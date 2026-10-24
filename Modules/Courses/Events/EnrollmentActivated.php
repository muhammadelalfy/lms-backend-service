<?php

declare(strict_types=1);

namespace Modules\Courses\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Fact: an enrollment now gives access (free at once, paid when the payment is paid, or renewed). Dispatched after the change commits. */
final class EnrollmentActivated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $enrollmentId, public readonly int $courseId, public readonly int $studentId) {}
}
