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

use Session;

/**
 * Lista de valores compartilhada: qualquer campo de escolha pode apontar
 * para a mesma lista.
 */
class ChoiceList extends AdminItem
{
    public $dohistory = true;

    public static function getTypeName($nb = 0)
    {
        return _n('Lista de valores', 'Listas de valores', $nb, 'morefields');
    }

    public static function getIcon()
    {
        return 'ti ti-list-details';
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab(Choice::class, $tabs, $options);
        $this->addStandardTab(\Log::class, $tabs, $options);

        return $tabs;
    }

    public function rawSearchOptions()
    {
        return [
            ['id' => 'common', 'name' => self::getTypeName(2)],
            ['id' => '1', 'table' => self::getTable(), 'field' => 'name', 'name' => __('Name'), 'datatype' => 'itemlink', 'massiveaction' => false],
            ['id' => '2', 'table' => self::getTable(), 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number', 'massiveaction' => false],
            ['id' => '19', 'table' => self::getTable(), 'field' => 'date_mod', 'name' => __('Last update'), 'datatype' => 'datetime', 'massiveaction' => false],
        ];
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        echo "<tr class='tab_bg_1'><td>" . __s('Name') . "</td><td>";
        echo "<input type='text' class='form-control' name='name' required value='" . htmlescape($this->fields['name'] ?? '') . "'>";
        echo "</td><td>" . __s('Comments') . "</td><td>";
        echo "<textarea class='form-control' name='comment' rows='2'>" . htmlescape($this->fields['comment'] ?? '') . '</textarea>';
        echo '</td></tr>';

        $this->showFormButtons($options);

        return true;
    }

    /** @return array{fields: string[], values: int} campos que usam a lista e valores gravados nas opções dela */
    public function usage(): array
    {
        global $DB;

        $field_ids = FieldDefinition::getIdsUsingList((int) $this->getID());
        $labels    = [];
        $values    = 0;
        if ($field_ids !== []) {
            foreach ($DB->request(['SELECT' => ['label'], 'FROM' => FieldDefinition::getTable(), 'WHERE' => ['id' => $field_ids]]) as $row) {
                $labels[] = $row['label'];
            }
            $choice_ids = array_column(iterator_to_array($DB->request([
                'SELECT' => 'id', 'FROM' => Choice::getTable(), 'WHERE' => ['plugin_morefields_choicelists_id' => $this->getID()],
            ]), false), 'id');
            if ($choice_ids !== []) {
                $values = countElementsInTable('glpi_plugin_morefields_values', [
                    'plugin_morefields_fielddefinitions_id' => $field_ids,
                    'v_int'                                 => $choice_ids,
                ]);
            }
        }

        return ['fields' => $labels, 'values' => $values];
    }

    public function pre_deleteItem()
    {
        $usage = $this->usage();
        if ($usage['fields'] !== []) {
            Session::addMessageAfterRedirect(
                sprintf(
                    __('A lista "%1$s" é usada pelos campos: %2$s. Altere ou exclua esses campos antes de excluir a lista.', 'morefields'),
                    $this->fields['name'],
                    implode(', ', $usage['fields'])
                ),
                false,
                ERROR
            );

            return false;
        }

        return true;
    }

    public function cleanDBonPurge()
    {
        global $DB;

        // Valores gravados que apontavam para as opções desta lista.
        $choice_ids = array_column(iterator_to_array($DB->request([
            'SELECT' => 'id', 'FROM' => Choice::getTable(), 'WHERE' => ['plugin_morefields_choicelists_id' => $this->getID()],
        ]), false), 'id');
        $field_ids = FieldDefinition::getIdsUsingList((int) $this->getID());
        if ($choice_ids !== [] && $field_ids !== []) {
            $DB->delete('glpi_plugin_morefields_values', [
                'plugin_morefields_fielddefinitions_id' => $field_ids,
                'v_int'                                 => $choice_ids,
            ]);
        }
        $DB->delete(Choice::getTable(), ['plugin_morefields_choicelists_id' => $this->getID()]);
    }
}
