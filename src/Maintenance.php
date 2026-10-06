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

use Config;
use Toolbox;

final class Maintenance
{
    /**
     * Desinstalação. Por padrão MANTÉM os dados (as tabelas continuam e uma
     * reinstalação os reaproveita). No modo "apagar tudo" gera antes o backup e
     * só apaga se o backup foi gravado.
     *
     * @param string|null $pattern padrão LIKE das tabelas (só para testes)
     */
    public static function uninstall(?string $pattern = null, ?bool $keep = null): bool
    {
        global $DB;

        $pattern ??= 'glpi\\_plugin\\_morefields\\_%';
        $keep    ??= (bool) Settings::get('keep_data_on_uninstall');

        if ($keep) {
            Toolbox::logInFile('morefields', "desinstalação: dados mantidos (tabelas {$pattern} preservadas)\n");

            return true;
        }

        try {
            $file = Backup::dump($pattern, 'uninstall');
        } catch (\Throwable $e) {
            Toolbox::logInFile('morefields', 'desinstalação ABORTADA, backup falhou: ' . $e->getMessage() . "\n");

            return false;
        }

        foreach ($DB->listTables($pattern) as $row) {
            $DB->dropTable($row['TABLE_NAME']);
        }
        Config::deleteConfigurationValues(Settings::CONTEXT);
        Toolbox::logInFile('morefields', "desinstalação: dados apagados; backup em {$file}\n");

        return true;
    }
}
