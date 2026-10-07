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

use GlpiPlugin\Morefields\Container;
use GlpiPlugin\Morefields\Menu;
use GlpiPlugin\Morefields\VisibilityRule;

Session::checkRight('config', READ);

$rule = new VisibilityRule();

/** Volta para a aba de regras do bloco (e não para a página anterior). */
$back_to = static fn(int $container_id) => Container::getFormURLWithID($container_id, true)
    . '&forcetab=' . rawurlencode(VisibilityRule::class . '$1');

if (isset($_POST['add'])) {
    $rule->check(-1, CREATE, $_POST);
    $rule->add($_POST);
    Html::redirect($back_to((int) ($_POST['plugin_morefields_containers_id'] ?? 0)));
} elseif (isset($_POST['update']) || isset($_POST['purge'])) {
    $rule->check((int) $_POST['id'], isset($_POST['purge']) ? PURGE : UPDATE);
    $container_id = (int) $rule->fields['plugin_morefields_containers_id'];
    if (isset($_POST['purge'])) {
        $rule->delete($_POST, true);
    } else {
        $rule->update($_POST);
    }
    Html::redirect($back_to($container_id));
}

Html::header(VisibilityRule::getTypeName(1), $_SERVER['PHP_SELF'], 'config', Menu::class, Menu::optionFor(VisibilityRule::class));
Menu::renderSubNav(Menu::optionFor(VisibilityRule::class));

$id = (int) ($_GET['id'] ?? 0);
if ($id > 0) {
    $rule->display(['id' => $id]);
} else {
    // Regra nova: renderizada direto (sem abas AJAX), que não recebem o bloco de origem.
    $container_id = (int) ($_GET['plugin_morefields_containers_id'] ?? 0);
    if ($container_id <= 0 || !(new Container())->getFromDB($container_id)) {
        throw new \Glpi\Exception\Http\BadRequestHttpException();
    }
    $rule->check(-1, CREATE);
    $rule->showForm(0, ['plugin_morefields_containers_id' => $container_id]);
}

Html::footer();
