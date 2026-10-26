<?php

declare(strict_types=1);

namespace Modules\Exams\DTOs;

use Modules\Exams\Enums\ExamResultStatus;

/** The fields of an exam result a request sets (only the ones it sent, for an update). */
final class ExamResultData
{
    public const FIELDS = ['student_id', 'title', 'score', 'max_score', 'taken_at'];

    /** @param array<string, mixed> $values */
    public function __construct(public readonly array $values, public readonly ExamResultStatus $status = ExamResultStatus::Published) {}

    /**
     * A create without `status` publishes at once: the published mobile app records results this way
     * and expects the student to be told; new clients send `status: draft` to review first.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            array_intersect_key($validated, array_flip(self::FIELDS)),
            ExamResultStatus::tryFrom((string) ($validated['status'] ?? '')) ?? ExamResultStatus::Published,
        );
    }
}
