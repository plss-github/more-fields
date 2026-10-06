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
 * Regra de visibilidade/obrigatoriedade para um bloco inteiro (alvo 0) ou
 * para um campo do bloco. As condições ficam em JSON editado por um
 * repetidor em JS (public/js/morefields.js).
 */
class VisibilityRule extends AdminItem
{
    public static function getTypeName($nb = 0)
    {
        return _n('Regra de exibição', 'Regras de exibição', $nb, 'morefields');
    }

    public static function getActions(): array
    {
        return [
            'show'     => __('Mostrar somente se', 'morefields'),
            'hide'     => __('Ocultar se', 'morefields'),
            'required' => __('Tornar obrigatório se', 'morefields'),
            'readonly' => __('Tornar somente leitura se', 'morefields'),
        ];
    }

    /** @return array<int, array<int, array{action: string, compiled: array}>> alvo => regras */
    public static function loadForContainer(int $container_id): array
    {
        global $DB;

        $result = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['plugin_morefields_containers_id' => $container_id, 'is_active' => 1], 'ORDER' => 'id']) as $row) {
            $result[(int) $row['plugin_morefields_containerfields_id']][] = [
                'action'   => $row['action'],
                'compiled' => RuleEngine::compile($row),
            ];
        }

        return $result;
    }

    /** Dados para os seletores do repetidor de condições. */
    public static function getCriteriaOptions(): array
    {
        global $DB;

        $pluck = static function (string $table, string $field = 'name') use ($DB): array {
            $out = [];
            foreach ($DB->request(['SELECT' => ['id', $field], 'FROM' => $table, 'ORDER' => $field]) as $row) {
                $out[(string) $row['id']] = $row[$field];
            }

            return $out;
        };

        $criteria = [
            'profile'      => ['label' => __('Perfil ativo', 'morefields'), 'options' => $pluck('glpi_profiles'), 'sons' => false],
            'entity'       => ['label' => __('Entidade', 'morefields'), 'options' => $pluck('glpi_entities', 'completename'), 'sons' => true],
            'itilcategory' => ['label' => __('Categoria ITIL', 'morefields'), 'options' => $pluck('glpi_itilcategories', 'completename'), 'sons' => true],
            'status'       => ['label' => __('Status (chamado)', 'morefields'), 'options' => array_map('strval', \Ticket::getAllStatusArray()), 'sons' => false],
            'type'         => ['label' => __('Tipo (chamado)', 'morefields'), 'options' => array_map('strval', \Ticket::getTypes()), 'sons' => false],
        ];

        $fields = [];
        foreach ($DB->request(['FROM' => FieldDefinition::getTable(), 'WHERE' => ['is_active' => 1], 'ORDER' => 'label']) as $row) {
            $cfg      = json_decode((string) $row['config'], true) ?: [];
            $options  = null;
            if (in_array($row['type'], [FieldType::CHOICE, FieldType::MULTICHOICE], true)) {
                $options = array_map('strval', FieldType::getChoices($cfg));
            } elseif ($row['type'] === FieldType::YESNO) {
                $options = ['1' => __('Yes'), '0' => __('No')];
            }
            $fields[] = ['id' => (int) $row['id'], 'label' => $row['label'], 'options' => $options];
        }

        return ['criteria' => $criteria, 'fields' => $fields];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Container) {
            return self::createTabEntry(self::getTypeName(2), countElementsInTable(self::getTable(), ['plugin_morefields_containers_id' => $item->getID()]), null, 'ti ti-eye-cog');
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

    private const ACTION_STYLE = [
        'show'     => ['bg-green-lt', 'ti ti-eye'],
        'hide'     => ['bg-red-lt', 'ti ti-eye-off'],
        'required' => ['bg-orange-lt', 'ti ti-asterisk'],
        'readonly' => ['bg-blue-lt', 'ti ti-lock'],
    ];

    public static function showForContainer(Container $container): void
    {
        global $DB;

        $cid     = (int) $container->getID();
        $targets = [0 => __('O bloco inteiro', 'morefields')];
        foreach (Binding::getContainerFields($cid) as $cf_id => $f) {
            $targets[$cf_id] = $f['label'];
        }
        $actions = self::getActions();
        $labels  = [
            'profile' => __('Perfil', 'morefields'), 'entity' => __('Entidade', 'morefields'), 'itilcategory' => __('Categoria ITIL', 'morefields'),
            'status' => __('Status', 'morefields'), 'type' => __('Tipo', 'morefields'), 'field' => __('Outro campo', 'morefields'),
        ];

        if (self::canUpdate()) {
            $url = self::getFormURL() . '?plugin_morefields_containers_id=' . $cid;
            echo "<div class='mb-3'><a class='btn btn-primary' href='" . htmlescape($url) . "'><i class='ti ti-plus me-1'></i>" . __s('Nova regra', 'morefields') . '</a></div>';
        }

        $rules = iterator_to_array($DB->request(['FROM' => self::getTable(), 'WHERE' => ['plugin_morefields_containers_id' => $cid], 'ORDER' => 'id']), false);
        if ($rules === []) {
            echo "<div class='text-center text-muted py-5'><i class='ti ti-eye-cog fs-1 d-block mb-2'></i>" . __s('Nenhuma regra neste bloco. Sem regras, todos os campos aparecem para todos.', 'morefields') . '</div>';

            return;
        }

        echo "<div class='table-responsive'><table class='table table-hover align-middle mf-table'><thead><tr>"
            . '<th>' . __s('Name') . '</th><th>' . __s('Alvo', 'morefields') . '</th><th>' . __s('Ação', 'morefields') . '</th><th>' . __s('Condições', 'morefields') . '</th><th>' . __s('Situação', 'morefields') . '</th></tr></thead><tbody>';
        foreach ($rules as $row) {
            [$cls, $icon] = self::ACTION_STYLE[$row['action']] ?? ['bg-secondary-lt', 'ti ti-help'];
            $conds = json_decode((string) $row['conditions'], true) ?: [];
            $crits = array_unique(array_map(static fn($c) => $labels[$c['c'] ?? ''] ?? '?', $conds));
            echo '<tr>';
            echo "<td><a class='fw-bold' href='" . htmlescape(self::getFormURLWithID((int) $row['id'])) . "'><i class='ti ti-eye-cog me-1'></i>" . htmlescape($row['name'] ?: ('#' . $row['id'])) . '</a></td>';
            echo '<td>' . ((int) $row['plugin_morefields_containerfields_id'] === 0
                ? "<span class='badge bg-purple-lt'><i class='ti ti-layout-list me-1'></i>" . htmlescape($targets[0]) . '</span>'
                : "<span class='badge bg-azure-lt'><i class='ti ti-forms me-1'></i>" . htmlescape($targets[(int) $row['plugin_morefields_containerfields_id']] ?? '?') . '</span>') . '</td>';
            echo "<td><span class='badge $cls'><i class='$icon me-1'></i>" . htmlescape($actions[$row['action']] ?? $row['action']) . '</span></td>';
            echo '<td>' . ($conds === [] ? "<span class='text-muted'>" . __s('Sempre', 'morefields') . '</span>'
                : htmlescape(implode(', ', $crits)) . " <span class='badge bg-secondary-lt ms-1'>" . ($row['match_mode'] === 'OR' ? __s('qualquer', 'morefields') : __s('todas', 'morefields')) . '</span>') . '</td>';
            echo '<td>' . ($row['is_active']
                ? "<span class='badge bg-green-lt'><i class='ti ti-check me-1'></i>" . __s('Ativo', 'morefields') . '</span>'
                : "<span class='badge bg-secondary-lt'><i class='ti ti-player-pause me-1'></i>" . __s('Inativo', 'morefields') . '</span>') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    protected function historyParent(): ?array
    {
        return [Container::class, (int) ($this->fields['plugin_morefields_containers_id'] ?? 0)];
    }

    protected function historyLabel(): string
    {
        return (string) (($this->fields['name'] ?? '') !== '' ? $this->fields['name'] : '#' . $this->getID());
    }

    public function prepareInputForAdd($input)
    {
        // Regra sem bloco ficaria invisível e sem efeito.
        if (!(new Container())->getFromDB((int) ($input['plugin_morefields_containers_id'] ?? 0))) {
            Session::addMessageAfterRedirect(__('A regra precisa pertencer a um bloco.', 'morefields'), false, ERROR);

            return false;
        }

        return $this->checkInput($input);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->checkInput($input);
    }

    private function checkInput(array $input): array|false
    {
        if (isset($input['action']) && !array_key_exists($input['action'], self::getActions())) {
            return false;
        }
        if (array_key_exists('conditions', $input)) {
            $decoded = json_decode((string) $input['conditions'], true);
            if (!is_array($decoded)) {
                $decoded = [];
            }
            $clean = [];
            foreach ($decoded as $c) {
                $criterion = (string) ($c['c'] ?? '');
                if (!in_array($criterion, ['profile', 'entity', 'itilcategory', 'status', 'type', 'field'], true)) {
                    continue;
                }
                $op = in_array($c['op'] ?? '', ['in', 'not_in', 'empty', 'not_empty'], true) ? $c['op'] : 'in';
                $clean[] = [
                    'c'    => $criterion,
                    'fid'  => $criterion === 'field' ? (int) ($c['fid'] ?? 0) : null,
                    'op'   => $op,
                    'vals' => array_values(array_map('strval', (array) ($c['vals'] ?? []))),
                    'sons' => !empty($c['sons']),
                ];
            }
            $input['conditions'] = json_encode($clean);
        }
        if (isset($input['match_mode'])) {
            $input['match_mode'] = $input['match_mode'] === 'OR' ? 'OR' : 'AND';
        }

        return $input;
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);

        $cid = (int) ($this->fields['plugin_morefields_containers_id'] ?? $options['plugin_morefields_containers_id'] ?? $_GET['plugin_morefields_containers_id'] ?? 0);
        $this->showFormHeader($options);

        $targets = [0 => __('O bloco inteiro', 'morefields')];
        foreach (Binding::getContainerFields($cid) as $cf_id => $f) {
            $targets[$cf_id] = $f['label'];
        }

        echo "<tr class='tab_bg_1'><td>" . __s('Name') . '</td><td>';
        echo "<input type='hidden' name='plugin_morefields_containers_id' value='$cid'>";
        echo "<input type='text' class='form-control' name='name' value='" . htmlescape($this->fields['name'] ?? '') . "'>";
        echo '</td><td>' . __s('Ativo', 'morefields') . '</td><td>';
        Dropdown::showYesNo('is_active', $this->isNewID($ID) ? 1 : (int) $this->fields['is_active']);
        echo '</td></tr>';

        echo "<tr class='tab_bg_1'><td>" . __s('Alvo', 'morefields') . '</td><td>';
        Dropdown::showFromArray('plugin_morefields_containerfields_id', $targets, ['value' => $this->fields['plugin_morefields_containerfields_id'] ?? 0]);
        echo '</td><td>' . __s('Ação', 'morefields') . '</td><td>';
        Dropdown::showFromArray('action', self::getActions(), ['value' => $this->fields['action'] ?? 'hide']);
        echo '</td></tr>';

        echo "<tr class='tab_bg_1'><td>" . __s('Condições', 'morefields') . '</td><td colspan="3">';
        echo '<div class="mb-2">' . __s('Vale quando', 'morefields') . ' ';
        Dropdown::showFromArray('match_mode', ['AND' => __('todas as condições forem verdadeiras', 'morefields'), 'OR' => __('qualquer condição for verdadeira', 'morefields')], [
            'value' => $this->fields['match_mode'] ?? 'AND', 'width' => '320px',
        ]);
        echo '</div>';
        echo "<input type='hidden' name='conditions' id='mf-conditions-json' value='" . htmlescape($this->fields['conditions'] ?? '[]') . "'>";
        echo "<div id='mf-conditions' data-options='" . htmlescape(json_encode(self::getCriteriaOptions())) . "'></div>";
        echo '</td></tr>';

        $this->showFormButtons($options);

        return true;
    }
}
