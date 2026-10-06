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
use Log;
use Session;

/**
 * Base dos itens de administração do plugin. O direito `config` do GLPI só
 * tem os bits READ/UPDATE, então mapeamos todas as operações neles.
 *
 * Histórico: classes com aba "Histórico" (bloco, campo, lista) usam
 * `$dohistory = true`. Itens filhos (campo do bloco, regra, valor de lista)
 * sobrescrevem historyParent() e registram no histórico do pai.
 */
abstract class AdminItem extends CommonDBTM
{
    public static $rightname = 'config';

    public static function canCreate(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    public static function canView(): bool
    {
        return Session::haveRight('config', READ);
    }

    public static function canUpdate(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    public static function canDelete(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    public static function canPurge(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    /** @return array{0: class-string<CommonDBTM>, 1: int}|null classe e id do item pai */
    protected function historyParent(): ?array
    {
        return null;
    }

    /** Texto do item filho exibido no histórico do pai. */
    protected function historyLabel(): string
    {
        return (string) $this->getNameID();
    }

    protected function logOnParent(int $action, string $label = ''): void
    {
        $parent = $this->historyParent();
        if ($parent === null || $parent[1] <= 0) {
            return;
        }
        Log::history($parent[1], $parent[0], [0, '', $label !== '' ? $label : $this->historyLabel()], static::class, $action);
    }

    public function post_addItem()
    {
        $this->logOnParent(Log::HISTORY_ADD_SUBITEM);
    }

    public function post_updateItem($history = true)
    {
        $changed = array_values(array_filter((array) $this->updates, static fn($f) => $f !== 'date_mod'));
        if ($changed === []) {
            return;
        }
        $this->logOnParent(Log::HISTORY_UPDATE_SUBITEM, $this->historyLabel() . ' (' . implode(', ', $changed) . ')');
    }

    public function post_purgeItem()
    {
        $this->logOnParent(Log::HISTORY_DELETE_SUBITEM);
    }
}
