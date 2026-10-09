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

use Glpi\Form\QuestionType\QuestionTypeCategoryInterface;
use Override;

/** Categoria "More Fields" no seletor de tipos de pergunta do editor de formulários. */
final class QuestionTypeCategory implements QuestionTypeCategoryInterface
{
    #[Override]
    public function getLabel(): string
    {
        return __('More Fields', 'morefields');
    }

    #[Override]
    public function getIcon(): string
    {
        return 'ti ti-forms';
    }

    #[Override]
    public function getWeight(): int
    {
        return 1000;
    }
}
