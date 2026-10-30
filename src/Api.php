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
 * API pública para outros plugins (ex.: assetprefixes) lerem e gravarem
 * valores de campos do More Fields sem conhecer as tabelas internas.
 *
 * Uso defensivo no outro plugin: class_exists(\GlpiPlugin\Morefields\Api::class).
 */
final class Api
{
    /** Tipos que aceitam receber uma string gerada por outro plugin. */
    public const TEXT_TYPES = [FieldType::TEXT, FieldType::TEXTAREA];

    /**
     * Campos de texto ativos que valem para o itemtype (em algum bloco ativo).
     *
     * @return array<int, string> id da definição => rótulo
     */
    public static function getTextFields(string $itemtype): array
    {
        global $DB;

        $result = [];
        foreach ($DB->request([
            'SELECT'     => ['d.id', 'd.label'],
            'DISTINCT'   => true,
            'FROM'       => FieldDefinition::getTable() . ' AS d',
            'INNER JOIN' => [
                'glpi_plugin_morefields_containerfields AS cf' => ['ON' => ['cf' => 'plugin_morefields_fielddefinitions_id', 'd' => 'id']],
                'glpi_plugin_morefields_containers AS c'       => ['ON' => ['cf' => 'plugin_morefields_containers_id', 'c' => 'id']],
                'glpi_plugin_morefields_containerfielditemtypes AS i' => ['ON' => ['i' => 'plugin_morefields_containerfields_id', 'cf' => 'id']],
            ],
            'WHERE'      => ['d.is_active' => 1, 'c.is_active' => 1, 'i.itemtype' => $itemtype, 'd.type' => self::TEXT_TYPES],
            'ORDER'      => 'd.label',
        ]) as $row) {
            $result[(int) $row['id']] = (string) $row['label'];
        }

        return $result;
    }

    /** Rótulo de uma definição, ou null se ela não existe mais. */
    public static function describe(int $field_id): ?string
    {
        global $DB;

        $row = $DB->request(['SELECT' => ['label'], 'FROM' => FieldDefinition::getTable(), 'WHERE' => ['id' => $field_id], 'LIMIT' => 1])->current();

        return $row === null ? null : (string) $row['label'];
    }

    /**
     * Grava um valor de texto num item. Valida o campo (existe, ativo, tipo
     * texto, vale para o itemtype) e normaliza o valor.
     *
     * @return string|null null em caso de sucesso; senão o motivo da falha
     */
    public static function setValue(string $itemtype, int $items_id, int $field_id, string $value): ?string
    {
        global $DB;

        $def = $DB->request(['FROM' => FieldDefinition::getTable(), 'WHERE' => ['id' => $field_id], 'LIMIT' => 1])->current();
        if ($def === null) {
            return "campo #$field_id do More Fields não existe (foi removido?)";
        }
        if (!$def['is_active']) {
            return "campo \"{$def['label']}\" do More Fields está inativo";
        }
        if (!in_array($def['type'], self::TEXT_TYPES, true)) {
            return "campo \"{$def['label']}\" é do tipo \"{$def['type']}\", incompatível (use texto/texto longo)";
        }
        if (!isset(self::getTextFields($itemtype)[$field_id])) {
            return "campo \"{$def['label']}\" não está configurado para $itemtype em nenhum bloco ativo";
        }

        $normalized = FieldType::normalize($def['type'], $value);
        if ($normalized === []) {
            return "valor vazio ou inválido para o campo \"{$def['label']}\"";
        }

        ValueRepository::replace($itemtype, $items_id, $field_id, $def['type'], $normalized);

        return null;
    }

    /**
     * Campos ativos de qualquer tipo que valem para o itemtype (em algum bloco ativo),
     * para importadores como o datainjection.
     *
     * @return array<int, array{label: string, type: string, config: array}> id da definição => dados
     */
    public static function getFields(string $itemtype): array
    {
        global $DB;

        $result = [];
        foreach ($DB->request([
            'SELECT'     => ['d.id', 'd.label', 'd.type', 'd.config'],
            'DISTINCT'   => true,
            'FROM'       => FieldDefinition::getTable() . ' AS d',
            'INNER JOIN' => [
                'glpi_plugin_morefields_containerfields AS cf' => ['ON' => ['cf' => 'plugin_morefields_fielddefinitions_id', 'd' => 'id']],
                'glpi_plugin_morefields_containers AS c'       => ['ON' => ['cf' => 'plugin_morefields_containers_id', 'c' => 'id']],
                'glpi_plugin_morefields_containerfielditemtypes AS i' => ['ON' => ['i' => 'plugin_morefields_containerfields_id', 'cf' => 'id']],
            ],
            'WHERE'      => ['d.is_active' => 1, 'c.is_active' => 1, 'i.itemtype' => $itemtype],
            'ORDER'      => 'd.label',
        ]) as $row) {
            $result[(int) $row['id']] = [
                'label'  => (string) $row['label'],
                'type'   => (string) $row['type'],
                'config' => json_decode((string) $row['config'], true) ?: [],
            ];
        }

        return $result;
    }

    /**
     * Converte o texto de uma planilha no valor bruto que o campo aceita:
     * escolhas e itens do GLPI são procurados pelo nome (vários separados por "|"),
     * Sim/Não viram 1/0. Datas e números seguem o formato interno do campo.
     *
     * @return array{value: mixed, error: ?string} error preenchido quando o texto não corresponde a nada
     */
    public static function convertImportValue(int $field_id, string $text): array
    {
        $def    = self::definition($field_id);
        if ($def === null) {
            return ['value' => $text, 'error' => "campo #$field_id não existe"];
        }

        $text = trim($text);
        if ($text === '') {
            return ['value' => '', 'error' => null];
        }

        $parts = FieldType::isMulti($def['type']) ? array_map('trim', explode('|', $text)) : [$text];
        $out   = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            switch ($def['type']) {
                case FieldType::CHOICE:
                case FieldType::MULTICHOICE:
                    $id = array_search(mb_strtolower($part), array_map('mb_strtolower', FieldType::getChoices($def['config'])), true);
                    if ($id === false) {
                        return ['value' => $text, 'error' => "valor \"$part\" não existe na lista do campo \"{$def['label']}\""];
                    }
                    $out[] = $id;
                    break;
                case FieldType::GLPI_ITEM:
                    $id = self::findItemId((string) ($def['config']['itemtype'] ?? ''), $part);
                    if ($id === null) {
                        return ['value' => $text, 'error' => "item \"$part\" não encontrado"];
                    }
                    $out[] = $id;
                    break;
                case FieldType::YESNO:
                    $yes = ['1', 'sim', 'yes', 's', 'y', 'true'];
                    $no  = ['0', 'não', 'nao', 'no', 'n', 'false'];
                    $key = mb_strtolower($part);
                    if (!in_array($key, array_merge($yes, $no), true)) {
                        return ['value' => $text, 'error' => "\"$part\" não é Sim/Não"];
                    }
                    $out[] = in_array($key, $yes, true) ? 1 : 0;
                    break;
                default:
                    $out[] = $part;
            }
        }

        if (FieldType::normalize($def['type'], $out) === []) {
            return ['value' => $text, 'error' => "valor \"$text\" inválido para o campo \"{$def['label']}\" ({$def['type']})"];
        }

        return ['value' => FieldType::isMulti($def['type']) ? $out : ($out[0] ?? ''), 'error' => null];
    }

    /**
     * Grava vários valores brutos (id da definição => valor) em um item já existente,
     * com a mesma validação do formulário do GLPI. Retorna os erros por campo.
     *
     * @param array<int, mixed> $answers
     *
     * @return string[]
     */
    public static function setValues(\CommonDBTM $item, array $answers): array
    {
        $writes = Saver::prepareAnswers($item, $answers);
        Saver::apply($item, $writes);

        $errors = [];
        foreach (array_diff_key($answers, $writes) as $def_id => $raw) {
            $errors[] = (self::describe((int) $def_id) ?? "#$def_id") . ': valor não aceito';
        }

        return $errors;
    }

    private static function definition(int $field_id): ?array
    {
        global $DB;

        $row = $DB->request(['FROM' => FieldDefinition::getTable(), 'WHERE' => ['id' => $field_id], 'LIMIT' => 1])->current();

        return $row === null ? null : ['label' => (string) $row['label'], 'type' => (string) $row['type'], 'config' => json_decode((string) $row['config'], true) ?: []];
    }

    private static function findItemId(string $itemtype, string $name): ?int
    {
        global $DB;

        if (!is_a($itemtype, \CommonDBTM::class, true)) {
            return null;
        }
        if (ctype_digit($name)) {
            $item = new $itemtype();

            return $item->getFromDB((int) $name) ? (int) $name : null;
        }
        $table = getTableForItemType($itemtype);
        if (!$DB->fieldExists($table, 'name')) {
            return null;
        }
        $row = $DB->request(['SELECT' => ['id'], 'FROM' => $table, 'WHERE' => ['name' => $name], 'LIMIT' => 1])->current();

        return $row === null ? null : (int) $row['id'];
    }
}
