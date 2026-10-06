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

use Dropdown;

/**
 * Bloco de campos: só um agrupador (nome, entidade e regras). O local de
 * exibição e os itens em que cada campo vale ficam em ContainerField.
 */
class Container extends AdminItem
{
    public $dohistory = true;

    public static function getTypeName($nb = 0)
    {
        return _n('Bloco de campos', 'Blocos de campos', $nb, 'morefields');
    }

    public static function getIcon()
    {
        return 'ti ti-layout-list';
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab(ContainerField::class, $tabs, $options);
        $this->addStandardTab(VisibilityRule::class, $tabs, $options);
        $this->addStandardTab(\Log::class, $tabs, $options);

        return $tabs;
    }

    public function rawSearchOptions()
    {
        return [
            ['id' => 'common', 'name' => self::getTypeName(2)],
            ['id' => '1', 'table' => self::getTable(), 'field' => 'name', 'name' => __('Name'), 'datatype' => 'itemlink', 'massiveaction' => false],
            ['id' => '3', 'table' => self::getTable(), 'field' => 'is_active', 'name' => __('Ativo', 'morefields'), 'datatype' => 'bool'],
            ['id' => '80', 'table' => 'glpi_entities', 'field' => 'completename', 'name' => __('Entity'), 'datatype' => 'dropdown'],
            ['id' => '19', 'table' => self::getTable(), 'field' => 'date_mod', 'name' => __('Last update'), 'datatype' => 'datetime', 'massiveaction' => false],
        ];
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        echo "<tr class='tab_bg_1'><td>" . __s('Name') . "</td><td>";
        echo "<input type='text' class='form-control' name='name' required value='" . htmlescape($this->fields['name'] ?? '') . "'>";
        echo '</td><td>' . __s('Ativo', 'morefields') . '</td><td>';
        Dropdown::showYesNo('is_active', $this->isNewID($ID) ? 1 : (int) $this->fields['is_active']);
        echo '</td></tr>';

        echo "<tr class='tab_bg_1'><td>" . __s('Comments') . "</td><td colspan='3'>";
        echo "<textarea class='form-control' name='comment' rows='2'>" . htmlescape($this->fields['comment'] ?? '') . '</textarea>';
        echo '</td></tr>';
        echo "<tr class='tab_bg_1'><td colspan='4' class='text-muted'>" . __s('O local de exibição e os itens em que cada campo vale são definidos na aba "Campos".', 'morefields') . '</td></tr>';

        $this->showFormButtons($options);

        return true;
    }

    public function cleanDBonPurge()
    {
        global $DB;

        // deleteChildrenAndRelationsFromDb() só funciona com CommonDBConnexity.
        $cf_ids = array_column(iterator_to_array($DB->request([
            'SELECT' => 'id', 'FROM' => ContainerField::getTable(), 'WHERE' => ['plugin_morefields_containers_id' => $this->getID()],
        ]), false), 'id');
        if ($cf_ids !== []) {
            $DB->delete('glpi_plugin_morefields_containerfielditemtypes', ['plugin_morefields_containerfields_id' => $cf_ids]);
        }
        $DB->delete(VisibilityRule::getTable(), ['plugin_morefields_containers_id' => $this->getID()]);
        $DB->delete(ContainerField::getTable(), ['plugin_morefields_containers_id' => $this->getID()]);
    }
}
