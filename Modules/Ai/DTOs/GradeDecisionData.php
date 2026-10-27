<?php

declare(strict_types=1);

namespace Modules\Ai\DTOs;

use Modules\Ai\Enums\GradeDecision;

/** A teacher's decision on one written answer, as sent. */
final class GradeDecisionData
{
    public function __construct(
        public readonly GradeDecision $decision,
        public readonly ?float $finalScore,
        public readonly ?string $finalFeedback,
        public readonly bool $feedbackSent,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(
            GradeDecision::from((string) $validated['decision']),
            isset($validated['final_score']) ? (float) $validated['final_score'] : null,
            isset($validated['final_feedback']) ? trim((string) $validated['final_feedback']) : null,
            array_key_exists('final_feedback', $validated),
        );
    }
}
