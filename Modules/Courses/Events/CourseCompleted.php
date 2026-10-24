<?php

declare(strict_types=1);

namespace Modules\Courses\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fact: a student completed a course, at a given version of it. Later changes to the course never undo
 * this; a certificate refers to the version in this event. Dispatched after the change commits.
 */
final class CourseCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $enrollmentId, public readonly int $courseId, public readonly int $studentId, public readonly int $courseVersion) {}
}
