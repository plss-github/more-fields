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

use CommonDBTM;
use Html;
use Session;

/**
 * Monta o HTML de um bloco para um item. Regras estáticas (perfil, entidade)
 * são resolvidas aqui: o que não pode aparecer nem chega ao navegador.
 * As dinâmicas (categoria, status, valor de outro campo) vão em
 * data-mf-config para o JS reavaliar ao vivo.
 */
final class Renderer
{
    /**
     * Regras do bloco com as condições estáticas já resolvidas.
     *
     * @return array{0: array, 1: array<int, array>} [regras do bloco, regras por campo (cf_id)]
     */
    private static function resolveRules(int $cid, array $ctx): array
    {
        $by_target = [];
        foreach (VisibilityRule::loadForContainer($cid) as $target => $rules) {
            foreach ($rules as $rule) {
                $partial = RuleEngine::partial($rule['compiled'], $ctx);
                if ($partial !== null) {
                    $by_target[$target][] = ['action' => $rule['action'], 'compiled' => $partial];
                } elseif ($rule['action'] === 'show') {
                    // "Mostrar somente se" que nunca vale para este usuário precisa
                    // continuar existindo (como "nunca casa"): sem ela o campo viraria
                    // sempre visível. Para as demais ações, uma regra que nunca casa é inócua.
                    $by_target[$target][] = ['action' => 'show', 'compiled' => ['match' => 'OR', 'conds' => [], 'never' => true]];
                }
            }
        }
        $container_rules = $by_target[0] ?? [];
        unset($by_target[0]);

        return [$container_rules, $by_target];
    }

    /** Há alguma regra do alvo que ainda pode mudar no navegador? */
    private static function hasDynamic(array $rules): bool
    {
        foreach ($rules as $rule) {
            if ($rule['compiled']['conds'] !== []) {
                return true;
            }
        }

        return false;
    }

    /** O bloco inteiro está oculto de forma definitiva para este usuário/item? */
    public static function isOmitted(array $container, CommonDBTM $item): bool
    {
        $ctx = Context::build($item);
        [$crules] = self::resolveRules((int) $container['id'], $ctx);

        return !RuleEngine::state(false, false, $crules, $ctx)['visible'] && !self::hasDynamic($crules);
    }

    /**
     * Seção de um bloco: os campos com o local indicado (formulário principal
     * ou uma aba nomeada).
     */
    public static function section(array $container, CommonDBTM $item, string $placement, string $tab_label = ''): string
    {
        $cid    = (int) $container['id'];
        $ctx    = Context::build($item);
        $fields = Binding::getContainerFields($cid, $placement, $placement === ContainerField::PLACEMENT_TAB ? $tab_label : null, $container, $item::class);
        [$crules, $frules] = self::resolveRules($cid, $ctx);

        $cstate = RuleEngine::state(false, false, $crules, $ctx);
        if (!$cstate['visible'] && !self::hasDynamic($crules)) {
            return '';
        }

        $stored = $item->isNewItem() ? [] : ValueRepository::load($item::class, (int) $item->getID());
        $config = [
            'mode'      => $placement,
            'ctx'       => ['field' => []] + array_diff_key($ctx, ['field' => 1]),
            'container' => $crules,
            'fields'    => [],
        ];
        $rows = '';

        // Só vão para o navegador os valores dos campos citados em condições.
        $referenced = [];
        foreach (array_merge($crules, ...array_values($frules ?: [[]])) as $rule) {
            foreach ($rule['compiled']['conds'] as $cond) {
                if ($cond['c'] === 'field') {
                    $referenced[(int) $cond['fid']] = true;
                }
            }
        }
        $config['ctx']['field'] = (object) array_intersect_key($ctx['field'], $referenced);

        // Mesmo markup dos campos nativos do GLPI (grade de 2 colunas; no chamado, largura total).
        $field_class = $item instanceof \CommonITILObject ? 'col-12 glpi-full-width' : 'col-12 col-sm-6';

        foreach ($fields as $cf_id => $f) {
            $rules = RuleEngine::fieldRules($frules[$cf_id] ?? [], $crules);
            $state = RuleEngine::state($f['is_required'], $f['is_readonly'], $rules, $ctx);
            if (!$state['visible'] && !self::hasDynamic($rules)) {
                continue;
            }
            $config['fields'][$f['def_id']] = ['required' => $f['is_required'], 'rules' => $rules];

            $values     = $stored[$f['def_id']] ?? [];
            $always_ro  = $f['is_readonly'] || self::alwaysApplies($rules, 'readonly');
            $name       = "_morefields[$cid][{$f['def_id']}]";
            $input_html = $always_ro
                ? "<div class='form-control mf-readonly' title='" . __s('Somente leitura', 'morefields') . "'>" . (FieldType::formatValues($f, $values) ?: '&nbsp;') . '</div>'
                : FieldType::renderInput($f, $values, $name);
            $hidden     = $state['visible'] ? '' : " style='display:none'";
            $ro_attr    = $state['readonly'] ? " data-mf-readonly='1'" : '';
            $star       = $state['required'] ? "<span class='text-danger mf-star'> *</span>" : "<span class='text-danger mf-star' style='display:none'> *</span>";

            $rows .= "<div class='form-field row align-items-center {$field_class} mb-2 mf-field' data-mf-cid='$cid' data-mf-def='{$f['def_id']}'{$hidden}{$ro_attr}>"
                . "<label class='col-form-label col-xxl-5 text-xxl-end'>" . htmlescape($f['label']) . $star . '</label>'
                . "<div class='col-xxl-7 field-container mf-input'>" . $input_html . '</div></div>';
        }

        if ($rows === '') {
            return '';
        }

        // Sem cartão nem título: o bloco é só um agrupador, na tela aparecem só os campos.
        $hidden = $cstate['visible'] ? '' : " style='display:none'";
        $json   = htmlescape(json_encode($config));

        $html = "<div class='row mf-container' data-mf-container='$cid' data-mf-config='$json'{$hidden}>"
            . "<input type='hidden' name='_morefields[$cid][__present]' value='1'>" . $rows;

        if ($placement === ContainerField::PLACEMENT_TAB) {
            if ($item->canUpdateItem()) {
                $html .= "<div class='text-end mt-3'><button class='btn btn-primary' type='submit'>" . __s('Save') . '</button></div>';
            }
            $html .= '</div>';

            return "<form method='post' action='" . htmlescape(PLUGIN_MOREFIELDS_WEBDIR . '/front/values.php') . "' class='mf-tab-form p-3'>"
                . "<input type='hidden' name='itemtype' value='" . htmlescape($item::class) . "'>"
                . "<input type='hidden' name='items_id' value='" . (int) $item->getID() . "'>"
                . Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()])
                . $html . '</form>';
        }

        return $html . '</div>';
    }

    private static function alwaysApplies(array $rules, string $action): bool
    {
        foreach ($rules as $rule) {
            if ($rule['action'] === $action && $rule['compiled']['conds'] === [] && empty($rule['compiled']['never'])) {
                return true;
            }
        }

        return false;
    }
}
