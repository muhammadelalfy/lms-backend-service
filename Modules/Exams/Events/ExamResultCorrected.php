<?php

declare(strict_types=1);

namespace Modules\Exams\Events;

/**
 * A published result was corrected (one audit group); dispatched after the transaction committed.
 * `studentIds` holds the student before and after (they differ when the result moved to another student).
 */
final class ExamResultCorrected
{
    /** @param list<int> $studentIds */
    public function __construct(
        public readonly int $resultId,
        public readonly string $correctionGroupId,
        public readonly int $correctedBy,
        public readonly array $studentIds,
    ) {}
}
