<?php

declare(strict_types=1);

namespace Modules\Courses\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Fact: a student finished a lesson for the first time. Dispatched after the change commits. */
final class LessonCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $enrollmentId, public readonly int $courseId, public readonly int $studentId, public readonly int $lessonId) {}
}
