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

use CommonGLPI;
use Dropdown;
use Html;
use Session;

/**
 * Liga uma definição global de campo a um bloco (com ordem, obrigatório e
 * somente-leitura próprios daquele bloco).
 */
class ContainerField extends AdminItem
{
    public const PLACEMENT_DOM = 'dom';
    public const PLACEMENT_TAB = 'tab';

    public static function getPlacements(): array
    {
        return [
            self::PLACEMENT_DOM => __('Formulário principal', 'morefields'),
            self::PLACEMENT_TAB => __('Aba', 'morefields'),
        ];
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Campo do bloco', 'Campos do bloco', $nb, 'morefields');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Container) {
            return self::createTabEntry(
                FieldDefinition::getTypeName(2),
                countElementsInTable(self::getTable(), ['plugin_morefields_containers_id' => $item->getID()]),
                null,
                'ti ti-forms'
            );
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Container) {
            self::showForContainer($item);
        }

        return true;
    }

    public static function showForContainer(Container $container): void
    {
        global $DB;

        $cid      = (int) $container->getID();
        $can_edit = self::canUpdate();
        $url      = htmlescape(self::getFormURL());
        $rows     = iterator_to_array($DB->request([
            'SELECT'     => ['cf.*', 'd.label', 'd.name AS sysname', 'd.type'],
            'FROM'       => self::getTable() . ' AS cf',
            'INNER JOIN' => [FieldDefinition::getTable() . ' AS d' => ['ON' => ['cf' => 'plugin_morefields_fielddefinitions_id', 'd' => 'id']]],
            'WHERE'      => ['cf.plugin_morefields_containers_id' => $cid],
            'ORDER'      => ['cf.ranking', 'cf.id'],
        ]), false);
        $types = Binding::getFieldItemtypes($cid);

        if ($can_edit) {
            $used = array_column($rows, 'plugin_morefields_fielddefinitions_id');
            echo "<div class='card mb-4'><div class='card-header'><h3 class='card-title'><i class='ti ti-plus me-2'></i>" . __s('Adicionar campo ao bloco', 'morefields') . '</h3></div>';
            echo "<div class='card-body'><form method='post' action='$url' class='row g-3 align-items-end'>";
            echo "<input type='hidden' name='plugin_morefields_containers_id' value='$cid'>";
            echo "<div class='col-md-3'><label class='form-label'>" . __s('Campo', 'morefields') . ' *</label>';
            Dropdown::show(FieldDefinition::class, ['name' => 'plugin_morefields_fielddefinitions_id', 'used' => $used, 'condition' => ['is_active' => 1], 'width' => '100%']);
            echo '</div>';
            echo "<div class='col-md-4'><label class='form-label'>" . __s('Vale para os itens', 'morefields') . ' *</label>' . self::itemtypeSelect([], null) . '</div>';
            echo "<div class='col-md-2'><label class='form-label'>" . __s('Onde exibir', 'morefields') . '</label>';
            Dropdown::showFromArray('placement', self::getPlacements(), ['value' => self::PLACEMENT_TAB, 'width' => '100%']);
            echo '</div>';
            echo "<div class='col-md-3' data-mf-tablabel><label class='form-label'>" . __s('Nome da aba', 'morefields') . '</label>'
                . "<input type='text' name='tab_label' class='form-control' placeholder='" . htmlescape($container->fields['name']) . "'></div>";
            echo "<div class='col-auto'><label class='form-check form-switch mb-0'><input class='form-check-input' type='checkbox' name='is_required' value='1'><span class='form-check-label'>" . __s('Obrigatório', 'morefields') . '</span></label></div>';
            echo "<div class='col-auto'><label class='form-check form-switch mb-0'><input class='form-check-input' type='checkbox' name='is_readonly' value='1'><span class='form-check-label'>" . __s('Somente leitura', 'morefields') . '</span></label></div>';
            echo "<div class='col-auto ms-auto'><button class='btn btn-primary' name='add' type='submit'><i class='ti ti-plus me-1'></i>" . __s('Add') . '</button></div>';
            echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
            echo '</form></div></div>';
        }

        if ($rows === []) {
            echo "<div class='text-center text-muted py-5'><i class='ti ti-forms fs-1 d-block mb-2'></i>" . __s('Nenhum campo neste bloco ainda.', 'morefields') . '</div>';

            return;
        }

        echo "<div class='table-responsive'><table class='table table-hover align-middle mf-table'><thead><tr>"
            . "<th style='width:5rem'><i class='ti ti-arrows-sort me-1'></i>" . __s('Ordem', 'morefields') . '</th>'
            . '<th>' . __s('Campo', 'morefields') . '</th>'
            . "<th><i class='ti ti-box me-1'></i>" . __s('Vale para os itens', 'morefields') . '</th>'
            . "<th><i class='ti ti-layout me-1'></i>" . __s('Onde exibir', 'morefields') . '</th>'
            . "<th><i class='ti ti-tag me-1'></i>" . __s('Nome da aba', 'morefields') . '</th>'
            . "<th class='text-center' title='" . __s('Obrigatório', 'morefields') . "'><i class='ti ti-asterisk'></i></th>"
            . "<th class='text-center' title='" . __s('Somente leitura', 'morefields') . "'><i class='ti ti-lock'></i></th>"
            . '<th></th></tr></thead><tbody>';

        foreach ($rows as $row) {
            $id  = (int) $row['id'];
            $dis = $can_edit ? '' : ' disabled';
            echo "<tr><form method='post' action='$url' id='mf_cf_$id'></form>";
            echo "<td><input form='mf_cf_$id' type='number' name='ranking' value='" . (int) $row['ranking'] . "' class='form-control form-control-sm' style='width:4.5rem'$dis></td>";
            echo '<td><div class="fw-bold">' . htmlescape($row['label']) . '</div>'
                . '<div class="mt-1">' . FieldType::badge($row['type']) . " <code class='ms-1'>" . htmlescape($row['sysname']) . '</code></div></td>';
            echo '<td>' . self::itemtypeSelect($types[$id] ?? [], $id, !$can_edit) . '</td>';
            echo '<td><select form="mf_cf_' . $id . '" name="placement" class="form-select form-select-sm"' . $dis . '>';
            foreach (self::getPlacements() as $key => $label) {
                echo "<option value='$key'" . ($row['placement'] === $key ? ' selected' : '') . '>' . htmlescape($label) . '</option>';
            }
            echo '</select></td>';
            echo "<td><input form='mf_cf_$id' type='text' name='tab_label' class='form-control form-control-sm' value='" . htmlescape((string) $row['tab_label'])
                . "' placeholder='" . htmlescape($container->fields['name']) . "'$dis></td>";
            echo "<td class='text-center'><input form='mf_cf_$id' class='form-check-input' type='checkbox' name='is_required' value='1' " . ($row['is_required'] ? 'checked' : '') . "$dis></td>";
            echo "<td class='text-center'><input form='mf_cf_$id' class='form-check-input' type='checkbox' name='is_readonly' value='1' " . ($row['is_readonly'] ? 'checked' : '') . "$dis></td>";
            echo "<td class='text-end text-nowrap'>";
            if ($can_edit) {
                // desmarcar checkbox não envia nada: o marcador força o valor 0
                echo "<input form='mf_cf_$id' type='hidden' name='_mf_flags' value='1'>";
                echo "<input form='mf_cf_$id' type='hidden' name='id' value='$id'>";
                echo "<input form='mf_cf_$id' type='hidden' name='_glpi_csrf_token' value='" . htmlescape(Session::getNewCSRFToken()) . "'>";
                echo "<button form='mf_cf_$id' class='btn btn-icon btn-primary btn-sm' name='update' type='submit' title='" . __s('Save') . "'><i class='ti ti-device-floppy'></i></button> ";
                echo "<button form='mf_cf_$id' class='btn btn-icon btn-outline-danger btn-sm' name='purge' type='submit' title='" . __s('Delete permanently') . "' onclick=\"return confirm('" . __s('Confirm the final deletion?') . "')\"><i class='ti ti-trash'></i></button>";
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public function prepareInputForAdd($input)
    {
        $input['is_required'] = (int) ($input['is_required'] ?? 0);
        $input['is_readonly'] = (int) ($input['is_readonly'] ?? 0);
        $input = $this->checkPlacement($input);
        if (empty($input['plugin_morefields_fielddefinitions_id'])) {
            Session::addMessageAfterRedirect(__('Escolha o campo.', 'morefields'), false, ERROR);

            return false;
        }
        if (self::cleanItemtypes($input['itemtypes'] ?? []) === []) {
            Session::addMessageAfterRedirect(__('Escolha ao menos um item em "Vale para os itens".', 'morefields'), false, ERROR);

            return false;
        }
        if (!isset($input['ranking'])) {
            $input['ranking'] = countElementsInTable(self::getTable(), ['plugin_morefields_containers_id' => $input['plugin_morefields_containers_id']]) + 1;
        }

        return $input;
    }

    protected function historyParent(): ?array
    {
        return [Container::class, (int) ($this->fields['plugin_morefields_containers_id'] ?? 0)];
    }

    protected function historyLabel(): string
    {
        return Api::describe((int) ($this->fields['plugin_morefields_fielddefinitions_id'] ?? 0)) ?? ('#' . $this->getID());
    }

    public function post_addItem()
    {
        self::syncItemtypes((int) $this->getID(), self::cleanItemtypes($this->input['itemtypes'] ?? []));
        parent::post_addItem();
    }

    public function prepareInputForUpdate($input)
    {
        // Gravado aqui (e não em post_updateItem) porque o core só chama esse
        // hook se algum campo da própria linha mudou. Lista vazia = sem alteração.
        $itemtypes = self::cleanItemtypes($input['itemtypes'] ?? []);
        if ($itemtypes !== []) {
            $old = Binding::getFieldItemtypes((int) $this->fields['plugin_morefields_containers_id'])[(int) $this->fields['id']] ?? [];
            sort($old);
            $new = $itemtypes;
            sort($new);
            self::syncItemtypes((int) $this->fields['id'], $itemtypes);
            if ($old !== $new) {
                // Mudar só os itens não altera a linha do campo, então o registro é feito aqui.
                $this->logOnParent(\Log::HISTORY_UPDATE_SUBITEM, $this->historyLabel() . ' (' . __('itens', 'morefields') . ': ' . implode(', ', $new) . ')');
            }
        }
        if (isset($input['_mf_flags'])) {
            $input['is_required'] = (int) ($input['is_required'] ?? 0);
            $input['is_readonly'] = (int) ($input['is_readonly'] ?? 0);
        }
        unset($input['plugin_morefields_containers_id'], $input['plugin_morefields_fielddefinitions_id']);

        return $this->checkPlacement($input);
    }

    private function checkPlacement(array $input): array
    {
        if (array_key_exists('placement', $input) && !array_key_exists($input['placement'], self::getPlacements())) {
            $input['placement'] = self::PLACEMENT_TAB;
        }
        if (array_key_exists('tab_label', $input)) {
            $label = trim((string) $input['tab_label']);
            $input['tab_label'] = $label === '' ? null : mb_substr($label, 0, 255);
        }
        // O nome da aba só faz sentido para campos em aba.
        if (($input['placement'] ?? $this->fields['placement'] ?? '') === self::PLACEMENT_DOM) {
            $input['tab_label'] = null;
        }

        return $input;
    }

    /** @return string[] só os itemtypes permitidos */
    private static function cleanItemtypes(mixed $raw): array
    {
        $allowed = FieldType::getLinkableItemtypes();

        return array_values(array_unique(array_filter(
            (array) $raw,
            static fn($itemtype) => is_string($itemtype) && isset($allowed[$itemtype]),
        )));
    }

    private static function syncItemtypes(int $cf_id, array $itemtypes): void
    {
        global $DB;

        $DB->delete('glpi_plugin_morefields_containerfielditemtypes', ['plugin_morefields_containerfields_id' => $cf_id]);
        foreach ($itemtypes as $itemtype) {
            $DB->insert('glpi_plugin_morefields_containerfielditemtypes', [
                'plugin_morefields_containerfields_id' => $cf_id,
                'itemtype'                             => $itemtype,
            ]);
        }
    }

    /**
     * Seleção múltipla com busca (select2), a mesma dos demais campos do GLPI.
     * Nas linhas da tabela o controle é associado ao formulário da linha pelo
     * atributo `form` (a tabela não fica dentro de um <form>).
     */
    private static function itemtypeSelect(array $selected, ?int $form_id, bool $disabled = false): string
    {
        $html = (string) Dropdown::showFromArray('itemtypes', FieldType::getLinkableItemtypes(), [
            'multiple'    => true,
            'values'      => $selected,
            'display'     => false,
            'width'       => '100%',
            'readonly'    => $disabled,
            'placeholder' => __('Selecione os itens', 'morefields'),
        ]);
        if ($form_id !== null) {
            $html = (string) preg_replace('/<(select|input)\b/', "<$1 form='mf_cf_$form_id'", $html);
        }

        return "<div style='min-width:18rem'>" . $html . '</div>';
    }

    public function cleanDBonPurge()
    {
        global $DB;
        $DB->delete('glpi_plugin_morefields_containerfielditemtypes', ['plugin_morefields_containerfields_id' => $this->getID()]);
        $DB->delete(VisibilityRule::getTable(), ['plugin_morefields_containerfields_id' => $this->getID()]);
    }
}
