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
 * GLPI_PLUGIN_DOC_DIR/morefields/backups, e a restauração dela.
 */
final class Backup
{
    public const DEFAULT_PATTERN = 'glpi\\_plugin\\_morefields\\_%';
    private const BATCH = 500;

    public static function dir(): string
    {
        return GLPI_PLUGIN_DOC_DIR . '/morefields/backups';
    }

    /**
     * @param string $pattern padrão LIKE das tabelas
     *
     * @return string caminho do arquivo gerado
     *
     * @throws \RuntimeException se não for possível gravar (quem chama deve abortar a operação)
     */
    public static function dump(string $pattern = self::DEFAULT_PATTERN, string $label = 'backup'): string
    {
        global $DB;

        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0o750, true) && !is_dir($dir)) {
            throw new \RuntimeException("não foi possível criar $dir");
        }
        $file = sprintf('%s/%s-%s.jsonl.gz', $dir, $label, date('Ymd-His'));
        $gz   = gzopen($file, 'wb9');
        if ($gz === false) {
            throw new \RuntimeException("não foi possível gravar $file");
        }

        try {
            $tables = array_column(iterator_to_array($DB->listTables($pattern), false), 'TABLE_NAME');
            // Primeira linha: lista de tabelas do instantâneo, inclusive as vazias, para que restaurar
            // também esvazie o que foi acrescentado nelas depois.
            if (gzwrite($gz, json_encode(['m' => ['tables' => $tables, 'plugin_version' => defined('PLUGIN_MOREFIELDS_VERSION') ? PLUGIN_MOREFIELDS_VERSION : null, 'created' => date('c')]]) . "\n") === false) {
                throw new \RuntimeException("falha ao escrever em $file");
            }
            foreach ($tables as $table) {
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

    /** Backups existentes, do mais novo para o mais antigo. @return array<int, array{name: string, size: int, time: int}> */
    public static function files(): array
    {
        $result = [];
        foreach (glob(self::dir() . '/*.jsonl.gz') ?: [] as $path) {
            $result[] = ['name' => basename($path), 'size' => (int) filesize($path), 'time' => (int) filemtime($path)];
        }
        usort($result, static fn(array $a, array $b) => $b['time'] <=> $a['time'] ?: strcmp($b['name'], $a['name']));

        return $result;
    }

    /**
     * Caminho de um backup pelo nome. Só aceita nomes simples gerados pelo plugin (sem diretórios).
     *
     * @throws \RuntimeException
     */
    public static function resolve(string $name): string
    {
        if (!preg_match('/^[a-z][a-z0-9-]*-\d{8}-\d{6}\.jsonl\.gz$/', $name)) {
            throw new \RuntimeException('nome de arquivo de backup inválido');
        }
        $path = self::dir() . '/' . $name;
        if (!is_file($path)) {
            throw new \RuntimeException('backup não encontrado');
        }

        return $path;
    }

    /**
     * Restaura um backup, SUBSTITUINDO o conteúdo das tabelas que ele contém (tabelas do plugin
     * que não estão no arquivo ficam como estão). Antes de tocar no banco o arquivo é validado
     * inteiro (formato, tabelas permitidas, colunas existentes) e, por padrão, é gerado um backup
     * de segurança. A troca acontece numa transação: se algo falhar, nada muda.
     *
     * @return array{tables: array<string, int>, safety_backup: ?string} registros restaurados por tabela
     *
     * @throws \RuntimeException
     */
    public static function restore(string $name, string $pattern = self::DEFAULT_PATTERN, bool $safety = true): array
    {
        global $DB;

        $path    = self::resolve($name);
        $allowed = array_column(iterator_to_array($DB->listTables($pattern), false), 'TABLE_NAME');

        // 1) valida o arquivo inteiro, sem tocar no banco
        $columns = [];
        $counts  = [];
        $gz      = gzopen($path, 'rb');
        if ($gz === false) {
            throw new \RuntimeException('não foi possível abrir o backup');
        }
        $line_no = 0;
        while (($line = gzgets($gz)) !== false) {
            $line_no++;
            $data = json_decode($line, true);
            if (is_array($data) && isset($data['m']['tables']) && is_array($data['m']['tables'])) {
                foreach ($data['m']['tables'] as $table) {
                    if (!is_string($table) || !in_array($table, $allowed, true)) {
                        gzclose($gz);
                        throw new \RuntimeException('o backup lista a tabela "' . (is_string($table) ? $table : '?') . '", que não existe neste banco (versão do plugin diferente?)');
                    }
                    $counts[$table] ??= 0;
                }
                continue;
            }
            if (!is_array($data) || !isset($data['t'], $data['r']) || !is_string($data['t']) || !is_array($data['r'])) {
                gzclose($gz);
                throw new \RuntimeException("linha $line_no do backup é inválida");
            }
            $table = $data['t'];
            if (!in_array($table, $allowed, true)) {
                gzclose($gz);
                throw new \RuntimeException("o backup tem a tabela \"$table\", que não existe neste banco (versão do plugin diferente?)");
            }
            $columns[$table] ??= array_keys($DB->listFields($table));
            foreach (array_keys($data['r']) as $column) {
                if (!in_array($column, $columns[$table], true)) {
                    gzclose($gz);
                    throw new \RuntimeException("o backup tem a coluna \"$column\" em \"$table\", que não existe neste banco (versão do plugin diferente?)");
                }
            }
            $counts[$table] = ($counts[$table] ?? 0) + 1;
        }
        gzclose($gz);
        if ($counts === []) {
            throw new \RuntimeException('o backup está vazio');
        }

        // 2) backup de segurança do estado atual
        $safety_file = $safety ? self::dump($pattern, 'before-restore') : null;

        // 3) troca os dados numa transação
        $DB->beginTransaction();
        try {
            foreach (array_keys($counts) as $table) {
                $DB->doQuery('DELETE FROM ' . $DB::quoteName($table));
            }
            $buffers = [];
            $flush = static function (string $table) use (&$buffers, $DB): void {
                if (empty($buffers[$table])) {
                    return;
                }
                $cols = array_keys($buffers[$table][0]);
                $rows = array_map(
                    static fn(array $r) => '(' . implode(',', array_map(
                        static fn($v) => $v === null ? 'NULL' : $DB->quote((string) $v),
                        array_values($r)
                    )) . ')',
                    $buffers[$table]
                );
                $DB->doQuery(
                    'INSERT INTO ' . $DB::quoteName($table) . ' (' . implode(',', array_map([$DB::class, 'quoteName'], $cols)) . ') VALUES ' . implode(',', $rows)
                );
                $buffers[$table] = [];
            };

            $gz = gzopen($path, 'rb');
            while (($line = gzgets($gz)) !== false) {
                $data = json_decode($line, true);
                if (isset($data['m'])) {
                    continue;
                }
                $buffers[$data['t']][] = $data['r'];
                if (count($buffers[$data['t']]) >= self::BATCH) {
                    $flush($data['t']);
                }
            }
            gzclose($gz);
            foreach (array_keys($buffers) as $table) {
                $flush($table);
            }
            $DB->commit();
        } catch (\Throwable $e) {
            try {
                $DB->rollBack();
            } catch (\Throwable) {
                // já fora da transação (isInTransaction() é privado no GLPI): nada a desfazer
            }
            throw new \RuntimeException('falha ao restaurar, nada foi alterado: ' . $e->getMessage(), 0, $e);
        }

        return ['tables' => $counts, 'safety_backup' => $safety_file];
    }
}
