<?php

declare(strict_types=1);

namespace Modules\Courses\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Fact: an enrollment ran past its access period. Dispatched after the change commits. */
final class EnrollmentExpired implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $enrollmentId, public readonly int $courseId, public readonly int $studentId) {}
}
