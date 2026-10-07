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
    public const OPTION_SETTINGS = 'settings';

    public static function getMenuName()
    {
        return __('More Fields', 'morefields');
    }

    public static function getIcon()
    {
        return 'ti ti-forms';
    }

    /**
     * Opção do menu (e da barra de abas) à qual pertence uma classe do plugin: é o que as
     * páginas passam ao Html::header() para o GLPI montar a barra de navegação.
     */
    public static function optionFor(string $class): string
    {
        return match ($class) {
            FieldDefinition::class           => 'fielddefinition',
            ChoiceList::class, Choice::class => 'choicelist',
            default                          => 'container', // Container, ContainerField, VisibilityRule
        };
    }

    /** @return array<string, array{title: string, page: string, icon: string, class: ?class-string}> */
    private static function pages(): array
    {
        $web = PLUGIN_MOREFIELDS_WEBDIR . '/front/';

        return [
            'container'            => ['title' => Container::getTypeName(2), 'page' => $web . 'container.php', 'icon' => 'ti ti-layout-list', 'class' => Container::class],
            'fielddefinition'      => ['title' => FieldDefinition::getTypeName(2), 'page' => $web . 'fielddefinition.php', 'icon' => 'ti ti-forms', 'class' => FieldDefinition::class],
            'choicelist'           => ['title' => ChoiceList::getTypeName(2), 'page' => $web . 'choicelist.php', 'icon' => 'ti ti-list-details', 'class' => ChoiceList::class],
            self::OPTION_SETTINGS  => ['title' => __('Configurações', 'morefields'), 'page' => $web . 'settings.php', 'icon' => 'ti ti-settings', 'class' => null],
        ];
    }

    /** Uma única entrada no menu "Configurar"; as páginas do plugin se alcançam pela barra de abas (renderSubNav). */
    public static function getMenuContent()
    {
        $options = [];
        foreach (self::pages() as $key => $page) {
            $options[$key] = [
                'title' => $page['title'],
                'page'  => $page['page'],
                'icon'  => $page['icon'],
            ];
            if ($page['class'] !== null) {
                $options[$key]['links'] = ['add' => $page['class']::getFormURL(false), 'search' => $page['page']];
            }
        }

        return [
            'title'   => self::getMenuName(),
            'page'    => $options['container']['page'],
            'icon'    => self::getIcon(),
            'options' => $options,
        ];
    }

    /**
     * Barra de abas no topo das páginas do plugin (Blocos · Campos · Listas · Configurações).
     *
     * @param string $active chave da página atual (ver optionFor())
     */
    public static function renderSubNav(string $active): void
    {
        echo '<ul class="nav nav-tabs mb-3 mf-subnav">';
        foreach (self::pages() as $key => $page) {
            echo '<li class="nav-item"><a class="nav-link' . ($key === $active ? ' active' : '') . '" href="' . htmlescape($page['page']) . '">'
                . '<i class="' . htmlescape($page['icon']) . ' me-1"></i>' . htmlescape($page['title']) . '</a></li>';
        }
        echo '</ul>';
    }
}
