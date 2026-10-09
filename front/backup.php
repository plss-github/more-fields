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

// Download de um backup (arquivo .jsonl.gz). Só nomes simples gerados pelo plugin.
Session::checkRight('config', UPDATE);

try {
    $path = Backup::resolve((string) ($_GET['file'] ?? ''));
} catch (\RuntimeException) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

header('Content-Type: application/gzip');
header('Content-Disposition: attachment; filename="' . basename($path) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
