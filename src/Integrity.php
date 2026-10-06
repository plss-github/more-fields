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
 * Verificação e correção de integridade: sobras de exclusões, valores
 * duplicados (concorrência ou importação por SQL) e valores que apontam para
 * itens ou opções que não existem mais.
 */
final class Integrity
{
    private const V  = 'glpi_plugin_morefields_values';
    private const D  = 'glpi_plugin_morefields_fielddefinitions';
    private const C  = 'glpi_plugin_morefields_containers';
    private const CF = 'glpi_plugin_morefields_containerfields';
    private const CI = 'glpi_plugin_morefields_containerfielditemtypes';
    private const R  = 'glpi_plugin_morefields_visibilityrules';
    private const CH = 'glpi_plugin_morefields_choices';
    private const CL = 'glpi_plugin_morefields_choicelists';

    /**
     * Cada problema: ['label' => ..., 'count' => n, 'fix' => closure que apaga e devolve o nº de linhas]
     *
     * @return array<string, array{label: string, count: int, fix: callable}>
     */
    public static function problems(): array
    {
        global $DB;

        $count = static fn(string $sql): int => (int) ($DB->doQuery($sql)->fetch_row()[0] ?? 0);
        $exec  = static function (string $sql) use ($DB): int {
            $DB->doQuery($sql);

            return (int) $DB->affectedRows();
        };
        $multi = "'" . FieldType::MULTICHOICE . "'";

        $problems = [
            'orphan_values_field' => [
                'alias' => 'v',
                'label' => __('Valores de campos que não existem mais', 'morefields'),
                'sql'   => 'FROM ' . self::V . ' v LEFT JOIN ' . self::D . ' d ON d.id = v.plugin_morefields_fielddefinitions_id WHERE d.id IS NULL',
                'del'   => 'DELETE v FROM ' . self::V . ' v LEFT JOIN ' . self::D . ' d ON d.id = v.plugin_morefields_fielddefinitions_id WHERE d.id IS NULL',
            ],
            'orphan_cf' => [
                'alias' => 'cf',
                'label' => __('Campos ligados a bloco ou a definição inexistente', 'morefields'),
                'sql'   => 'FROM ' . self::CF . ' cf LEFT JOIN ' . self::C . ' c ON c.id = cf.plugin_morefields_containers_id LEFT JOIN ' . self::D . ' d ON d.id = cf.plugin_morefields_fielddefinitions_id WHERE c.id IS NULL OR d.id IS NULL',
                'del'   => 'DELETE cf FROM ' . self::CF . ' cf LEFT JOIN ' . self::C . ' c ON c.id = cf.plugin_morefields_containers_id LEFT JOIN ' . self::D . ' d ON d.id = cf.plugin_morefields_fielddefinitions_id WHERE c.id IS NULL OR d.id IS NULL',
            ],
            'orphan_itemtypes' => [
                'alias' => 'i',
                'label' => __('Itens de campos que não existem mais', 'morefields'),
                'sql'   => 'FROM ' . self::CI . ' i LEFT JOIN ' . self::CF . ' cf ON cf.id = i.plugin_morefields_containerfields_id WHERE cf.id IS NULL',
                'del'   => 'DELETE i FROM ' . self::CI . ' i LEFT JOIN ' . self::CF . ' cf ON cf.id = i.plugin_morefields_containerfields_id WHERE cf.id IS NULL',
            ],
            'orphan_rules' => [
                'alias' => 'r',
                'label' => __('Regras sem bloco (ou apontando para campo removido)', 'morefields'),
                'sql'   => 'FROM ' . self::R . ' r LEFT JOIN ' . self::C . ' c ON c.id = r.plugin_morefields_containers_id LEFT JOIN ' . self::CF . ' cf ON cf.id = r.plugin_morefields_containerfields_id WHERE c.id IS NULL OR (r.plugin_morefields_containerfields_id > 0 AND cf.id IS NULL)',
                'del'   => 'DELETE r FROM ' . self::R . ' r LEFT JOIN ' . self::C . ' c ON c.id = r.plugin_morefields_containers_id LEFT JOIN ' . self::CF . ' cf ON cf.id = r.plugin_morefields_containerfields_id WHERE c.id IS NULL OR (r.plugin_morefields_containerfields_id > 0 AND cf.id IS NULL)',
            ],
            'orphan_choices' => [
                'alias' => 'ch',
                'label' => __('Opções de listas que não existem mais', 'morefields'),
                'sql'   => 'FROM ' . self::CH . ' ch LEFT JOIN ' . self::CL . ' l ON l.id = ch.plugin_morefields_choicelists_id WHERE l.id IS NULL',
                'del'   => 'DELETE ch FROM ' . self::CH . ' ch LEFT JOIN ' . self::CL . ' l ON l.id = ch.plugin_morefields_choicelists_id WHERE l.id IS NULL',
            ],
            // Campo de valor único com mais de uma linha: mantém a mais recente.
            'duplicates' => [
                'alias' => 'v',
                'label' => __('Valores duplicados em campos de valor único', 'morefields'),
                'sql'   => 'FROM ' . self::V . ' v JOIN ' . self::D . " d ON d.id = v.plugin_morefields_fielddefinitions_id AND d.type <> $multi"
                    . ' JOIN ' . self::V . ' n ON n.itemtype = v.itemtype AND n.items_id = v.items_id AND n.plugin_morefields_fielddefinitions_id = v.plugin_morefields_fielddefinitions_id AND n.id > v.id',
                'del'   => 'DELETE v FROM ' . self::V . ' v JOIN ' . self::D . " d ON d.id = v.plugin_morefields_fielddefinitions_id AND d.type <> $multi"
                    . ' JOIN ' . self::V . ' n ON n.itemtype = v.itemtype AND n.items_id = v.items_id AND n.plugin_morefields_fielddefinitions_id = v.plugin_morefields_fielddefinitions_id AND n.id > v.id',
            ],
        ];

        $result = [];
        foreach ($problems as $key => $p) {
            $result[$key] = [
                'label' => $p['label'],
                'count' => $count('SELECT COUNT(DISTINCT ' . $p['alias'] . '.id) ' . $p['sql']),
                'fix'   => static fn(): int => $exec($p['del']),
            ];
        }

        // Valores de itens que não existem mais, por tipo de item.
        $orphan_items = [];
        foreach ($DB->request(['SELECT' => ['itemtype'], 'DISTINCT' => true, 'FROM' => self::V]) as $row) {
            $itemtype = $row['itemtype'];
            $table    = class_exists($itemtype) ? getTableForItemType($itemtype) : '';
            if ($table === '' || !$DB->tableExists($table)) {
                continue;
            }
            $q = $DB->quote($itemtype);
            $orphan_items[$itemtype] = [
                $count("SELECT COUNT(*) FROM " . self::V . " v LEFT JOIN `$table` t ON t.id = v.items_id WHERE v.itemtype = $q AND t.id IS NULL"),
                "DELETE v FROM " . self::V . " v LEFT JOIN `$table` t ON t.id = v.items_id WHERE v.itemtype = $q AND t.id IS NULL",
            ];
        }
        $result['orphan_items'] = [
            'label' => __('Valores de itens (ativos, chamados...) que não existem mais', 'morefields'),
            'count' => array_sum(array_column($orphan_items, 0)),
            'fix'   => static function () use ($orphan_items, $exec): int {
                return array_sum(array_map(static fn(array $o): int => $o[0] > 0 ? $exec($o[1]) : 0, $orphan_items));
            },
        ];

        // Campos de lista com valor que não é uma opção da lista do campo.
        $dangling = [];
        foreach ($DB->request(['FROM' => self::D, 'WHERE' => ['type' => [FieldType::CHOICE, FieldType::MULTICHOICE]]]) as $def) {
            $list = (int) (json_decode((string) $def['config'], true)['choicelist'] ?? 0);
            $where = 'plugin_morefields_fielddefinitions_id = ' . (int) $def['id']
                . ' AND (v_int IS NULL OR v_int NOT IN (SELECT id FROM ' . self::CH . ' WHERE plugin_morefields_choicelists_id = ' . $list . '))';
            $dangling[] = [$count('SELECT COUNT(*) FROM ' . self::V . ' WHERE ' . $where), 'DELETE FROM ' . self::V . ' WHERE ' . $where];
        }
        $result['dangling_choices'] = [
            'label' => __('Valores de campos de lista que apontam para opção inexistente', 'morefields'),
            'count' => array_sum(array_column($dangling, 0)),
            'fix'   => static fn(): int => array_sum(array_map(static fn(array $d): int => $d[0] > 0 ? $exec($d[1]) : 0, $dangling)),
        ];

        return $result;
    }

    /** Corrige tudo; devolve quantas linhas foram removidas por problema. */
    public static function fix(): array
    {
        $removed = [];
        foreach (self::problems() as $key => $problem) {
            if ($problem['count'] > 0) {
                $removed[$key] = ($problem['fix'])();
            }
        }

        return $removed;
    }
}
