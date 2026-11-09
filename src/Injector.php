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

/**
 * Pontos de integração com o GLPI (hooks de formulário e de gravação).
 */
final class Injector
{
    public static function postItemForm(array $params): void
    {
        static $done = [];

        $item = $params['item'] ?? null;
        if (!$item instanceof CommonDBTM || !in_array($item::class, Binding::getBoundItemtypes('dom'), true)) {
            return;
        }

        $entity = $item->isEntityAssign() ? (int) ($item->fields['entities_id'] ?? ($_SESSION['glpiactive_entity'] ?? 0)) : null;
        foreach (Binding::getContainers($item::class, ContainerField::PLACEMENT_DOM, $entity) as $cid => $container) {
            $key = $item::class . '#' . $item->getID() . '#' . $cid;
            if (isset($done[$key])) {
                continue;
            }
            $done[$key] = true;
            echo Renderer::section($container, $item, ContainerField::PLACEMENT_DOM);
        }
    }

    public static function preItemAdd(CommonDBTM $item): void
    {
        self::pre($item, true);
    }

    public static function preItemUpdate(CommonDBTM $item): void
    {
        self::pre($item, false);
    }

    private static function pre(CommonDBTM $item, bool $adding): void
    {
        // Respostas de um Formulário do GLPI (ver Form\DestinationField).
        $answers = $item->input['_morefields_answers'] ?? null;
        unset($item->input['_morefields_answers']);
        $answer_writes = is_array($answers) && $answers !== [] ? Saver::prepareAnswers($item, $answers) : [];

        $posted = $item->input['_morefields'] ?? null;
        if (!$adding && !is_array($posted)) {
            $posted = self::massiveActionPosted($item);
        }
        if (!is_array($posted)) {
            // Criação sem formulário (API, importação): só cobra os obrigatórios se o admin ligou.
            if (
                $adding
                && Settings::get('require_without_form')
                && empty($item->input['clone'])
                && !isset($item->input['_oldID'])
                && empty($item->input['is_template'])
            ) {
                $errors = Saver::requiredErrorsWithoutForm($item, $item->input, $answer_writes);
                if ($errors !== []) {
                    Saver::reportErrors($errors);
                    $item->input = [];

                    return;
                }
            }
            if ($answer_writes !== []) {
                if ($adding) {
                    $item->input['_morefields_writes'] = $answer_writes;
                } else {
                    Saver::apply($item, $answer_writes);
                }
            }

            return;
        }
        unset($item->input['_morefields']);

        $result = Saver::prepare($item, $posted, $item->input);
        if ($result['errors'] !== []) {
            Saver::reportErrors($result['errors']);
            $item->input = [];

            return;
        }

        if ($adding) {
            // sem ID ainda: grava em item_add (o que veio do formulário principal prevalece sobre as respostas)
            $item->input['_morefields_writes'] = $result['writes'] + $answer_writes;
        } else {
            // O core só dispara item_update se algum campo do próprio item mudou;
            // gravar aqui garante que editar só os campos extras funcione.
            Saver::apply($item, $result['writes']);
            if ($result['writes'] !== [] && array_key_exists('date_mod', $item->fields)) {
                // Sem nenhuma coluna própria alterada o update() devolve false (a ação massiva
                // contaria como falha): date_mod garante o retorno certo.
                $item->input['date_mod'] = $_SESSION['glpi_currenttime'];
            }
        }
    }

    /**
     * Ação massiva "Atualizar" sobre um campo do plugin: o GLPI chama update() com
     * `<coluna> => [id do campo => valor]` (ver FieldDefinition::massiveActionInput).
     *
     * @return array|null container_id => def_id => valor, no formato de `_morefields`
     */
    private static function massiveActionPosted(CommonDBTM $item): ?array
    {
        $raw = [];
        // linkfield das opções de busca: padrão do GLPI para a tabela de valores, ou v_int nas listas.
        foreach (['plugin_morefields_values_id', 'v_int'] as $column) {
            $value = $item->input[$column] ?? null;
            if (!is_array($value) || $value === []) {
                continue;
            }
            foreach ($value as $def_id => $v) {
                if (is_int($def_id) || ctype_digit((string) $def_id)) {
                    $raw[(int) $def_id] = $v;
                }
            }
            unset($item->input[$column]);
        }
        // "Item do GLPI": o dropdown padrão do GLPI não carrega o id do campo, que vem da opção
        // de busca escolhida na própria requisição da ação massiva.
        if (
            array_key_exists('v_items_id', $item->input)
            && ($_POST['action'] ?? '') === 'update'
            && ($_POST['field'] ?? '') === 'v_items_id'
        ) {
            $def_id = (int) ($_POST['search_options'][$item::class] ?? 0) - FieldDefinition::SEARCH_OPTION_BASE;
            if ($def_id > 0) {
                $raw[$def_id] = $item->input['v_items_id'];
                unset($item->input['v_items_id']);
            }
        }
        if ($raw === []) {
            return null;
        }

        $entity = $item->isEntityAssign() ? (int) ($item->fields['entities_id'] ?? 0) : null;
        $posted = [];
        foreach (Binding::getContainers($item::class, null, $entity) as $cid => $container) {
            foreach (Binding::getContainerFields((int) $cid, null, null, null, $item::class) as $field) {
                if (array_key_exists($field['def_id'], $raw)) {
                    $posted[(int) $cid][$field['def_id']] = $raw[$field['def_id']];
                }
            }
        }

        return $posted;
    }

    public static function itemAdd(CommonDBTM $item): void
    {
        // Clone e criação a partir de modelo passam por Clonable::clone(), que não tem
        // gancho para plugins: o item de origem é o objeto do quadro "clone" da pilha.
        if (!empty($item->input['clone'])) {
            $source = self::findCloneSource($item);
            if ($source !== null) {
                ValueRepository::copyItem($source::class, (int) $source->getID(), $item::class, (int) $item->getID());
            }
        }

        Saver::apply($item, $item->input['_morefields_writes'] ?? []);
    }

    private static function findCloneSource(CommonDBTM $item): ?CommonDBTM
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 30) as $frame) {
            $object = $frame['object'] ?? null;
            if (
                ($frame['function'] ?? '') === 'clone'
                && $object instanceof CommonDBTM
                && $object::class === $item::class
                && !$object->isNewItem()
                && (int) $object->getID() !== (int) $item->getID()
            ) {
                return $object;
            }
        }

        return null;
    }

    /** Transferência de entidade com cópia: o item novo recebe os valores do original. */
    public static function itemTransfer(array $params): void
    {
        $id     = (int) ($params['id'] ?? 0);
        $new_id = (int) ($params['newID'] ?? 0);
        $type   = (string) ($params['type'] ?? '');
        if ($type !== '' && $new_id > 0 && $new_id !== $id && in_array($type, Binding::getBoundItemtypes(), true)) {
            ValueRepository::copyItem($type, $id, $type, $new_id);
        }
    }

    public static function itemPurge(CommonDBTM $item): void
    {
        ValueRepository::purgeItem($item::class, (int) $item->getID());
    }
}
