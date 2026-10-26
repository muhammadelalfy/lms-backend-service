<?php

declare(strict_types=1);

namespace Modules\Ai\DTOs;

final readonly class ExtractedSection
{
    public function __construct(public string $kind, public string $label, public int $ordinal, public string $text) {}

    public function charCount(): int
    {
        return mb_strlen($this->text);
    }
}
