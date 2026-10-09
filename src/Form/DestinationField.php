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
use Glpi\Form\AnswersSet;
use Glpi\Form\Destination\AbstractConfigField;
use Glpi\Form\Destination\CommonITILField\Category;
use Glpi\Form\Destination\CommonITILField\SimpleValueConfig;
use Glpi\Form\Destination\FormDestination;
use Glpi\Form\Form;
use Glpi\Form\Question;
use InvalidArgumentException;
use Override;

/**
 * Campo de configuração do destino (chamado, mudança ou problema gerado pelo formulário):
 * quando ligado, a resposta de cada pergunta "Campo do More Fields" vira o valor do campo.
 * As respostas seguem em `_morefields_answers` e são gravadas quando o item é criado
 * (ver Injector).
 */
final class DestinationField extends AbstractConfigField
{
    #[Override]
    public function getLabel(): string
    {
        return __('Campos adicionais (More Fields)', 'morefields');
    }

    #[Override]
    public function getConfigClass(): string
    {
        return SimpleValueConfig::class;
    }

    #[Override]
    public function renderConfigForm(
        Form $form,
        FormDestination $destination,
        JsonFieldInterface $config,
        string $input_name,
        array $display_options,
    ): string {
        if (!$config instanceof SimpleValueConfig) {
            throw new InvalidArgumentException('Unexpected config class');
        }

        return TemplateRenderer::getInstance()->render('@morefields/form/destination_field.html.twig', [
            'value'      => $config->getValue(),
            'input_name' => $input_name . '[' . SimpleValueConfig::VALUE . ']',
            'options'    => $display_options,
        ]);
    }

    #[Override]
    public function applyConfiguratedValueToInputUsingAnswers(
        JsonFieldInterface $config,
        array $input,
        AnswersSet $answers_set,
    ): array {
        if (!$config instanceof SimpleValueConfig) {
            throw new InvalidArgumentException('Unexpected config class');
        }
        if (!(bool) $config->getValue()) {
            return $input;
        }

        $type = new QuestionType();
        foreach ($answers_set->getAnswersByTypes([QuestionType::class]) as $answer) {
            $question = Question::getById($answer->getQuestionId());
            if (!$question instanceof Question) {
                continue;
            }
            $field_id = $type->getFieldId($question);
            if ($field_id !== null) {
                $input['_morefields_answers'][$field_id] = $answer->getRawAnswer();
            }
        }

        return $input;
    }

    #[Override]
    public function getDefaultConfig(Form $form): SimpleValueConfig
    {
        return new SimpleValueConfig('1');
    }

    #[Override]
    public function getWeight(): int
    {
        return 1000;
    }

    #[Override]
    public function getCategory(): Category
    {
        return Category::PROPERTIES;
    }
}
