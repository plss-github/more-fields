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

use GlpiPlugin\Morefields\FieldDefinition;
use GlpiPlugin\Morefields\FieldType;

function plugin_morefields_install()
{
    global $DB;

    $charset = DBConnection::getDefaultCharset();
    $collate = DBConnection::getDefaultCollation();
    $sign    = DBConnection::getDefaultPrimaryKeySignOption();

    $tables = [
        'glpi_plugin_morefields_fielddefinitions' => "
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL,
            `label` varchar(255) NOT NULL DEFAULT '',
            `type` varchar(30) NOT NULL DEFAULT 'text',
            `config` text DEFAULT NULL,
            `comment` text DEFAULT NULL,
            `is_active` tinyint NOT NULL DEFAULT 1,
            `copy_on_clone` tinyint NOT NULL DEFAULT 1,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `name` (`name`)",
        'glpi_plugin_morefields_choicelists' => "
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL DEFAULT '',
            `comment` text DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `name` (`name`)",
        'glpi_plugin_morefields_choices' => "
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `plugin_morefields_choicelists_id` int {$sign} NOT NULL DEFAULT 0,
            `name` varchar(255) NOT NULL DEFAULT '',
            `color` varchar(7) DEFAULT NULL,
            `ranking` int NOT NULL DEFAULT 0,
            `is_active` tinyint NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`),
            KEY `plugin_morefields_choicelists_id` (`plugin_morefields_choicelists_id`)",
        'glpi_plugin_morefields_containers' => "
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL DEFAULT '',
            `entities_id` int {$sign} NOT NULL DEFAULT 0,
            `is_recursive` tinyint NOT NULL DEFAULT 1,
            `is_active` tinyint NOT NULL DEFAULT 1,
            `comment` text DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `entities_id` (`entities_id`),
            KEY `is_active` (`is_active`)",
        'glpi_plugin_morefields_containerfields' => "
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `plugin_morefields_containers_id` int {$sign} NOT NULL DEFAULT 0,
            `plugin_morefields_fielddefinitions_id` int {$sign} NOT NULL DEFAULT 0,
            `ranking` int NOT NULL DEFAULT 0,
            `placement` varchar(10) NOT NULL DEFAULT 'tab',
            `tab_label` varchar(255) DEFAULT NULL,
            `is_required` tinyint NOT NULL DEFAULT 0,
            `is_readonly` tinyint NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `container_field` (`plugin_morefields_containers_id`, `plugin_morefields_fielddefinitions_id`),
            KEY `plugin_morefields_fielddefinitions_id` (`plugin_morefields_fielddefinitions_id`)",
        // 0.3.0: itens em que o campo vale (por campo do bloco, não por bloco)
        'glpi_plugin_morefields_containerfielditemtypes' => "
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `plugin_morefields_containerfields_id` int {$sign} NOT NULL DEFAULT 0,
            `itemtype` varchar(100) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `field_itemtype` (`plugin_morefields_containerfields_id`, `itemtype`),
            KEY `itemtype` (`itemtype`)",
        'glpi_plugin_morefields_visibilityrules' => "
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL DEFAULT '',
            `plugin_morefields_containers_id` int {$sign} NOT NULL DEFAULT 0,
            `plugin_morefields_containerfields_id` int {$sign} NOT NULL DEFAULT 0,
            `action` varchar(20) NOT NULL DEFAULT 'hide',
            `match_mode` varchar(3) NOT NULL DEFAULT 'AND',
            `conditions` text DEFAULT NULL,
            `is_active` tinyint NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`),
            KEY `plugin_morefields_containers_id` (`plugin_morefields_containers_id`),
            KEY `plugin_morefields_containerfields_id` (`plugin_morefields_containerfields_id`)",
        // Armazenamento único e tipado: sem DDL em runtime, sem classes geradas.
        'glpi_plugin_morefields_values' => "
            `id` bigint {$sign} NOT NULL AUTO_INCREMENT,
            `itemtype` varchar(100) NOT NULL,
            `items_id` int {$sign} NOT NULL DEFAULT 0,
            `plugin_morefields_fielddefinitions_id` int {$sign} NOT NULL DEFAULT 0,
            `v_string` varchar(255) DEFAULT NULL,
            `v_text` mediumtext DEFAULT NULL,
            `v_int` bigint DEFAULT NULL,
            `v_decimal` decimal(20,6) DEFAULT NULL,
            `v_date` date DEFAULT NULL,
            `v_datetime` timestamp NULL DEFAULT NULL,
            `v_items_id` int {$sign} DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `item_field` (`itemtype`, `items_id`, `plugin_morefields_fielddefinitions_id`),
            KEY `field_int` (`plugin_morefields_fielddefinitions_id`, `v_int`),
            KEY `field_string` (`plugin_morefields_fielddefinitions_id`, `v_string`),
            KEY `field_date` (`plugin_morefields_fielddefinitions_id`, `v_date`),
            KEY `field_item` (`plugin_morefields_fielddefinitions_id`, `v_items_id`)",
    ];

    $fresh_fielditemtypes = !$DB->tableExists('glpi_plugin_morefields_containerfielditemtypes');

    foreach ($tables as $table => $columns) {
        if (!$DB->tableExists($table)) {
            $DB->doQuery(
                "CREATE TABLE `{$table}` ({$columns})
                 ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collate} ROW_FORMAT=DYNAMIC"
            );
        }
    }

    // 0.2.0: o local de exibição passou do bloco para cada campo do bloco.
    $cf_table = 'glpi_plugin_morefields_containerfields';
    if (!$DB->fieldExists($cf_table, 'placement')) {
        $DB->doQuery("ALTER TABLE `{$cf_table}`
            ADD COLUMN `placement` varchar(10) NOT NULL DEFAULT 'tab' AFTER `ranking`,
            ADD COLUMN `tab_label` varchar(255) DEFAULT NULL AFTER `placement`");
        if ($DB->fieldExists('glpi_plugin_morefields_containers', 'display_type')) {
            $DB->doQuery("UPDATE `{$cf_table}` AS cf
                INNER JOIN `glpi_plugin_morefields_containers` AS c ON c.id = cf.plugin_morefields_containers_id
                SET cf.placement = c.display_type");
        }
    }

    // 0.4.1: por campo, copiar ou não o valor ao clonar / criar de modelo / transferir com cópia.
    if (!$DB->fieldExists('glpi_plugin_morefields_fielddefinitions', 'copy_on_clone')) {
        $DB->doQuery("ALTER TABLE `glpi_plugin_morefields_fielddefinitions`
            ADD COLUMN `copy_on_clone` tinyint NOT NULL DEFAULT 1 AFTER `is_active`");
    }

    // 0.3.0: cada campo herda os itemtypes do bloco onde já estava.
    if ($fresh_fielditemtypes && $DB->tableExists('glpi_plugin_morefields_containeritemtypes')) {
        $DB->doQuery("INSERT IGNORE INTO `glpi_plugin_morefields_containerfielditemtypes`
                (`plugin_morefields_containerfields_id`, `itemtype`)
            SELECT cf.id, ci.itemtype
            FROM `glpi_plugin_morefields_containerfields` AS cf
            INNER JOIN `glpi_plugin_morefields_containeritemtypes` AS ci
                ON ci.plugin_morefields_containers_id = cf.plugin_morefields_containers_id");
    }

    return true;
}

function plugin_morefields_uninstall()
{
    return \GlpiPlugin\Morefields\Maintenance::uninstall();
}

/**
 * Colunas de busca (GLPI 11): uma por definição de campo ativa que esteja
 * em algum bloco ligado ao itemtype.
 */
function plugin_morefields_getAddSearchOptionsNew($itemtype)
{
    return FieldDefinition::getSearchOptionsForItemtype((string) $itemtype);
}
