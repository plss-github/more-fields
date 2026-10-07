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
 * Acesso à tabela única de valores.
 */
final class ValueRepository
{
    public const TABLE = 'glpi_plugin_morefields_values';

    /** @return array<int, string> def_id => tipo */
    public static function definitionTypes(): array
    {
        global $DB;
        static $types = null;
        if ($types === null) {
            $types = [];
            foreach ($DB->request(['SELECT' => ['id', 'type'], 'FROM' => FieldDefinition::getTable()]) as $row) {
                $types[(int) $row['id']] = $row['type'];
            }
        }

        return $types;
    }

    /** @return array<int, array<int, int|float|string>> def_id => valores */
    public static function load(string $itemtype, int $items_id): array
    {
        global $DB;

        $types  = self::definitionTypes();
        $result = [];
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id], 'ORDER' => 'id']) as $row) {
            $fid = (int) $row['plugin_morefields_fielddefinitions_id'];
            if (!isset($types[$fid])) {
                continue;
            }
            $value = $row[FieldType::column($types[$fid])];
            if ($value === null) {
                continue;
            }
            if (FieldType::isMulti($types[$fid])) {
                $result[$fid][] = $value;
            } else {
                // Campo de valor único: se houver linhas duplicadas (ex.: importação por SQL),
                // vale a mais recente (a consulta vem ordenada por id).
                $result[$fid] = [$value];
            }
        }

        return $result;
    }

    /**
     * Substitui os valores de um campo no item. Retorna true se mudou algo.
     *
     * @param array<int, int|float|string> $values
     */
    public static function replace(string $itemtype, int $items_id, int $def_id, string $type, array $values): bool
    {
        global $DB;

        // Trava por (item, campo): duas gravações simultâneas esperam uma pela outra
        // em vez de duplicar linhas ou perder valor (a tabela não tem chave única,
        // pois campos de múltipla escolha têm várias linhas).
        $lock    = 'mf_' . md5($itemtype . '#' . $items_id . '#' . $def_id);
        $locked  = (int) ($DB->doQuery('SELECT GET_LOCK(' . $DB->quote($lock) . ', 10)')->fetch_row()[0] ?? 0) === 1;
        $DB->beginTransaction(); // aninhada (savepoint) se o chamador já tem transação

        try {
            $old = self::load($itemtype, $items_id)[$def_id] ?? [];
            if (array_map('strval', $old) === array_map('strval', $values)) {
                $DB->commit();

                return false;
            }
            $old_text = self::plain($def_id, $type, $old);

            $DB->delete(self::TABLE, [
                'itemtype' => $itemtype,
                'items_id' => $items_id,
                'plugin_morefields_fielddefinitions_id' => $def_id,
            ]);
            $column = FieldType::column($type);
            foreach ($values as $value) {
                $DB->insert(self::TABLE, [
                    'itemtype' => $itemtype,
                    'items_id' => $items_id,
                    'plugin_morefields_fielddefinitions_id' => $def_id,
                    $column => $value,
                ]);
            }
            $DB->commit();
        } catch (\Throwable $e) {
            try {
                $DB->rollBack();
            } catch (\Throwable) {
                // já fora da transação (isInTransaction() é privado no GLPI): nada a desfazer
            }
            throw $e;
        } finally {
            if ($locked) {
                $DB->doQuery('SELECT RELEASE_LOCK(' . $DB->quote($lock) . ')');
            }
        }

        self::logChange($itemtype, $items_id, $def_id, $old_text, self::plain($def_id, $type, $values));

        return true;
    }

    /** Copia todos os valores de um item para outro (clone, modelo, transferência). */
    public static function copyItem(string $src_type, int $src_id, string $dst_type, int $dst_id): int
    {
        global $DB;

        $types  = self::definitionTypes();
        $copied = 0;
        // Campos marcados como "não copiar ao clonar" (ex.: identificadores únicos).
        $skip = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => FieldDefinition::getTable(), 'WHERE' => ['copy_on_clone' => 0]]) as $row) {
            $skip[(int) $row['id']] = true;
        }
        foreach (self::load($src_type, $src_id) as $def_id => $values) {
            if (isset($skip[(int) $def_id])) {
                continue;
            }
            if (self::replace($dst_type, $dst_id, (int) $def_id, $types[(int) $def_id], $values)) {
                $copied++;
            }
        }

        return $copied;
    }

    /** Valor em texto puro (para o histórico), usando a configuração do campo. */
    private static function plain(int $def_id, string $type, array $values): string
    {
        global $DB;

        $row    = $DB->request(['SELECT' => ['config'], 'FROM' => FieldDefinition::getTable(), 'WHERE' => ['id' => $def_id], 'LIMIT' => 1])->current();
        $config = json_decode((string) ($row['config'] ?? ''), true) ?: [];

        return FieldType::plainValues(['type' => $type, 'config' => $config], $values);
    }

    /**
     * Registra a mudança no histórico do item (aba "Histórico"), como o GLPI
     * faz com campos nativos: coluna = opção de busca do campo, de -> para.
     * Só para itens que mantêm histórico.
     */
    private static function logChange(string $itemtype, int $items_id, int $def_id, string $old, string $new): void
    {
        $item = getItemForItemtype($itemtype);
        if (!$item instanceof \CommonDBTM || !$item->dohistory || $old === $new) {
            return;
        }
        \Log::history($items_id, $itemtype, [FieldDefinition::SEARCH_OPTION_BASE + $def_id, $old, $new]);
    }

    public static function purgeItem(string $itemtype, int $items_id): void
    {
        global $DB;
        $DB->delete(self::TABLE, ['itemtype' => $itemtype, 'items_id' => $items_id]);
    }
}
