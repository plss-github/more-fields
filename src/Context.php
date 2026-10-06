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
 * Contexto de avaliação das regras para um item (salvo ou em criação).
 */
final class Context
{
    /**
     * @param array $input  entrada do formulário (sobrepõe os campos gravados)
     * @param array $posted $_POST['_morefields']: container_id => def_id => valor bruto
     */
    public static function build(CommonDBTM $item, array $input = [], array $posted = []): array
    {
        $get = static fn(string $key) => $input[$key] ?? $item->fields[$key] ?? '';

        $entity = $item->isEntityAssign()
            ? ($get('entities_id') !== '' ? $get('entities_id') : ($_SESSION['glpiactive_entity'] ?? 0))
            : ($_SESSION['glpiactive_entity'] ?? 0);

        $fields = [];
        if (!$item->isNewItem()) {
            foreach (ValueRepository::load($item::class, (int) $item->getID()) as $fid => $values) {
                $fields[$fid] = array_map('strval', $values);
            }
        }
        $types = ValueRepository::definitionTypes();
        foreach ($posted as $values) {
            foreach ((array) $values as $fid => $raw) {
                if (isset($types[(int) $fid])) {
                    $fields[(int) $fid] = array_map('strval', FieldType::normalize($types[(int) $fid], $raw));
                }
            }
        }

        return [
            'profile'      => (string) ($_SESSION['glpiactiveprofile']['id'] ?? ''),
            'entity'       => (string) $entity,
            'itilcategory' => (string) $get('itilcategories_id'),
            'status'       => (string) $get('status'),
            'type'         => (string) $get('type'),
            'field'        => $fields,
        ];
    }
}
