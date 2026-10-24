<?php

declare(strict_types=1);

namespace Modules\Courses\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Fact: a student joined a course (paid courses start pending). Dispatched after the change commits. */
final class EnrollmentCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $enrollmentId, public readonly int $courseId, public readonly int $studentId) {}
}
