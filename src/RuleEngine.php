<?php

/**
 * -------------------------------------------------------------------------
 * More Fields plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * @copyright Copyright (C) 2026 Matheus Schmidt
 * @license   GPLv2+ https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version. See the LICENSE file.
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Morefields;

/**
 * Avaliação das regras de visibilidade. A mesma estrutura compilada é
 * avaliada no servidor (autoridade na gravação) e em public/js/morefields.js
 * (reavaliação ao vivo no formulário).
 *
 * Regra compilada: ['match' => 'AND'|'OR', 'conds' => [cond...]]
 * Condição:        ['c' => critério, 'fid' => ?int, 'op' => in|not_in|empty|not_empty, 'vals' => string[]]
 */
final class RuleEngine
{
    /** Critérios resolvidos no servidor: nunca mudam durante a edição. */
    public const STATIC_CRITERIA = ['profile', 'entity'];

    public static function compile(array $rule): array
    {
        $conds = [];
        foreach (json_decode((string) ($rule['conditions'] ?? ''), true) ?: [] as $c) {
            $criterion = (string) ($c['c'] ?? '');
            $vals      = array_map('strval', (array) ($c['vals'] ?? []));
            if (!empty($c['sons'])) {
                $table = match ($criterion) {
                    'itilcategory' => 'glpi_itilcategories',
                    'entity'       => 'glpi_entities',
                    default        => null,
                };
                if ($table !== null) {
                    $expanded = [];
                    foreach ($vals as $id) {
                        $expanded += array_map('strval', getSonsOf($table, (int) $id));
                    }
                    $vals = array_values($expanded);
                }
            }
            $conds[] = [
                'c'    => $criterion,
                'fid'  => $criterion === 'field' ? (int) ($c['fid'] ?? 0) : null,
                'op'   => (string) ($c['op'] ?? 'in'),
                'vals' => $vals,
            ];
        }

        return ['match' => ($rule['match_mode'] ?? 'AND') === 'OR' ? 'OR' : 'AND', 'conds' => $conds];
    }

    /** @param string[] $current valores atuais do critério */
    private static function evalCondition(array $cond, array $current): bool
    {
        $current = array_values(array_filter(array_map('strval', $current), static fn($v) => $v !== ''));

        return match ($cond['op']) {
            'empty'     => $current === [],
            'not_empty' => $current !== [],
            'not_in'    => array_intersect($current, $cond['vals']) === [],
            default     => array_intersect($current, $cond['vals']) !== [],
        };
    }

    private static function currentFor(array $cond, array $ctx): array
    {
        return match ($cond['c']) {
            'profile'      => [$ctx['profile'] ?? ''],
            'entity'       => [$ctx['entity'] ?? ''],
            'itilcategory' => [$ctx['itilcategory'] ?? ''],
            'status'       => [$ctx['status'] ?? ''],
            'type'         => [$ctx['type'] ?? ''],
            'field'        => (array) ($ctx['field'][$cond['fid']] ?? []),
            default        => [],
        };
    }

    public static function matches(array $compiled, array $ctx): bool
    {
        // Regra cujas condições estáticas já não podem mais ser verdadeiras.
        if (!empty($compiled['never'])) {
            return false;
        }
        if ($compiled['conds'] === []) {
            return true;
        }
        foreach ($compiled['conds'] as $cond) {
            $ok = self::evalCondition($cond, self::currentFor($cond, $ctx));
            if ($compiled['match'] === 'OR' && $ok) {
                return true;
            }
            if ($compiled['match'] === 'AND' && !$ok) {
                return false;
            }
        }

        return $compiled['match'] === 'AND';
    }

    /**
     * Resolve as condições estáticas (perfil, entidade).
     * Retorna null se a regra nunca vale; senão a regra só com as dinâmicas
     * (sem condições = vale sempre).
     */
    public static function partial(array $compiled, array $ctx): ?array
    {
        $dynamic = [];
        foreach ($compiled['conds'] as $cond) {
            if (!in_array($cond['c'], self::STATIC_CRITERIA, true)) {
                $dynamic[] = $cond;
                continue;
            }
            $ok = self::evalCondition($cond, self::currentFor($cond, $ctx));
            if ($compiled['match'] === 'OR') {
                if ($ok) {
                    return ['match' => 'OR', 'conds' => []];
                }
            } elseif (!$ok) {
                return null;
            }
        }
        if ($dynamic === [] && $compiled['conds'] !== [] && $compiled['match'] === 'OR') {
            return null;
        }

        return ['match' => $compiled['match'], 'conds' => $dynamic];
    }

    /**
     * Regras que valem para um campo: as dele mais as do bloco inteiro que
     * mudam o próprio campo (somente leitura e obrigatório). Mostrar/ocultar do
     * bloco continuam sendo decididos pelo bloco.
     *
     * @param array<int, array> $field_rules
     * @param array<int, array> $container_rules
     */
    public static function fieldRules(array $field_rules, array $container_rules): array
    {
        $inherited = array_filter(
            $container_rules,
            static fn(array $rule) => in_array($rule['action'], ['readonly', 'required'], true),
        );

        return array_values(array_merge($field_rules, $inherited));
    }

    /**
     * @param array<int, array> $rules regras do alvo: ['action' => ..., 'compiled' => ...]
     *
     * @return array{visible: bool, required: bool, readonly: bool}
     */
    public static function state(bool $base_required, bool $base_readonly, array $rules, array $ctx): array
    {
        $has_show = false;
        $show     = false;
        $hide     = false;
        $required = $base_required;
        $readonly = $base_readonly;

        foreach ($rules as $rule) {
            $hit = self::matches($rule['compiled'], $ctx);
            switch ($rule['action']) {
                case 'show':
                    $has_show = true;
                    $show     = $show || $hit;
                    break;
                case 'hide':
                    $hide = $hide || $hit;
                    break;
                case 'required':
                    $required = $required || $hit;
                    break;
                case 'readonly':
                    $readonly = $readonly || $hit;
                    break;
            }
        }

        return [
            'visible'  => ($has_show ? $show : true) && !$hide,
            'required' => $required,
            'readonly' => $readonly,
        ];
    }
}
