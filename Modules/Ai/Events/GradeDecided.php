<?php

declare(strict_types=1);

namespace Modules\Ai\Events;

/**
 * A teacher decided written answers (one, or several by bulk accept); dispatched after the transaction
 * committed. `reviewedSessionIds` are the sessions that became fully reviewed (their draft result exists).
 */
final class GradeDecided
{
    /**
     * @param  list<int>  $gradeIds
     * @param  list<int>  $reviewedSessionIds
     */
    public function __construct(public readonly array $gradeIds, public readonly int $decidedBy, public readonly array $reviewedSessionIds) {}
}
