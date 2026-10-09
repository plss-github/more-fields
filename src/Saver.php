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
use Session;

/**
 * Valida e grava os valores postados, aplicando as regras de visibilidade
 * no servidor: campo oculto ou somente leitura nunca é gravado.
 */
final class Saver
{
    /**
     * @param array $posted container_id => def_id => valor bruto
     *
     * @return array{errors: string[], writes: array<int, array{type: string, values: array}>}
     */
    public static function prepare(CommonDBTM $item, array $posted, array $input = []): array
    {
        $errors = [];
        $writes = [];

        $entity     = $item->isEntityAssign() ? (int) ($input['entities_id'] ?? $item->fields['entities_id'] ?? ($_SESSION['glpiactive_entity'] ?? 0)) : null;
        $containers = Binding::getContainers($item::class, null, $entity);
        $ctx        = Context::build($item, $input, $posted);

        foreach ($posted as $cid => $values) {
            $cid = (int) $cid;
            if (!isset($containers[$cid]) || !is_array($values)) {
                continue;
            }
            $rules = VisibilityRule::loadForContainer($cid);
            if (!RuleEngine::state(false, false, $rules[0] ?? [], $ctx)['visible']) {
                continue;
            }
            foreach (Binding::getContainerFields($cid, null, null, null, $item::class) as $field) {
                // Um bloco pode estar dividido em várias seções (principal, abas):
                // só tratamos os campos que estavam no formulário enviado.
                if (!array_key_exists($field['def_id'], $values)) {
                    continue;
                }
                $state = RuleEngine::state($field['is_required'], $field['is_readonly'], RuleEngine::fieldRules($rules[$field['cf_id']] ?? [], $rules[0] ?? []), $ctx);
                if (!$state['visible'] || $state['readonly']) {
                    continue;
                }
                $normalized = FieldType::normalize($field['type'], $values[$field['def_id']] ?? []);
                if ($state['required'] && $normalized === []) {
                    $errors[] = sprintf(__('O campo "%s" é obrigatório.', 'morefields'), $field['label']);
                }
                $writes[$field['def_id']] = ['type' => $field['type'], 'values' => $normalized];
            }
        }

        return ['errors' => array_values(array_unique($errors)), 'writes' => $writes];
    }

    /**
     * Obrigatórios do formulário principal para uma criação que não passou pelo
     * formulário (API, importação): trata todo campo do formulário principal
     * como "enviado vazio". Campos de aba não entram: não existem na criação.
     *
     * @return string[]
     */
    /**
     * Respostas de um Formulário do GLPI (campo_id => resposta bruta) viram gravações normalizadas.
     * É uma escolha explícita do administrador (pergunta ligada ao campo), então não passa pelas
     * regras de visibilidade/somente leitura: só vale o que o campo aceita para este tipo de item.
     *
     * @return array<int, array{type: string, values: array}>
     */
    public static function prepareAnswers(CommonDBTM $item, array $answers): array
    {
        global $DB;

        $writes = [];
        foreach ($answers as $def_id => $raw) {
            $def_id = (int) $def_id;
            $def    = $DB->request(['FROM' => FieldDefinition::getTable(), 'WHERE' => ['id' => $def_id, 'is_active' => 1], 'LIMIT' => 1])->current();
            if ($def === null || !Binding::fieldAppliesTo($def_id, $item::class)) {
                continue;
            }
            $values = FieldType::normalize($def['type'], $raw);
            if ($values !== []) {
                $writes[$def_id] = ['type' => $def['type'], 'values' => $values];
            }
        }

        return $writes;
    }

    /**
     * @param array<int, array{type: string, values: array}> $provided valores já fornecidos por outro meio (ex.: respostas de formulário)
     */
    public static function requiredErrorsWithoutForm(CommonDBTM $item, array $input, array $provided = []): array
    {
        $entity  = $item->isEntityAssign() ? (int) ($input['entities_id'] ?? ($_SESSION['glpiactive_entity'] ?? 0)) : null;
        $assumed = [];
        foreach (Binding::getContainers($item::class, ContainerField::PLACEMENT_DOM, $entity) as $cid => $container) {
            $assumed[$cid] = ['__present' => 1];
            foreach (Binding::getContainerFields($cid, ContainerField::PLACEMENT_DOM, null, $container, $item::class) as $field) {
                $assumed[$cid][$field['def_id']] = $provided[$field['def_id']]['values'] ?? '';
            }
        }

        return self::prepare($item, $assumed, $input)['errors'];
    }

    /**
     * @param array<int, array{type: string, values: array}> $writes
     *
     * @return bool true se algum valor mudou (e então date_mod do item é atualizado)
     */
    public static function apply(CommonDBTM $item, array $writes): bool
    {
        global $DB;

        $changed = false;
        foreach ($writes as $def_id => $write) {
            $changed = ValueRepository::replace($item::class, (int) $item->getID(), (int) $def_id, $write['type'], $write['values']) || $changed;
        }
        if ($changed && !$item->isNewItem() && array_key_exists('date_mod', $item->fields)) {
            $DB->update($item::getTable(), ['date_mod' => $_SESSION['glpi_currenttime']], ['id' => $item->getID()]);
        }

        return $changed;
    }

    public static function reportErrors(array $errors): void
    {
        foreach ($errors as $error) {
            Session::addMessageAfterRedirect(htmlescape($error), false, ERROR);
        }
    }
}
