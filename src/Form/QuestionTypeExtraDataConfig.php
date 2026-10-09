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

use Glpi\DBAL\JsonFieldInterface;
use Override;

/** Configuração gravada na pergunta: qual campo do More Fields ela preenche. */
final class QuestionTypeExtraDataConfig implements JsonFieldInterface
{
    // Nome fixo usado na serialização
    public const FIELD_ID = 'field_id';

    public function __construct(private readonly ?int $field_id = null) {}

    #[Override]
    public static function jsonDeserialize(array $data): self
    {
        return new self(isset($data[self::FIELD_ID]) ? (int) $data[self::FIELD_ID] : null);
    }

    #[Override]
    public function jsonSerialize(): array
    {
        return [self::FIELD_ID => $this->field_id];
    }

    public function getFieldId(): ?int
    {
        return $this->field_id;
    }
}
