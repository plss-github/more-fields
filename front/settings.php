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

use GlpiPlugin\Morefields\Backup;
use GlpiPlugin\Morefields\Integrity;
use GlpiPlugin\Morefields\Menu;
use GlpiPlugin\Morefields\Settings;

Session::checkRight('config', READ);

if (isset($_POST['save'])) {
    Session::checkRight('config', UPDATE);
    Settings::save(['keep_data_on_uninstall' => $_POST['keep_data_on_uninstall'] ?? 1, 'require_without_form' => $_POST['require_without_form'] ?? 0]);
    Session::addMessageAfterRedirect(__s('Configurações salvas.', 'morefields'), false, INFO);
    Html::back();
}
if (isset($_POST['fix'])) {
    Session::checkRight('config', UPDATE);
    $removed = Integrity::fix();
    Session::addMessageAfterRedirect(sprintf(__s('Integridade corrigida: %d registro(s) removido(s).', 'morefields'), array_sum($removed)), false, INFO);
    Html::back();
}
if (isset($_POST['backup'])) {
    Session::checkRight('config', UPDATE);
    try {
        $file = Backup::dump('glpi\\_plugin\\_morefields\\_%', 'manual');
        Session::addMessageAfterRedirect(sprintf(__s('Backup gerado: %s', 'morefields'), htmlescape($file)), false, INFO);
    } catch (\Throwable $e) {
        Session::addMessageAfterRedirect(htmlescape($e->getMessage()), false, ERROR);
    }
    Html::back();
}

Html::header(__('Configurações', 'morefields'), $_SERVER['PHP_SELF'], 'config', Menu::class, 'settings');

$cfg      = Settings::all();
$can_edit = Session::haveRight('config', UPDATE);
$dis      = $can_edit ? '' : ' disabled';
$action   = PLUGIN_MOREFIELDS_WEBDIR . '/front/settings.php';
$token    = Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

echo "<div class='container-fluid' style='max-width:62rem'>";

// ---- comportamento
echo "<form method='post' action='" . htmlescape($action) . "' class='card mb-4'>";
echo "<div class='card-header'><h3 class='card-title'><i class='ti ti-settings me-2'></i>" . __s('Comportamento', 'morefields') . '</h3></div><div class="card-body">';
echo "<div class='mb-4'><label class='form-label fw-bold'>" . __s('Ao desinstalar o plugin', 'morefields') . '</label>';
echo "<select name='keep_data_on_uninstall' class='form-select' style='max-width:34rem'$dis>";
echo "<option value='1'" . ($cfg['keep_data_on_uninstall'] ? ' selected' : '') . '>' . __s('Manter os dados (recomendado)', 'morefields') . '</option>';
echo "<option value='0'" . ($cfg['keep_data_on_uninstall'] ? '' : ' selected') . '>' . __s('Apagar tudo (gera um backup antes)', 'morefields') . '</option></select>';
echo "<div class='form-text'>" . __s('Mantendo, as tabelas continuam no banco e uma reinstalação reaproveita campos, regras e valores. Apagando, o backup fica em files/_plugins/morefields/backups e a desinstalação é cancelada se o backup falhar.', 'morefields') . '</div></div>';
echo "<div class='mb-2'><label class='form-check form-switch'><input type='hidden' name='require_without_form' value='0'>"
    . "<input class='form-check-input' type='checkbox' name='require_without_form' value='1'" . ($cfg['require_without_form'] ? ' checked' : '') . "$dis>"
    . "<span class='form-check-label fw-bold'>" . __s('Exigir campos obrigatórios também em criações sem formulário (API, importação)', 'morefields') . '</span></label>';
echo "<div class='form-text'>" . __s('Vale só para campos do formulário principal. Deixe desligado se algum caminho de criação não mostra os campos (por exemplo, a interface simplificada): ele ficaria impedido de criar itens.', 'morefields') . '</div></div>';
echo '</div>';
if ($can_edit) {
    echo "<div class='card-footer text-end'><button class='btn btn-primary' name='save' type='submit'><i class='ti ti-device-floppy me-1'></i>" . __s('Save') . '</button></div>';
}
echo $token . '</form>';

// ---- integridade
$problems = Integrity::problems();
$total    = array_sum(array_column($problems, 'count'));
echo "<div class='card mb-4'><div class='card-header'><h3 class='card-title'><i class='ti ti-shield-check me-2'></i>" . __s('Integridade dos dados', 'morefields') . '</h3></div>';
echo "<div class='card-body'><table class='table table-sm align-middle mb-0'><tbody>";
foreach ($problems as $problem) {
    echo '<tr><td>' . htmlescape($problem['label']) . "</td><td class='text-end'>"
        . ($problem['count'] > 0 ? "<span class='badge bg-orange-lt'>" . (int) $problem['count'] . '</span>' : "<span class='badge bg-green-lt'><i class='ti ti-check'></i></span>") . '</td></tr>';
}
echo '</tbody></table>';
echo "<div class='form-text mt-2'>" . __s('Sobras de exclusões, valores duplicados (por concorrência ou importação por SQL) e valores que apontam para itens ou opções que já não existem. Corrigir remove esses registros; em duplicados, mantém o mais recente.', 'morefields') . '</div></div>';
if ($can_edit) {
    echo "<div class='card-footer d-flex gap-2 justify-content-end'>";
    echo "<form method='post' action='" . htmlescape($action) . "' class='d-inline'>$token<button class='btn btn-outline-secondary' name='backup' type='submit'><i class='ti ti-download me-1'></i>" . __s('Gerar backup agora', 'morefields') . '</button></form>';
    if ($total > 0) {
        echo "<form method='post' action='" . htmlescape($action) . "' class='d-inline' onsubmit=\"return confirm('" . __s('Remover os registros listados? Recomendado gerar um backup antes.', 'morefields') . "')\">$token<button class='btn btn-warning' name='fix' type='submit'><i class='ti ti-tool me-1'></i>" . sprintf(__s('Corrigir (%d)', 'morefields'), $total) . '</button></form>';
    }
    echo '</div>';
}
echo '</div></div>';

Html::footer();
