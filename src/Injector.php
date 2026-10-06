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
        $posted = $item->input['_morefields'] ?? null;
        if (!is_array($posted)) {
            // Criação sem formulário (API, importação): só cobra os obrigatórios se o admin ligou.
            if (
                $adding
                && Settings::get('require_without_form')
                && empty($item->input['clone'])
                && !isset($item->input['_oldID'])
                && empty($item->input['is_template'])
            ) {
                $errors = Saver::requiredErrorsWithoutForm($item, $item->input);
                if ($errors !== []) {
                    Saver::reportErrors($errors);
                    $item->input = [];
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
            // sem ID ainda: grava em item_add
            $item->input['_morefields_writes'] = $result['writes'];
        } else {
            // O core só dispara item_update se algum campo do próprio item mudou;
            // gravar aqui garante que editar só os campos extras funcione.
            Saver::apply($item, $result['writes']);
        }
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
