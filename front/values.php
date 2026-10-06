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

use GlpiPlugin\Morefields\Saver;

Session::checkLoginUser();

$itemtype = (string) ($_POST['itemtype'] ?? '');
$items_id = (int) ($_POST['items_id'] ?? 0);

if (!is_a($itemtype, CommonDBTM::class, true)) {
    throw new \Glpi\Exception\Http\BadRequestHttpException();
}

$item = new $itemtype();
if (!$item->getFromDB($items_id)) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}
if (!$item->canUpdateItem()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$posted = is_array($_POST['_morefields'] ?? null) ? $_POST['_morefields'] : [];
$result = Saver::prepare($item, $posted);

if ($result['errors'] !== []) {
    Saver::reportErrors($result['errors']);
} else {
    Saver::apply($item, $result['writes']);
    Session::addMessageAfterRedirect(__s('Item successfully updated'), false, INFO);
}

Html::back();
