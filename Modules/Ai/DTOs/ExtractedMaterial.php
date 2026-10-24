<?php

declare(strict_types=1);

namespace Modules\Ai\DTOs;

/** What the AI service read out of a file (POST /v1/materials/extract). */
final readonly class ExtractedMaterial
{
    /**
     * @param  list<ExtractedSection>  $sections
     * @param  list<string>  $warnings
     */
    public function __construct(
        public ?int $pages,
        public int $charCount,
        public bool $needsTextReview,
        public array $warnings,
        public array $sections,
    ) {}
}
