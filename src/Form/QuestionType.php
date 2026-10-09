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

namespace GlpiPlugin\Morefields\Form;

use Glpi\Application\View\TemplateRenderer;
use Glpi\DBAL\JsonFieldInterface;
use Glpi\Form\Condition\ConditionValueTransformerInterface;
use Glpi\Form\Question;
use Glpi\Form\QuestionType\AbstractQuestionType;
use Glpi\Form\QuestionType\QuestionTypeCategoryInterface;
use GlpiPlugin\Morefields\FieldType;
use Override;

/**
 * Pergunta de formulário (Formulários do GLPI) que preenche um campo do More Fields: quem
 * responde vê o controle certo do tipo do campo (texto, data, lista, usuário...).
 * A resposta vira o valor do campo no chamado, mudança ou problema que o formulário gerar
 * (ver DestinationField).
 */
final class QuestionType extends AbstractQuestionType implements ConditionValueTransformerInterface
{
    /** Tipos de item que um formulário pode gerar. */
    public const DESTINATION_ITEMTYPES = ['Ticket', 'Change', 'Problem'];

    #[Override]
    public function getCategory(): QuestionTypeCategoryInterface
    {
        return new QuestionTypeCategory();
    }

    #[Override]
    public function getName(): string
    {
        return __('Campo do More Fields', 'morefields');
    }

    #[Override]
    public function getIcon(): string
    {
        return 'ti ti-forms';
    }

    #[Override]
    public function getExtraDataConfigClass(): string
    {
        return QuestionTypeExtraDataConfig::class;
    }

    #[Override]
    public function validateExtraDataInput(array $input): bool
    {
        return isset($input[QuestionTypeExtraDataConfig::FIELD_ID])
            && is_numeric($input[QuestionTypeExtraDataConfig::FIELD_ID])
            && isset(self::getAvailableFields()[(int) $input[QuestionTypeExtraDataConfig::FIELD_ID]]);
    }

    /**
     * Campos ativos que valem para chamado, mudança ou problema (em algum bloco ativo).
     *
     * @return array<int, string> id da definição => rótulo
     */
    public static function getAvailableFields(): array
    {
        global $DB;
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $cache = [];
        try {
            if (!$DB->tableExists('glpi_plugin_morefields_containerfielditemtypes')) {
                return $cache;
            }
            foreach ($DB->request([
                'SELECT'     => ['d.id', 'd.label'],
                'DISTINCT'   => true,
                'FROM'       => 'glpi_plugin_morefields_fielddefinitions AS d',
                'INNER JOIN' => [
                    'glpi_plugin_morefields_containerfields AS cf'        => ['ON' => ['cf' => 'plugin_morefields_fielddefinitions_id', 'd' => 'id']],
                    'glpi_plugin_morefields_containers AS c'              => ['ON' => ['cf' => 'plugin_morefields_containers_id', 'c' => 'id']],
                    'glpi_plugin_morefields_containerfielditemtypes AS i' => ['ON' => ['i' => 'plugin_morefields_containerfields_id', 'cf' => 'id']],
                ],
                'WHERE'      => ['d.is_active' => 1, 'c.is_active' => 1, 'i.itemtype' => self::DESTINATION_ITEMTYPES],
                'ORDER'      => 'd.label',
            ]) as $row) {
                $cache[(int) $row['id']] = (string) $row['label'];
            }
        } catch (\Throwable) {
            // banco indisponível (instalação/atualização): sem tipo de pergunta
        }

        return $cache;
    }

    public static function hasAvailableFields(): bool
    {
        return self::getAvailableFields() !== [];
    }

    /** Id do campo configurado na pergunta (ou null). */
    public function getFieldId(?Question $question): ?int
    {
        if (!$question instanceof Question) {
            return null;
        }
        /** @var ?QuestionTypeExtraDataConfig $config */
        $config = $this->getExtraDataConfig(\json_decode((string) ($question->fields['extra_data'] ?? ''), true) ?: []);

        return $config?->getFieldId();
    }

    /** Definição do campo no formato que FieldType espera, ou null se não existe/inativo. */
    private function getField(?int $field_id): ?array
    {
        global $DB;

        if ($field_id === null) {
            return null;
        }
        $row = $DB->request(['FROM' => 'glpi_plugin_morefields_fielddefinitions', 'WHERE' => ['id' => $field_id, 'is_active' => 1], 'LIMIT' => 1])->current();
        if ($row === null) {
            return null;
        }

        return [
            'id'     => (int) $row['id'],
            'type'   => $row['type'],
            'label'  => $row['label'],
            'config' => \json_decode((string) $row['config'], true) ?: [],
        ];
    }

    /**
     * O GLPI usa esta tradução para decidir se uma pergunta obrigatória foi respondida (e nas
     * condições). Em lista, múltipla escolha e item do GLPI o "vazio" é enviado como id 0, que o
     * GLPI trataria como resposta: aqui ele vira vazio. Em número, o 0 continua sendo uma resposta.
     */
    #[Override]
    public function transformConditionValueForComparisons(mixed $value, ?JsonFieldInterface $question_config): string|array
    {
        $field    = $question_config instanceof QuestionTypeExtraDataConfig ? $this->getField($question_config->getFieldId()) : null;
        $zero_off = $field !== null && in_array($field['type'], [FieldType::CHOICE, FieldType::MULTICHOICE, FieldType::GLPI_ITEM], true);
        $blank    = static fn(string $v): bool => $v === '' || ($zero_off && $v === '0');

        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value), static fn(string $v) => !$blank($v)));
        }
        $text = (string) ($value ?? '');

        return $blank($text) ? '' : $text;
    }

    #[Override]
    public function renderAdministrationTemplate(?Question $question): string
    {
        $available = self::getAvailableFields();
        $selected  = $this->getFieldId($question);
        if ($selected === null || !isset($available[$selected])) {
            $selected = (int) array_key_first($available);
        }
        $field = $this->getField($selected);

        return TemplateRenderer::getInstance()->render('@morefields/form/question_administration.html.twig', [
            'question'          => $question,
            'available_fields'  => $available,
            'selected_field_id' => $selected,
            'type_label'        => $field !== null ? (FieldType::all()[$field['type']] ?? $field['type']) : '',
        ]);
    }

    #[Override]
    public function renderEndUserTemplate(Question $question): string
    {
        $field = $this->getField($this->getFieldId($question));
        if ($field === null) {
            return '<div class="text-muted">' . __s('Campo indisponível.', 'morefields') . '</div>';
        }

        return FieldType::renderInput($field, [], $question->getEndUserInputName());
    }

    #[Override]
    public function formatRawAnswer(mixed $answer, Question $question): string
    {
        $field = $this->getField($this->getFieldId($question));
        if ($field === null) {
            return '';
        }

        return FieldType::plainValues($field, FieldType::normalize($field['type'], $answer));
    }
}
