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

/**
 * Configurações do plugin (glpi_configs, contexto "plugin:morefields").
 */
final class Settings
{
    public const CONTEXT = 'plugin:morefields';

    public static function defaults(): array
    {
        return [
            // Ao desinstalar: 1 = manter as tabelas (padrão, seguro); 0 = apagar tudo, antes gerando backup.
            'keep_data_on_uninstall' => 1,
            // 1 = exigir campos obrigatórios (do formulário principal) também em criações sem
            // formulário (API, importação). Desligado por padrão: telas que não exibem os
            // campos (ex.: interface simplificada) ficariam impedidas de criar itens.
            'require_without_form'   => 0,
        ];
    }

    public static function all(): array
    {
        $stored = Config::getConfigurationValues(self::CONTEXT, array_keys(self::defaults()));

        return array_map('intval', array_merge(self::defaults(), $stored));
    }

    public static function get(string $key): int
    {
        return self::all()[$key] ?? 0;
    }

    public static function save(array $input): void
    {
        $values = [];
        foreach (array_keys(self::defaults()) as $key) {
            if (array_key_exists($key, $input)) {
                $values[$key] = (int) (bool) $input[$key];
            }
        }
        if ($values !== []) {
            Config::setConfigurationValues(self::CONTEXT, $values);
        }
    }
}
