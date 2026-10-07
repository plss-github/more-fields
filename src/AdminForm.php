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

use Html;
use Session;

/**
 * Tratador genérico dos `front/*.form.php` (add / update / purge / exibição).
 */
final class AdminForm
{
    /**
     * @param class-string<AdminItem> $class
     * @param bool $is_child true para itens editados dentro de abas do pai:
     *                       após a ação volta para a página anterior.
     */
    public static function handle(string $class, bool $is_child = false): void
    {
        Session::checkRight('config', READ);

        $obj = new $class();

        if (isset($_POST['add'])) {
            $obj->check(-1, CREATE, $_POST);
            $id = $obj->add($_POST);
            if ($id && !$is_child && ($_SESSION['glpibackcreated'] ?? false)) {
                Html::redirect($obj->getLinkURL());
            }
            Html::back();
        } elseif (isset($_POST['update'])) {
            $obj->check($_POST['id'], UPDATE);
            $obj->update($_POST);
            Html::back();
        } elseif (isset($_POST['purge'])) {
            $obj->check($_POST['id'], PURGE);
            $obj->delete($_POST, true);
            if ($is_child) {
                Html::back();
            }
            Html::redirect($class::getSearchURL());
        }

        Html::header($class::getTypeName(Session::getPluralNumber()), $_SERVER['PHP_SELF'], 'config', Menu::class, Menu::optionFor($class));
        Menu::renderSubNav(Menu::optionFor($class));
        $obj->display(['id' => (int) ($_GET['id'] ?? 0)]);
        Html::footer();
    }

    /**
     * @param class-string<AdminItem> $class
     */
    public static function list(string $class, string $menu_option): void
    {
        Session::checkRight('config', READ);
        Html::header($class::getTypeName(Session::getPluralNumber()), $_SERVER['PHP_SELF'], 'config', Menu::class, Menu::optionFor($class));
        Menu::renderSubNav(Menu::optionFor($class));
        \Search::show($class);
        Html::footer();
    }
}
