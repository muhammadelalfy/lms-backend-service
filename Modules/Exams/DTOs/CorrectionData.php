<?php

declare(strict_types=1);

namespace Modules\Exams\DTOs;

/** An audited correction: why, and the new values of the fields it sets. */
final class CorrectionData
{
    /** @param array<string, mixed> $changes */
    public function __construct(public readonly string $reason, public readonly array $changes) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(trim((string) $validated['reason']), array_intersect_key($validated, array_flip(ExamResultData::FIELDS)));
    }
}
