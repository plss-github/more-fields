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

class Menu extends CommonGLPI
{
    public static function getMenuName()
    {
        return __('More Fields', 'morefields');
    }

    public static function getIcon()
    {
        return 'ti ti-forms';
    }

    public static function getMenuContent()
    {
        $web = PLUGIN_MOREFIELDS_WEBDIR . '/front/';

        $entry = static fn(string $class, string $page, string $icon) => [
            'title' => $class::getTypeName(2),
            'page'  => $web . $page . '.php',
            'icon'  => $icon,
            'links' => ['add' => $class::getFormURL(false), 'search' => $web . $page . '.php'],
        ];

        return [
            'title'   => self::getMenuName(),
            'page'    => $web . 'container.php',
            'icon'    => self::getIcon(),
            'options' => [
                'container'       => $entry(Container::class, 'container', 'ti ti-layout-list'),
                'fielddefinition' => $entry(FieldDefinition::class, 'fielddefinition', 'ti ti-forms'),
                'choicelist'      => $entry(ChoiceList::class, 'choicelist', 'ti ti-list-details'),
                'settings'        => [
                    'title' => __('Configurações', 'morefields'),
                    'page'  => $web . 'settings.php',
                    'icon'  => 'ti ti-settings',
                ],
            ],
        ];
    }
}
