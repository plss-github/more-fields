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
 * Consultas "quais blocos se aplicam a este item".
 */
final class Binding
{
    private const TABLE_ITEMTYPES = 'glpi_plugin_morefields_containerfielditemtypes';
    private const TABLE_CONTAINERS = 'glpi_plugin_morefields_containers';
    private const TABLE_FIELDS = 'glpi_plugin_morefields_containerfields';

    /** @return string[] */
    public static function getBoundItemtypes(?string $placement = null): array
    {
        global $DB;
        static $cache = [];

        $key = $placement ?? '*';
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $cache[$key] = [];

        try {
            if (!$DB->tableExists(self::TABLE_ITEMTYPES)) {
                return [];
            }
            $where = ['c.is_active' => 1];
            if ($placement !== null) {
                $where['cf.placement'] = $placement;
            }
            $rows = $DB->request([
                'SELECT'     => ['i.itemtype'],
                'DISTINCT'   => true,
                'FROM'       => self::TABLE_ITEMTYPES . ' AS i',
                'INNER JOIN' => [
                    self::TABLE_FIELDS . ' AS cf' => [
                        'ON' => ['cf' => 'id', 'i' => 'plugin_morefields_containerfields_id'],
                    ],
                    self::TABLE_CONTAINERS . ' AS c' => [
                        'ON' => ['c' => 'id', 'cf' => 'plugin_morefields_containers_id'],
                    ],
                ],
                'WHERE'      => $where,
            ]);
            // Sem class_exists(): classes de ativos personalizados só existem
            // depois do init do plugin. O itemtype já foi validado ao cadastrar.
            foreach ($rows as $row) {
                $cache[$key][] = $row['itemtype'];
            }
        } catch (\Throwable) {
            // banco indisponível durante install/upgrade: sem hooks
        }

        return $cache[$key];
    }

    /**
     * Blocos ativos de um itemtype visíveis na entidade informada. Com
     * `$placement`, só os que têm ao menos um campo naquele local.
     *
     * @return array<int, array> id => linha da tabela de blocos
     */
    public static function getContainers(string $itemtype, ?string $placement, ?int $entity = null): array
    {
        global $DB;

        $where = ['i.itemtype' => $itemtype, 'c.is_active' => 1];
        $joins = [
            self::TABLE_FIELDS . ' AS cf' => ['ON' => ['cf' => 'plugin_morefields_containers_id', 'c' => 'id']],
            self::TABLE_ITEMTYPES . ' AS i' => ['ON' => ['i' => 'plugin_morefields_containerfields_id', 'cf' => 'id']],
        ];
        if ($placement !== null) {
            $where['cf.placement'] = $placement;
        }
        $rows = $DB->request([
            'SELECT'     => ['c.*'],
            'DISTINCT'   => true,
            'FROM'       => self::TABLE_CONTAINERS . ' AS c',
            'INNER JOIN' => $joins,
            'WHERE'      => $where,
            'ORDER'      => 'c.name',
        ]);

        $ancestors = $entity === null ? null : (getAncestorsOf('glpi_entities', $entity) + [$entity => $entity]);
        $result    = [];
        foreach ($rows as $row) {
            if ($ancestors !== null) {
                $visible = $row['is_recursive']
                    ? isset($ancestors[$row['entities_id']])
                    : (int) $row['entities_id'] === $entity;
                if (!$visible) {
                    continue;
                }
            }
            $result[(int) $row['id']] = $row;
        }

        return $result;
    }

    /**
     * Rótulo da aba de um campo: o próprio, ou o nome do bloco se vazio.
     */
    public static function tabLabel(array $field, array $container): string
    {
        $label = trim((string) ($field['tab_label'] ?? ''));

        return $label !== '' ? $label : (string) $container['name'];
    }

    /**
     * Campos de um bloco, na ordem, já com a definição global. Com
     * `$placement` filtra pelo local; para abas, `$tab_label` filtra a aba;
     * `$itemtype` deixa só os campos que valem para aquele tipo de item.
     *
     * @return array<int, array> containerfields_id => dados do campo
     */
    public static function getContainerFields(int $container_id, ?string $placement = null, ?string $tab_label = null, ?array $container = null, ?string $itemtype = null): array
    {
        global $DB;

        $rows = $DB->request([
            'SELECT'     => [
                'cf.id AS cf_id', 'cf.ranking', 'cf.placement', 'cf.tab_label', 'cf.is_required', 'cf.is_readonly',
                'd.id AS def_id', 'd.name', 'd.label', 'd.type', 'd.config', 'd.is_active',
            ],
            'FROM'       => 'glpi_plugin_morefields_containerfields AS cf',
            'INNER JOIN' => [
                'glpi_plugin_morefields_fielddefinitions AS d' => [
                    'ON' => ['cf' => 'plugin_morefields_fielddefinitions_id', 'd' => 'id'],
                ],
            ],
            'WHERE'      => ['cf.plugin_morefields_containers_id' => $container_id, 'd.is_active' => 1],
            'ORDER'      => ['cf.ranking', 'cf.id'],
        ]);

        $types = self::getFieldItemtypes($container_id);

        $result = [];
        foreach ($rows as $row) {
            if ($itemtype !== null && !in_array($itemtype, $types[(int) $row['cf_id']] ?? [], true)) {
                continue;
            }
            if ($placement !== null && $row['placement'] !== $placement) {
                continue;
            }
            if ($tab_label !== null && self::tabLabel($row, $container ?? ['name' => '']) !== $tab_label) {
                continue;
            }
            $result[(int) $row['cf_id']] = [
                'cf_id'       => (int) $row['cf_id'],
                'placement'   => $row['placement'],
                'itemtypes'   => $types[(int) $row['cf_id']] ?? [],
                'tab_label'   => $row['tab_label'],
                'def_id'      => (int) $row['def_id'],
                'ranking'     => (int) $row['ranking'],
                'is_required' => (bool) $row['is_required'],
                'is_readonly' => (bool) $row['is_readonly'],
                'name'        => $row['name'],
                'label'       => $row['label'],
                'type'        => $row['type'],
                'config'      => json_decode((string) $row['config'], true) ?: [],
            ];
        }

        return $result;
    }

    /** O campo está em algum bloco ativo que vale para o tipo de item? */
    public static function fieldAppliesTo(int $field_id, string $itemtype): bool
    {
        global $DB;

        return (bool) $DB->request([
            'SELECT'     => ['cf.id'],
            'FROM'       => self::TABLE_FIELDS . ' AS cf',
            'INNER JOIN' => [
                self::TABLE_CONTAINERS . ' AS c' => ['ON' => ['cf' => 'plugin_morefields_containers_id', 'c' => 'id']],
                self::TABLE_ITEMTYPES . ' AS i'  => ['ON' => ['i' => 'plugin_morefields_containerfields_id', 'cf' => 'id']],
            ],
            'WHERE'      => ['cf.plugin_morefields_fielddefinitions_id' => $field_id, 'c.is_active' => 1, 'i.itemtype' => $itemtype],
            'LIMIT'      => 1,
        ])->count();
    }

    /** @return array<int, string[]> containerfields_id => itemtypes */
    public static function getFieldItemtypes(int $container_id): array
    {
        global $DB;

        $result = [];
        foreach ($DB->request([
            'SELECT'     => ['i.plugin_morefields_containerfields_id AS cf_id', 'i.itemtype'],
            'FROM'       => self::TABLE_ITEMTYPES . ' AS i',
            'INNER JOIN' => [self::TABLE_FIELDS . ' AS cf' => ['ON' => ['cf' => 'id', 'i' => 'plugin_morefields_containerfields_id']]],
            'WHERE'      => ['cf.plugin_morefields_containers_id' => $container_id],
        ]) as $row) {
            $result[(int) $row['cf_id']][] = $row['itemtype'];
        }

        return $result;
    }
}
