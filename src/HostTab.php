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

use CommonDBTM;
use CommonGLPI;

/**
 * Abas dos campos com local "Aba". Campos do mesmo bloco com o mesmo nome de
 * aba ficam juntos; sem nome, usam o nome do bloco.
 */
class HostTab extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return __('More Fields', 'morefields');
    }

    /**
     * O núcleo converte o id da aba para inteiro, então a chave é o menor id
     * de campo do grupo (bloco + nome da aba).
     *
     * @return array<int, array{container: array, label: string}> chave da aba => dados
     */
    private static function tabsFor(CommonDBTM $item): array
    {
        $entity = $item->isEntityAssign() ? $item->getEntityID() : null;
        $tabs   = [];
        foreach (Binding::getContainers($item::class, ContainerField::PLACEMENT_TAB, $entity) as $cid => $container) {
            $groups = [];
            foreach (Binding::getContainerFields($cid, ContainerField::PLACEMENT_TAB, null, $container, $item::class) as $cf_id => $field) {
                $label = Binding::tabLabel($field, $container);
                $groups[$label] = min($groups[$label] ?? $cf_id, $cf_id);
            }
            foreach ($groups as $label => $key) {
                $tabs[$key] = ['container' => $container, 'label' => (string) $label];
            }
        }

        return $tabs;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof CommonDBTM || $item->isNewItem()) {
            return '';
        }
        $names = [];
        foreach (self::tabsFor($item) as $key => $tab) {
            if (!Renderer::isOmitted($tab['container'], $item)) {
                $names[$key] = self::createTabEntry($tab['label'], 0, null, 'ti ti-forms');
            }
        }

        return $names;
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof CommonDBTM) {
            return false;
        }
        $tabs = self::tabsFor($item);
        if (isset($tabs[(int) $tabnum])) {
            echo Renderer::section($tabs[(int) $tabnum]['container'], $item, ContainerField::PLACEMENT_TAB, $tabs[(int) $tabnum]['label']);
        }

        return true;
    }
}
