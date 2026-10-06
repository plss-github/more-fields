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

/**
 * Cópia de segurança das tabelas do plugin em JSON Lines comprimido
 * (uma linha por registro: {"t": tabela, "r": registro}), gravada em
 * GLPI_PLUGIN_DOC_DIR/morefields/backups.
 */
final class Backup
{
    /**
     * @param string $pattern padrão LIKE das tabelas
     *
     * @return string caminho do arquivo gerado
     *
     * @throws \RuntimeException se não for possível gravar (quem chama deve abortar a operação)
     */
    public static function dump(string $pattern = 'glpi\\_plugin\\_morefields\\_%', string $label = 'backup'): string
    {
        global $DB;

        $dir = GLPI_PLUGIN_DOC_DIR . '/morefields/backups';
        if (!is_dir($dir) && !mkdir($dir, 0o750, true) && !is_dir($dir)) {
            throw new \RuntimeException("não foi possível criar $dir");
        }
        $file = sprintf('%s/%s-%s.jsonl.gz', $dir, $label, date('Ymd-His'));
        $gz   = gzopen($file, 'wb9');
        if ($gz === false) {
            throw new \RuntimeException("não foi possível gravar $file");
        }

        try {
            foreach ($DB->listTables($pattern) as $row) {
                $table = $row['TABLE_NAME'];
                $last  = 0;
                do {
                    $rows = iterator_to_array($DB->request(['FROM' => $table, 'WHERE' => ['id' => ['>', $last]], 'ORDER' => 'id', 'LIMIT' => 5000]), false);
                    foreach ($rows as $record) {
                        if (gzwrite($gz, json_encode(['t' => $table, 'r' => $record], JSON_UNESCAPED_UNICODE) . "\n") === false) {
                            throw new \RuntimeException("falha ao escrever em $file");
                        }
                        $last = (int) $record['id'];
                    }
                } while (count($rows) === 5000);
            }
        } finally {
            gzclose($gz);
        }

        return $file;
    }
}
