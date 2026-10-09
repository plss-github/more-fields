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

use Glpi\Plugin\Hooks;
use GlpiPlugin\Morefields\Binding;
use GlpiPlugin\Morefields\Injector;
use GlpiPlugin\Morefields\Menu;

define('PLUGIN_MOREFIELDS_VERSION', '1.2.0');
define('PLUGIN_MOREFIELDS_MIN_GLPI', '11.0.0');
define('PLUGIN_MOREFIELDS_MAX_GLPI', '11.0.99');

global $CFG_GLPI;
define('PLUGIN_MOREFIELDS_WEBDIR', ($CFG_GLPI['root_doc'] ?? '') . '/plugins/morefields');

function plugin_init_morefields()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['morefields'] = true;

    if (!Plugin::isPluginActive('morefields')) {
        return;
    }

    plugin_morefields_register_form_types();

    // Hooks de gravação: fora do bloco de sessão para valer também via API.
    foreach (Binding::getBoundItemtypes() as $itemtype) {
        $PLUGIN_HOOKS[Hooks::PRE_ITEM_ADD]['morefields'][$itemtype]    = [Injector::class, 'preItemAdd'];
        $PLUGIN_HOOKS[Hooks::ITEM_ADD]['morefields'][$itemtype]        = [Injector::class, 'itemAdd'];
        $PLUGIN_HOOKS[Hooks::PRE_ITEM_UPDATE]['morefields'][$itemtype] = [Injector::class, 'preItemUpdate'];
        $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['morefields'][$itemtype]      = [Injector::class, 'itemPurge'];
    }
    $PLUGIN_HOOKS[Hooks::ITEM_TRANSFER]['morefields'] = [Injector::class, 'itemTransfer'];

    if (Session::getLoginUserID()) {
        $PLUGIN_HOOKS[Hooks::POST_ITEM_FORM]['morefields'] = [Injector::class, 'postItemForm'];

        $bound = Binding::getBoundItemtypes('tab');
        if ($bound !== []) {
            Plugin::registerClass(\GlpiPlugin\Morefields\HostTab::class, ['addtabon' => $bound]);
        }

        $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['morefields'][] = 'public/js/morefields.js';
        $PLUGIN_HOOKS[Hooks::ADD_CSS]['morefields'][]        = 'public/css/morefields.css';

        if (Session::haveRight('config', READ)) {
            $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['morefields'] = 'front/container.php';
            $PLUGIN_HOOKS[Hooks::MENU_TOADD]['morefields']  = ['config' => Menu::class];
        }
    }
}

/**
 * Integração com os Formulários do GLPI (tipo de pergunta + campo de destino). Só é registrada
 * se o GLPI tem a API de plugins dos Formulários e há campos que valem para chamado, mudança ou
 * problema (sem isso a pergunta não teria o que oferecer).
 */
function plugin_morefields_register_form_types(): void
{
    if (
        !class_exists(\Glpi\Form\QuestionType\QuestionTypesManager::class)
        || !class_exists(\Glpi\Form\Destination\FormDestinationManager::class)
        || !\GlpiPlugin\Morefields\Form\QuestionType::hasAvailableFields()
    ) {
        return;
    }

    $types = \Glpi\Form\QuestionType\QuestionTypesManager::getInstance();
    $dest  = \Glpi\Form\Destination\FormDestinationManager::getInstance();
    if (!method_exists($types, 'registerPluginQuestionType') || !method_exists($dest, 'registerPluginCommonITILConfigField')) {
        return;
    }

    $types->registerPluginCategory(new \GlpiPlugin\Morefields\Form\QuestionTypeCategory());
    $types->registerPluginQuestionType(new \GlpiPlugin\Morefields\Form\QuestionType());

    foreach ([
        new \Glpi\Form\Destination\FormDestinationTicket(),
        new \Glpi\Form\Destination\FormDestinationChange(),
        new \Glpi\Form\Destination\FormDestinationProblem(),
    ] as $destination) {
        $dest->registerPluginCommonITILConfigField(
            $destination::class,
            new \GlpiPlugin\Morefields\Form\DestinationField(),
        );
    }
}

function plugin_version_morefields()
{
    return [
        'name'         => 'More Fields',
        'version'      => PLUGIN_MOREFIELDS_VERSION,
        'author'       => 'Matheus Schmidt',
        'license'      => 'GPLv2+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_MOREFIELDS_MIN_GLPI,
                'max' => PLUGIN_MOREFIELDS_MAX_GLPI,
            ],
            'php'  => ['min' => '8.2'],
        ],
    ];
}
