<?php

declare(strict_types=1);

namespace Modules\Ai\DTOs;

use Illuminate\Http\UploadedFile;
use Modules\Ai\Enums\ExcelMode;

final readonly class UploadMaterialData
{
    public function __construct(public UploadedFile $file, public ?string $title, public ExcelMode $excelMode) {}

    public static function fromValidated(array $validated): self
    {
        return new self(
            $validated['file'],
            isset($validated['title']) ? trim($validated['title']) : null,
            ExcelMode::tryFrom((string) ($validated['excel_mode'] ?? '')) ?? ExcelMode::Auto,
        );
    }
}
