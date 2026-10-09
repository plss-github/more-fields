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
use Html;
use Session;

/**
 * Definição global de campo: reutilizável em vários blocos e itemtypes.
 */
class FieldDefinition extends AdminItem
{
    /** Opção de busca (e do histórico) de um campo = base + id da definição. */
    public const SEARCH_OPTION_BASE = 79000;

    public $dohistory = true;

    public static function getTypeName($nb = 0)
    {
        return _n('Campo', 'Campos', $nb, 'morefields');
    }

    public static function getIcon()
    {
        return 'ti ti-forms';
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab(\Log::class, $tabs, $options);

        return $tabs;
    }

    public function rawSearchOptions()
    {
        return [
            ['id' => 'common', 'name' => self::getTypeName(2)],
            ['id' => '1', 'table' => self::getTable(), 'field' => 'label', 'name' => __('Name'), 'datatype' => 'itemlink', 'massiveaction' => false],
            ['id' => '2', 'table' => self::getTable(), 'field' => 'name', 'name' => __('Nome do sistema', 'morefields'), 'datatype' => 'string'],
            ['id' => '3', 'table' => self::getTable(), 'field' => 'type', 'name' => __('Tipo', 'morefields'), 'datatype' => 'specific', 'searchtype' => 'equals'],
            ['id' => '4', 'table' => self::getTable(), 'field' => 'is_active', 'name' => __('Ativo', 'morefields'), 'datatype' => 'bool'],
            ['id' => '19', 'table' => self::getTable(), 'field' => 'date_mod', 'name' => __('Last update'), 'datatype' => 'datetime', 'massiveaction' => false],
        ];
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if ($field === 'type') {
            return htmlescape(FieldType::all()[$values[$field]] ?? $values[$field]);
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if ($field === 'type') {
            return Dropdown::showFromArray($name, FieldType::all(), ['value' => $values[$field] ?? '', 'display' => false]);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    public function prepareInputForAdd($input)
    {
        $name = strtolower(trim((string) ($input['name'] ?? '')));
        if ($name === '') {
            $name = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', (string) iconv('UTF-8', 'ASCII//TRANSLIT', (string) ($input['label'] ?? ''))));
            $name = trim($name, '_');
        }
        if (!preg_match('/^[a-z][a-z0-9_]{0,99}$/', $name)) {
            Session::addMessageAfterRedirect(__('Nome do sistema inválido (use letras minúsculas, números e _).', 'morefields'), false, ERROR);

            return false;
        }
        if (countElementsInTable(self::getTable(), ['name' => $name]) > 0) {
            Session::addMessageAfterRedirect(__('Já existe um campo com este nome do sistema.', 'morefields'), false, ERROR);

            return false;
        }
        $input['name'] = $name;

        if (!array_key_exists($input['type'] ?? '', FieldType::all())) {
            Session::addMessageAfterRedirect(__('Tipo de campo inválido.', 'morefields'), false, ERROR);

            return false;
        }

        return $this->packConfig($input, $input['type']);
    }

    public function prepareInputForUpdate($input)
    {
        // Nome do sistema e tipo são imutáveis: os valores gravados dependem deles.
        unset($input['name'], $input['type']);

        return $this->packConfig($input, $this->fields['type']);
    }

    private function packConfig(array $input, string $type): array|false
    {
        if (array_key_exists('cfg_choicelist', $input) || array_key_exists('cfg_itemtype', $input)) {
            $config = [];
            if (in_array($type, [FieldType::CHOICE, FieldType::MULTICHOICE], true)) {
                $config['choicelist'] = (int) ($input['cfg_choicelist'] ?? 0);
                if ($config['choicelist'] <= 0) {
                    Session::addMessageAfterRedirect(__('Escolha a lista de valores.', 'morefields'), false, ERROR);

                    return false;
                }
            } elseif ($type === FieldType::GLPI_ITEM) {
                $config['itemtype'] = (string) ($input['cfg_itemtype'] ?? '');
                if (!array_key_exists($config['itemtype'], FieldType::getLinkableItemtypes())) {
                    Session::addMessageAfterRedirect(__('Escolha o tipo de item.', 'morefields'), false, ERROR);

                    return false;
                }
            }
            $input['config'] = json_encode($config);
            unset($input['cfg_choicelist'], $input['cfg_itemtype']);
        }

        return $input;
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        $is_new = $this->isNewID($ID);
        $type   = $this->fields['type'] ?? FieldType::TEXT;
        $config = json_decode((string) ($this->fields['config'] ?? ''), true) ?: [];
        $rand   = mt_rand();

        echo "<tr class='tab_bg_1'><td>" . __s('Name') . "</td><td>";
        echo "<input type='text' class='form-control' name='label' required value='" . htmlescape($this->fields['label'] ?? '') . "'>";
        echo '</td><td>' . __s('Nome do sistema', 'morefields') . '</td><td>';
        if ($is_new) {
            echo "<input type='text' class='form-control' name='name' placeholder='" . __s('gerado a partir do nome', 'morefields') . "' pattern='[a-z][a-z0-9_]*'>";
        } else {
            echo '<code>' . htmlescape($this->fields['name']) . '</code>';
        }
        echo '</td></tr>';

        echo "<tr class='tab_bg_1'><td>" . __s('Tipo', 'morefields') . '</td><td>';
        if ($is_new) {
            Dropdown::showFromArray('type', FieldType::all(), ['value' => $type, 'rand' => $rand]);
        } else {
            echo htmlescape(FieldType::all()[$type] ?? $type);
        }
        echo '</td><td>' . __s('Ativo', 'morefields') . '</td><td>';
        Dropdown::showYesNo('is_active', $this->isNewID($ID) ? 1 : (int) $this->fields['is_active']);
        echo '</td></tr>';

        echo "<tr class='tab_bg_1' data-mf-cfg='choice'><td>" . __s('Lista de valores', 'morefields') . '</td><td>';
        Dropdown::show(ChoiceList::class, ['name' => 'cfg_choicelist', 'value' => $config['choicelist'] ?? 0, 'display_emptychoice' => true]);
        echo '</td><td colspan="2"></td></tr>';

        echo "<tr class='tab_bg_1' data-mf-cfg='glpi_item'><td>" . __s('Tipo de item', 'morefields') . '</td><td>';
        Dropdown::showFromArray('cfg_itemtype', FieldType::getLinkableItemtypes(), ['value' => $config['itemtype'] ?? '', 'display_emptychoice' => true]);
        echo '</td><td colspan="2"></td></tr>';

        echo "<tr class='tab_bg_1'><td>" . __s('Copiar ao clonar', 'morefields') . '</td><td>';
        Dropdown::showYesNo('copy_on_clone', $this->isNewID($ID) ? 1 : (int) $this->fields['copy_on_clone']);
        echo "</td><td colspan='2' class='text-muted'>" . __s('Vale ao clonar um item, criar a partir de modelo e transferir com cópia. Desligue para identificadores únicos (ex.: ID IC), que não devem se repetir no item novo.', 'morefields') . '</td></tr>';

        echo "<tr class='tab_bg_1'><td>" . __s('Comments') . "</td><td colspan='3'>";
        echo "<textarea class='form-control' name='comment' rows='2'>" . htmlescape($this->fields['comment'] ?? '') . '</textarea>';
        echo '</td></tr>';

        echo Html::scriptBlock("
            (() => {
                const form = document.currentScript.closest('form') || document.querySelector('form');
                const typeEl = form.querySelector('[name=type]');
                const current = " . json_encode($is_new ? null : $type) . ";
                const sync = () => {
                    const t = typeEl ? typeEl.value : current;
                    form.querySelectorAll('[data-mf-cfg]').forEach(tr => {
                        const k = tr.dataset.mfCfg;
                        const on = (k === 'choice' && ['choice', 'multichoice'].includes(t)) || (k === 'glpi_item' && t === 'glpi_item');
                        tr.style.display = on ? '' : 'none';
                        tr.querySelectorAll('select').forEach(s => s.disabled = !on);
                    });
                };
                if (typeEl) { $(typeEl).on('change', sync); }
                sync();
            })();
        ");

        if (!$is_new && ($stats = $this->valueStats())['values'] > 0) {
            echo "<tr class='tab_bg_1'><td colspan='4'><label class='form-check'>"
                . "<input class='form-check-input' type='checkbox' name='_confirm_purge_values' value='1'>"
                . "<span class='form-check-label text-danger'><i class='ti ti-alert-triangle me-1'></i>"
                . htmlescape(sprintf(__('Confirmo apagar os valores: este campo tem %1$d valor(es) gravado(s) em %2$d item(ns), e eles serão perdidos se o campo for excluído.', 'morefields'), $stats['values'], $stats['items']))
                . '</span></label></td></tr>';
        }

        $this->showFormButtons($options);

        return true;
    }

    /** @return int[] ids das definições de escolha que usam a lista */
    public static function getIdsUsingList(int $list_id): array
    {
        global $DB;
        $ids = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['type' => [FieldType::CHOICE, FieldType::MULTICHOICE]]]) as $row) {
            $cfg = json_decode((string) $row['config'], true) ?: [];
            if ((int) ($cfg['choicelist'] ?? 0) === $list_id) {
                $ids[] = (int) $row['id'];
            }
        }

        return $ids;
    }

    /** @return array{values: int, items: int} quantos valores/itens usam o campo */
    public function valueStats(): array
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => [
                new \Glpi\DBAL\QueryExpression('COUNT(*) AS c'),
                new \Glpi\DBAL\QueryExpression("COUNT(DISTINCT CONCAT(itemtype, '#', items_id)) AS n"),
            ],
            'FROM'  => 'glpi_plugin_morefields_values',
            'WHERE' => ['plugin_morefields_fielddefinitions_id' => $this->getID()],
        ])->current();

        return ['values' => (int) ($row['c'] ?? 0), 'items' => (int) ($row['n'] ?? 0)];
    }

    public function pre_deleteItem()
    {
        $stats = $this->valueStats();
        if ($stats['values'] > 0 && empty($this->input['_confirm_purge_values'])) {
            Session::addMessageAfterRedirect(
                sprintf(
                    __('O campo "%1$s" tem %2$d valor(es) gravado(s) em %3$d item(ns). Marque "Confirmo apagar os valores" e exclua novamente.', 'morefields'),
                    $this->fields['label'],
                    $stats['values'],
                    $stats['items']
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
        $DB->delete('glpi_plugin_morefields_values', ['plugin_morefields_fielddefinitions_id' => $this->getID()]);

        // Liga o campo a blocos: remove as ligações com seus itens e regras.
        $cf_ids = array_column(iterator_to_array($DB->request([
            'SELECT' => 'id', 'FROM' => ContainerField::getTable(), 'WHERE' => ['plugin_morefields_fielddefinitions_id' => $this->getID()],
        ]), false), 'id');
        if ($cf_ids !== []) {
            $DB->delete('glpi_plugin_morefields_containerfielditemtypes', ['plugin_morefields_containerfields_id' => $cf_ids]);
            $DB->delete(VisibilityRule::getTable(), ['plugin_morefields_containerfields_id' => $cf_ids]);
            $DB->delete(ContainerField::getTable(), ['id' => $cf_ids]);
        }
        // Regras de outros campos que dependiam do valor deste ficariam sem critério.
        foreach ($DB->request(['FROM' => VisibilityRule::getTable()]) as $rule) {
            if (str_contains((string) $rule['conditions'], '"fid":' . $this->getID() . ',')) {
                $DB->update(VisibilityRule::getTable(), ['is_active' => 0], ['id' => $rule['id']]);
            }
        }
    }

    /**
     * Opções de busca para o itemtype: um campo por definição presente em
     * algum bloco ativo ligado a ele.
     */
    public static function getSearchOptionsForItemtype(string $itemtype): array
    {
        global $DB;
        static $cache = [];

        if (isset($cache[$itemtype])) {
            return $cache[$itemtype];
        }
        $cache[$itemtype] = [];

        try {
            if (!in_array($itemtype, Binding::getBoundItemtypes(), true)) {
                return [];
            }
            $rows = $DB->request([
                'SELECT'     => ['d.id', 'd.label', 'd.type', 'd.config', 'c.name AS container'],
                'DISTINCT'   => true,
                'FROM'       => self::getTable() . ' AS d',
                'INNER JOIN' => [
                    'glpi_plugin_morefields_containerfields AS cf' => ['ON' => ['cf' => 'plugin_morefields_fielddefinitions_id', 'd' => 'id']],
                    'glpi_plugin_morefields_containers AS c'       => ['ON' => ['cf' => 'plugin_morefields_containers_id', 'c' => 'id']],
                    'glpi_plugin_morefields_containerfielditemtypes AS i' => ['ON' => ['i' => 'plugin_morefields_containerfields_id', 'cf' => 'id']],
                ],
                'WHERE'      => ['d.is_active' => 1, 'c.is_active' => 1, 'i.itemtype' => $itemtype],
                'GROUPBY'    => ['d.id'],
            ]);
        } catch (\Throwable) {
            return [];
        }

        $values_table = 'glpi_plugin_morefields_values';
        $options      = [['id' => 'morefields', 'name' => __('More Fields', 'morefields')]];

        foreach ($rows as $row) {
            $type   = $row['type'];
            $config = json_decode((string) $row['config'], true) ?: [];
            $fid    = (int) $row['id'];
            $cond   = ['NEWTABLE.plugin_morefields_fielddefinitions_id' => $fid];
            $join   = ['jointype' => 'itemtype_item', 'condition' => $cond];

            $opt = [
                'id'            => self::SEARCH_OPTION_BASE + $fid,
                'name'          => $row['label'],
                'massiveaction' => false,
                'joinparams'    => $join,
                'forcegroupby'  => true,
                'table'         => $values_table,
                'field'         => FieldType::column($type),
            ];

            switch ($type) {
                case FieldType::NUMBER:
                case FieldType::DECIMAL:
                    $opt['datatype'] = 'number';
                    break;
                case FieldType::DATE:
                    $opt['datatype'] = 'date';
                    break;
                case FieldType::DATETIME:
                    $opt['datatype'] = 'datetime';
                    break;
                case FieldType::YESNO:
                    $opt['datatype'] = 'bool';
                    break;
                case FieldType::TEXTAREA:
                    $opt['datatype'] = 'text';
                    break;
                case FieldType::URL:
                    $opt['datatype'] = 'weblink';
                    break;
                case FieldType::CHOICE:
                case FieldType::MULTICHOICE:
                    // choices.id = values.v_int, atravessando a jointure dos valores
                    $opt['table']      = Choice::getTable();
                    $opt['field']      = 'name';
                    $opt['datatype']   = 'dropdown';
                    $opt['linkfield']  = 'v_int';
                    $opt['joinparams'] = ['beforejoin' => ['table' => $values_table, 'joinparams' => $join]];
                    break;
                case FieldType::GLPI_ITEM:
                    $target = (string) ($config['itemtype'] ?? '');
                    if (class_exists($target)) {
                        $opt['table']      = getTableForItemType($target);
                        $opt['field']      = 'name';
                        $opt['datatype']   = 'dropdown';
                        $opt['linkfield']  = 'v_items_id';
                        $opt['joinparams'] = ['beforejoin' => ['table' => $values_table, 'joinparams' => $join]];
                    }
                    break;
                default:
                    $opt['datatype'] = 'string';
            }
            // A tabela de valores não tem classe: sem 'itemtype' o GLPI 11 passa null
            // para getItemForItemtype() e quebra a listagem. Com uma classe real (sem
            // getSpecificValueToDisplay para estas colunas) ele exibe o valor padrão.
            if ($opt['table'] === $values_table) {
                $opt['itemtype'] = self::class;
            }
            $options[] = $opt;
        }

        return $cache[$itemtype] = count($options) > 1 ? $options : [];
    }
}
