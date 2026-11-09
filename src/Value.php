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

use CommonDBTM;

/**
 * Classe da tabela de valores (`glpi_plugin_morefields_values`). Só existe para o GLPI
 * resolver essa tabela para um itemtype do plugin: é assim que ele chama o gancho
 * MassiveActionsFieldsDisplay ao montar a ação massiva "Atualizar". Gravação e leitura
 * continuam em ValueRepository.
 */
final class Value extends CommonDBTM
{
    public static function getTypeName($nb = 0)
    {
        return _n('Valor', 'Valores', $nb, 'morefields');
    }
}
