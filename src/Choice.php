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
use Html;
use Session;

class Choice extends AdminItem
{
    public static function getTypeName($nb = 0)
    {
        return _n('Valor', 'Valores', $nb, 'morefields');
    }

    /** @return array<int, string> id => nome dos valores ativos, na ordem */
    public static function getList(int $list_id, bool $only_active = true): array
    {
        global $DB;
        static $cache = [];
        $key = $list_id . ($only_active ? 'a' : 'x');
        if (!isset($cache[$key])) {
            $where = ['plugin_morefields_choicelists_id' => $list_id];
            if ($only_active) {
                $where['is_active'] = 1;
            }
            $cache[$key] = [];
            foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => $where, 'ORDER' => ['ranking', 'name']]) as $row) {
                $cache[$key][(int) $row['id']] = $row['name'];
            }
        }

        return $cache[$key];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof ChoiceList) {
            return self::createTabEntry(self::getTypeName(2), count(self::getList($item->getID(), false)), null, 'ti ti-list-details');
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof ChoiceList) {
            self::showForList($item);
        }

        return true;
    }

    public static function showForList(ChoiceList $list): void
    {
        global $DB;

        $can_edit = self::canUpdate();
        $list_id  = (int) $list->getID();
        $url      = htmlescape(self::getFormURL());

        if ($can_edit) {
            echo "<div class='card mb-4'><div class='card-header'><h3 class='card-title'><i class='ti ti-plus me-2'></i>" . __s('Adicionar valor', 'morefields') . '</h3></div>';
            echo "<div class='card-body'><form method='post' action='$url' class='row g-3 align-items-end'>";
            echo "<input type='hidden' name='plugin_morefields_choicelists_id' value='$list_id'>";
            echo "<div class='col-md-5'><label class='form-label'>" . __s('Name') . "</label><input type='text' name='name' required class='form-control'></div>";
            echo "<div class='col-auto'><label class='form-label'>" . __s('Cor', 'morefields') . "</label><input type='color' name='color' class='form-control form-control-color' value='#6c757d'></div>";
            echo "<div class='col-auto'><label class='form-label'>" . __s('Ordem', 'morefields') . "</label><input type='number' name='ranking' class='form-control' style='width:6rem' value='" . (count(self::getList($list_id, false)) + 1) . "'></div>";
            echo "<div class='col-auto ms-auto'><button class='btn btn-primary' name='add' type='submit'><i class='ti ti-plus me-1'></i>" . __s('Add') . '</button></div>';
            echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
            echo '</form></div></div>';
        }

        $rows = iterator_to_array($DB->request(['FROM' => self::getTable(), 'WHERE' => ['plugin_morefields_choicelists_id' => $list_id], 'ORDER' => ['ranking', 'name']]), false);
        if ($rows === []) {
            echo "<div class='text-center text-muted py-5'><i class='ti ti-list-details fs-1 d-block mb-2'></i>" . __s('Nenhum valor nesta lista ainda.', 'morefields') . '</div>';

            return;
        }

        $in_use   = [];
        $field_ids = FieldDefinition::getIdsUsingList($list_id);
        if ($field_ids !== []) {
            foreach ($DB->request([
                'SELECT'  => ['v_int', new \Glpi\DBAL\QueryExpression('COUNT(*) AS n')],
                'FROM'    => 'glpi_plugin_morefields_values',
                'WHERE'   => ['plugin_morefields_fielddefinitions_id' => $field_ids],
                'GROUPBY' => 'v_int',
            ]) as $r) {
                $in_use[(int) $r['v_int']] = (int) $r['n'];
            }
        }

        echo "<div class='table-responsive'><table class='table table-hover align-middle mf-table'><thead><tr>"
            . '<th>' . __s('Valor', 'morefields') . '</th><th>' . __s('Ordem', 'morefields') . '</th><th>' . __s('Situação', 'morefields') . '</th><th></th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $row['color']) ? $row['color'] : '#6c757d';
            echo '<tr>';
            echo "<td><span class='badge' style='background:" . htmlescape($color) . ";color:#fff'>" . htmlescape($row['name']) . '</span></td>';
            echo '<td>' . (int) $row['ranking'] . '</td>';
            echo '<td>' . ($row['is_active']
                ? "<span class='badge bg-green-lt'><i class='ti ti-check me-1'></i>" . __s('Ativo', 'morefields') . '</span>'
                : "<span class='badge bg-secondary-lt'><i class='ti ti-player-pause me-1'></i>" . __s('Inativo', 'morefields') . '</span>') . '</td>';
            echo "<td class='text-end text-nowrap'>";
            if ($can_edit) {
                echo "<form method='post' action='$url' style='display:inline'>";
                echo "<input type='hidden' name='id' value='" . (int) $row['id'] . "'>";
                echo "<input type='hidden' name='is_active' value='" . ($row['is_active'] ? 0 : 1) . "'>";
                echo "<button class='btn btn-icon btn-outline-secondary btn-sm' name='update' type='submit' title='" . ($row['is_active'] ? __s('Desativar', 'morefields') : __s('Ativar', 'morefields')) . "'><i class='ti " . ($row['is_active'] ? 'ti-player-pause' : 'ti-player-play') . "'></i></button> ";
                echo "<button class='btn btn-icon btn-outline-danger btn-sm' name='purge' type='submit' title='" . __s('Delete permanently') . "' onclick=\"return confirm('" . ($in_use[(int) $row['id']] ?? 0 > 0
                    ? htmlescape(sprintf(__('%d valor(es) gravado(s) usam esta opção e serão apagados. Excluir mesmo assim?', 'morefields'), $in_use[(int) $row['id']]))
                    : __s('Confirm the final deletion?')) . "')\"><i class='ti ti-trash'></i></button>";
                echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
                echo '</form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    protected function historyParent(): ?array
    {
        return [ChoiceList::class, (int) ($this->fields['plugin_morefields_choicelists_id'] ?? 0)];
    }

    protected function historyLabel(): string
    {
        return (string) ($this->fields['name'] ?? ('#' . $this->getID()));
    }

    public function cleanDBonPurge()
    {
        global $DB;
        // Remove valores gravados que apontavam para esta escolha (só nos
        // campos que usam esta lista: v_int é compartilhada com outros tipos).
        $field_ids = FieldDefinition::getIdsUsingList((int) $this->fields['plugin_morefields_choicelists_id']);
        if ($field_ids !== []) {
            $DB->delete('glpi_plugin_morefields_values', [
                'plugin_morefields_fielddefinitions_id' => $field_ids,
                'v_int'                                 => $this->getID(),
            ]);
        }
    }
}
