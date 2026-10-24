<?php

declare(strict_types=1);

namespace Modules\Ai\Enums;

enum MaterialKind: string
{
    case Pdf = 'pdf';
    case Docx = 'docx';
    case Xlsx = 'xlsx';

    public function mime(): string
    {
        return match ($this) {
            self::Pdf => 'application/pdf',
            self::Docx => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }
}
