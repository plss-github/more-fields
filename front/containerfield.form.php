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

use GlpiPlugin\Morefields\AdminForm;
use GlpiPlugin\Morefields\ContainerField;

// "Salvar tudo" da aba Campos do bloco: grava todas as linhas de uma vez.
if (isset($_POST['update_all'])) {
    Session::checkRight('config', UPDATE);
    try {
        $changed = ContainerField::updateMany(
            (int) ($_POST['plugin_morefields_containers_id'] ?? 0),
            is_array($_POST['rows'] ?? null) ? $_POST['rows'] : []
        );
        Session::addMessageAfterRedirect(
            $changed > 0
                ? sprintf(_n('%d campo alterado.', '%d campos alterados.', $changed, 'morefields'), $changed)
                : __s('Nenhuma alteração para salvar.', 'morefields'),
            false,
            INFO
        );
    } catch (\RuntimeException $e) {
        Session::addMessageAfterRedirect(__s('Nada foi salvo: ', 'morefields') . htmlescape($e->getMessage()), false, ERROR);
    }
    Html::back();
}

AdminForm::handle(ContainerField::class, true);
