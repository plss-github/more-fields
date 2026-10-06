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
 * API pública para outros plugins (ex.: assetprefixes) lerem e gravarem
 * valores de campos do More Fields sem conhecer as tabelas internas.
 *
 * Uso defensivo no outro plugin: class_exists(\GlpiPlugin\Morefields\Api::class).
 */
final class Api
{
    /** Tipos que aceitam receber uma string gerada por outro plugin. */
    public const TEXT_TYPES = [FieldType::TEXT, FieldType::TEXTAREA];

    /**
     * Campos de texto ativos que valem para o itemtype (em algum bloco ativo).
     *
     * @return array<int, string> id da definição => rótulo
     */
    public static function getTextFields(string $itemtype): array
    {
        global $DB;

        $result = [];
        foreach ($DB->request([
            'SELECT'     => ['d.id', 'd.label'],
            'DISTINCT'   => true,
            'FROM'       => FieldDefinition::getTable() . ' AS d',
            'INNER JOIN' => [
                'glpi_plugin_morefields_containerfields AS cf' => ['ON' => ['cf' => 'plugin_morefields_fielddefinitions_id', 'd' => 'id']],
                'glpi_plugin_morefields_containers AS c'       => ['ON' => ['cf' => 'plugin_morefields_containers_id', 'c' => 'id']],
                'glpi_plugin_morefields_containerfielditemtypes AS i' => ['ON' => ['i' => 'plugin_morefields_containerfields_id', 'cf' => 'id']],
            ],
            'WHERE'      => ['d.is_active' => 1, 'c.is_active' => 1, 'i.itemtype' => $itemtype, 'd.type' => self::TEXT_TYPES],
            'ORDER'      => 'd.label',
        ]) as $row) {
            $result[(int) $row['id']] = (string) $row['label'];
        }

        return $result;
    }

    /** Rótulo de uma definição, ou null se ela não existe mais. */
    public static function describe(int $field_id): ?string
    {
        global $DB;

        $row = $DB->request(['SELECT' => ['label'], 'FROM' => FieldDefinition::getTable(), 'WHERE' => ['id' => $field_id], 'LIMIT' => 1])->current();

        return $row === null ? null : (string) $row['label'];
    }

    /**
     * Grava um valor de texto num item. Valida o campo (existe, ativo, tipo
     * texto, vale para o itemtype) e normaliza o valor.
     *
     * @return string|null null em caso de sucesso; senão o motivo da falha
     */
    public static function setValue(string $itemtype, int $items_id, int $field_id, string $value): ?string
    {
        global $DB;

        $def = $DB->request(['FROM' => FieldDefinition::getTable(), 'WHERE' => ['id' => $field_id], 'LIMIT' => 1])->current();
        if ($def === null) {
            return "campo #$field_id do More Fields não existe (foi removido?)";
        }
        if (!$def['is_active']) {
            return "campo \"{$def['label']}\" do More Fields está inativo";
        }
        if (!in_array($def['type'], self::TEXT_TYPES, true)) {
            return "campo \"{$def['label']}\" é do tipo \"{$def['type']}\", incompatível (use texto/texto longo)";
        }
        if (!isset(self::getTextFields($itemtype)[$field_id])) {
            return "campo \"{$def['label']}\" não está configurado para $itemtype em nenhum bloco ativo";
        }

        $normalized = FieldType::normalize($def['type'], $value);
        if ($normalized === []) {
            return "valor vazio ou inválido para o campo \"{$def['label']}\"";
        }

        ValueRepository::replace($itemtype, $items_id, $field_id, $def['type'], $normalized);

        return null;
    }
}
