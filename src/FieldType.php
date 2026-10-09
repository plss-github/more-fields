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

/**
 * Tipos de campo: coluna de armazenamento, renderização, normalização e
 * formatação de exibição. O armazenamento é sempre a tabela `values`.
 */
final class FieldType
{
    public const TEXT        = 'text';
    public const TEXTAREA    = 'textarea';
    public const NUMBER      = 'number';
    public const DECIMAL     = 'decimal';
    public const URL         = 'url';
    public const DATE        = 'date';
    public const DATETIME    = 'datetime';
    public const YESNO       = 'yesno';
    public const CHOICE      = 'choice';
    public const MULTICHOICE = 'multichoice';
    public const GLPI_ITEM   = 'glpi_item';

    public static function all(): array
    {
        return [
            self::TEXT        => __('Texto (uma linha)', 'morefields'),
            self::TEXTAREA    => __('Texto (várias linhas)', 'morefields'),
            self::NUMBER      => __('Número inteiro', 'morefields'),
            self::DECIMAL     => __('Número decimal', 'morefields'),
            self::URL         => __('URL', 'morefields'),
            self::DATE        => __('Data', 'morefields'),
            self::DATETIME    => __('Data e hora', 'morefields'),
            self::YESNO       => __('Sim / Não', 'morefields'),
            self::CHOICE      => __('Lista de valores (escolha única)', 'morefields'),
            self::MULTICHOICE => __('Lista de valores (múltipla escolha)', 'morefields'),
            self::GLPI_ITEM   => __('Item do GLPI', 'morefields'),
        ];
    }

    public static function icon(string $type): string
    {
        return match ($type) {
            self::TEXT        => 'ti ti-typography',
            self::TEXTAREA    => 'ti ti-align-left',
            self::NUMBER      => 'ti ti-123',
            self::DECIMAL     => 'ti ti-decimal',
            self::URL         => 'ti ti-link',
            self::DATE        => 'ti ti-calendar',
            self::DATETIME    => 'ti ti-calendar-time',
            self::YESNO       => 'ti ti-toggle-left',
            self::CHOICE      => 'ti ti-list',
            self::MULTICHOICE => 'ti ti-list-check',
            self::GLPI_ITEM   => 'ti ti-box',
            default           => 'ti ti-forms',
        };
    }

    public static function badge(string $type): string
    {
        return "<span class='badge bg-blue-lt'><i class='" . htmlescape(self::icon($type)) . " me-1'></i>"
            . htmlescape(self::all()[$type] ?? $type) . '</span>';
    }

    public static function column(string $type): string
    {
        return match ($type) {
            self::TEXT, self::URL            => 'v_string',
            self::TEXTAREA                   => 'v_text',
            self::NUMBER, self::YESNO,
            self::CHOICE, self::MULTICHOICE  => 'v_int',
            self::DECIMAL                    => 'v_decimal',
            self::DATE                       => 'v_date',
            self::DATETIME                   => 'v_datetime',
            self::GLPI_ITEM                  => 'v_items_id',
            default                          => 'v_string',
        };
    }

    public static function isMulti(string $type): bool
    {
        return $type === self::MULTICHOICE;
    }

    /**
     * Tipos de item que podem receber campos (e que podem ser alvo de um campo
     * "Item do GLPI"), agrupados para o seletor. Vêm dos registros do próprio GLPI
     * (ativos, componentes, dropdowns padrão) mais uma lista fixa de itens de
     * gerência, assistência, ferramentas e administração. Só entram classes que
     * têm tabela, formulário próprio e não são relações entre itens.
     *
     * @return array<string, array<string, string>> grupo => [itemtype => rótulo]
     */
    public static function getLinkableItemtypeGroups(): array
    {
        global $CFG_GLPI;
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $candidates = [
            __('Ativos', 'morefields')         => $CFG_GLPI['asset_types'] ?? [],
            __('Componentes', 'morefields')    => $CFG_GLPI['device_types'] ?? [],
            __('Gerência', 'morefields')       => [
                'Software', 'Line', 'Contract', 'Supplier', 'Contact', 'Budget', 'Document', 'Certificate',
                'Domain', 'Appliance', 'Cluster', 'DatabaseInstance', 'CartridgeItem', 'ConsumableItem',
                'Datacenter', 'DCRoom', 'Rack', 'Enclosure', 'PDU', 'PassiveDCEquipment', 'Cable',
            ],
            __('Assistência', 'morefields')    => ['Ticket', 'Problem', 'Change'],
            __('Ferramentas', 'morefields')    => ['Project', 'Reminder', 'RSSFeed', 'KnowbaseItem'],
            __('Administração', 'morefields')  => ['User', 'Group', 'Entity', 'Profile'],
        ];
        // Unidades físicas de cada componente (ex.: o chip SIM em si, não o seu cadastro). Têm
        // formulário próprio (item_devicesimcard.form.php etc.), onde ficam serial, ICCID, local...
        $units = [];
        foreach ($CFG_GLPI['device_types'] ?? [] as $device) {
            if (is_string($device) && class_exists('Item_' . $device)) {
                $units[] = 'Item_' . $device;
            }
        }
        $candidates[__('Componentes — unidades físicas', 'morefields')] = $units;

        // Cadastros auxiliares (tipos, modelos, categorias...) agrupados como no menu de Dropdowns do GLPI.
        foreach (\Dropdown::getStandardDropdownItemTypes() as $group => $classes) {
            $candidates[(string) $group] = array_merge($candidates[(string) $group] ?? [], array_keys($classes));
        }

        $seen   = [];
        $groups = [];
        foreach ($candidates as $group => $classes) {
            foreach ($classes as $itemtype) {
                if (!is_string($itemtype) || isset($seen[$itemtype]) || !self::isLinkableItemtype($itemtype)) {
                    continue;
                }
                $seen[$itemtype] = true;
                $groups[$group][$itemtype] = is_subclass_of($itemtype, \Item_Devices::class)
                    ? sprintf(__('%s (unidade física)', 'morefields'), getItemForItemtype(substr($itemtype, strlen('Item_')))::getTypeName(1))
                    : getItemForItemtype($itemtype)::getTypeName(1);
            }
        }
        foreach ($groups as &$items) {
            asort($items);
        }
        unset($items);

        return $cache = $groups;
    }

    /** Classe com tabela e formulário próprios (exclui relações entre itens, tarefas, acompanhamentos...). */
    private static function isLinkableItemtype(string $itemtype): bool
    {
        global $DB;

        if (!class_exists($itemtype) || !is_subclass_of($itemtype, \CommonDBTM::class)) {
            return false;
        }
        $ref = new \ReflectionClass($itemtype);
        // Relações entre itens não têm formulário próprio; a exceção são as unidades físicas
        // de componentes (Item_Devices), que têm.
        if (
            $ref->isAbstract()
            || (is_subclass_of($itemtype, \CommonDBRelation::class) && !is_subclass_of($itemtype, \Item_Devices::class))
            || (is_subclass_of($itemtype, \CommonDBConnexity::class) && !is_subclass_of($itemtype, \Item_Devices::class))
            || is_subclass_of($itemtype, \CommonITILTask::class)
        ) {
            return false;
        }
        $item = getItemForItemtype($itemtype);

        return $item instanceof \CommonDBTM && $item::getTable() !== '' && $DB->tableExists($item::getTable());
    }

    /** Lista plana itemtype => rótulo (validação e compatibilidade). */
    public static function getLinkableItemtypes(): array
    {
        $flat = [];
        foreach (self::getLinkableItemtypeGroups() as $items) {
            $flat += $items;
        }
        asort($flat);

        return $flat;
    }

    /**
     * Valores brutos vindos do formulário -> lista de escalares a gravar.
     *
     * @return array<int, int|float|string>
     */
    public static function normalize(string $type, mixed $raw): array
    {
        $list = is_array($raw) ? $raw : [$raw];
        $out  = [];
        foreach ($list as $v) {
            if ($v === null || (is_string($v) && trim($v) === '')) {
                continue;
            }
            $v = is_string($v) ? trim($v) : $v;
            switch ($type) {
                case self::NUMBER:
                case self::CHOICE:
                case self::MULTICHOICE:
                case self::GLPI_ITEM:
                    if (is_numeric($v) && (int) $v == $v && ($type === self::NUMBER || (int) $v > 0)) {
                        $out[] = (int) $v;
                    }
                    break;
                case self::YESNO:
                    if ($v === '0' || $v === '1' || $v === 0 || $v === 1) {
                        $out[] = (int) $v;
                    }
                    break;
                case self::DECIMAL:
                    $v = str_replace(',', '.', (string) $v);
                    if (is_numeric($v)) {
                        $out[] = (string) $v;
                    }
                    break;
                case self::DATE:
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v)) {
                        $out[] = (string) $v;
                    }
                    break;
                case self::DATETIME:
                    if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', (string) $v)) {
                        $out[] = str_replace('T', ' ', (string) $v);
                    }
                    break;
                case self::URL:
                    if (filter_var($v, FILTER_VALIDATE_URL) !== false) {
                        $out[] = mb_substr((string) $v, 0, 255);
                    }
                    break;
                case self::TEXT:
                    $out[] = mb_substr((string) $v, 0, 255);
                    break;
                default:
                    $out[] = (string) $v;
            }
            if (!self::isMulti($type) && $out !== []) {
                break;
            }
        }

        return $out;
    }

    /** Opções [id => nome] da lista de valores de um campo de escolha. */
    public static function getChoices(array $config, bool $only_active = true): array
    {
        $list_id = (int) ($config['choicelist'] ?? 0);

        return $list_id > 0 ? Choice::getList($list_id, $only_active) : [];
    }

    /**
     * HTML do controle de entrada. `$name` já inclui o prefixo do formulário.
     *
     * @param array<int, int|float|string> $values valores atuais
     */
    public static function renderInput(array $field, array $values, string $name): string
    {
        $type  = $field['type'];
        $first = $values[0] ?? '';
        $attrs = " data-mf-input='1'";

        switch ($type) {
            case self::TEXTAREA:
                return "<textarea class='form-control' rows='3' name='" . htmlescape($name) . "'$attrs>"
                    . htmlescape((string) $first) . '</textarea>';
            case self::NUMBER:
            case self::DECIMAL:
                $step = $type === self::NUMBER ? '1' : 'any';

                return "<input type='number' step='$step' class='form-control' name='" . htmlescape($name)
                    . "' value='" . htmlescape((string) $first) . "'$attrs>";
            case self::URL:
                return "<input type='url' class='form-control' name='" . htmlescape($name)
                    . "' value='" . htmlescape((string) $first) . "'$attrs>";
            case self::DATE:
                return (string) Html::showDateField($name, ['value' => $first ?: null, 'display' => false]);
            case self::DATETIME:
                return (string) Html::showDateTimeField($name, ['value' => $first ?: null, 'display' => false]);
            case self::YESNO:
                return (string) Dropdown::showFromArray($name, ['' => Dropdown::EMPTY_VALUE, 1 => __('Yes'), 0 => __('No')], [
                    'value' => $first === '' ? '' : (string) $first, 'display' => false, 'width' => '100%',
                ]);
            case self::CHOICE:
                return (string) Dropdown::showFromArray($name, self::getChoices($field['config']), [
                    'value' => $first, 'display' => false, 'display_emptychoice' => true, 'width' => '100%',
                ]);
            case self::MULTICHOICE:
                return (string) Dropdown::showFromArray($name, self::getChoices($field['config']), [
                    'values' => $values, 'display' => false, 'multiple' => true, 'width' => '100%',
                ]);
            case self::GLPI_ITEM:
                $itemtype = (string) ($field['config']['itemtype'] ?? '');
                if (!class_exists($itemtype)) {
                    return '';
                }

                return (string) Dropdown::show($itemtype, [
                    'name' => $name, 'value' => (int) $first, 'display' => false, 'entity' => -1, 'width' => '100%',
                ]);
            default:
                return "<input type='text' class='form-control' name='" . htmlescape($name)
                    . "' value='" . htmlescape((string) $first) . "' maxlength='255'$attrs>";
        }
    }

    /** Valores formatados para leitura (campo somente leitura, listas). */
    public static function formatValues(array $field, array $values): string
    {
        $out = [];
        foreach ($values as $v) {
            $out[] = match ($field['type']) {
                self::YESNO       => (int) $v === 1 ? __s('Yes') : __s('No'),
                self::DATE        => htmlescape(Html::convDate((string) $v)),
                self::DATETIME    => htmlescape(Html::convDateTime((string) $v)),
                self::CHOICE,
                self::MULTICHOICE => htmlescape(self::getChoices($field['config'], false)[(int) $v] ?? ''),
                self::GLPI_ITEM   => htmlescape(self::itemName((string) ($field['config']['itemtype'] ?? ''), (int) $v)),
                self::URL         => "<a href='" . htmlescape((string) $v) . "' target='_blank' rel='noopener'>" . htmlescape((string) $v) . '</a>',
                default           => nl2br(htmlescape((string) $v)),
            };
        }

        return implode(', ', $out);
    }

    /** Valores como texto puro (sem HTML), para o histórico. */
    public static function plainValues(array $field, array $values): string
    {
        $out = [];
        foreach ($values as $v) {
            $out[] = trim(html_entity_decode(strip_tags(self::formatValues($field, [$v])), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return implode(', ', array_filter($out, static fn($x) => $x !== ''));
    }

    private static function itemName(string $itemtype, int $id): string
    {
        if (!class_exists($itemtype) || $id <= 0) {
            return '';
        }

        return (string) Dropdown::getDropdownName(getTableForItemType($itemtype), $id);
    }
}
