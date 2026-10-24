<?php

declare(strict_types=1);

namespace Modules\Courses\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Courses\Enums\ContentStatus;
use Modules\Courses\Enums\EnrollmentStatus;
use Modules\Courses\Events\CourseCompleted;
use Modules\Courses\Events\LessonCompleted;
use Modules\Courses\Exceptions\ContentRuleViolation;
use Modules\Courses\Models\CourseLesson;
use Modules\Courses\Models\Enrollment;
use Modules\Courses\Models\LessonProgress;
use Modules\Courses\Services\CourseProgress;

/**
 * Marks a lesson done for an enrolled student. When every required lesson is done the enrollment becomes
 * completed, once and for good, at the version of the course the student completed.
 */
final class CompleteLesson
{
    public function __construct(private readonly CourseProgress $progress) {}

    /** @return array{completed_lessons: int, total_lessons: int, course_completed: bool} */
    public function handle(Enrollment $enrollment, CourseLesson $lesson): array
    {
        $course = $lesson->subject->course;

        if (! $enrollment->grantsAccess()) {
            throw match ($enrollment->status) {
                EnrollmentStatus::Pending => ContentRuleViolation::enrollmentLocked(),
                EnrollmentStatus::Cancelled => ContentRuleViolation::enrollmentCancelled(403),
                default => ContentRuleViolation::enrollmentExpired(),
            };
        }
        abort_unless($lesson->status === ContentStatus::Published && $lesson->subject->status === ContentStatus::Published, 404);

        $ordered = $this->progress->orderedLessons($course);
        $locked = $this->progress->lockedLessonIds($course, $ordered, $this->progress->completedLessonIds($course, $enrollment->student));
        if (in_array($lesson->id, $locked, true)) {
            throw ContentRuleViolation::lessonLocked();
        }

        return DB::transaction(function () use ($enrollment, $lesson, $course, $ordered): array {
            $locked = Enrollment::query()->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            $progress = LessonProgress::query()->firstOrCreate(
                ['lesson_id' => $lesson->id, 'student_id' => $locked->student_id],
                ['completed_at' => now()],
            );
            if ($progress->wasRecentlyCreated) {
                LessonCompleted::dispatch($locked->id, $course->id, $locked->student_id, $lesson->id);
            }

            $done = $this->progress->completedLessonIds($course, $locked->student);
            $remaining = $ordered->pluck('id')->diff($done)->isNotEmpty();

            // Completion is decided here, from the course's own records, and is never revisited if the
            // course later changes.
            if (! $remaining && $locked->status === EnrollmentStatus::Active) {
                $locked->transitionTo(EnrollmentStatus::Completed);
                $locked->completed_at = now();
                $locked->completed_version = $course->version;
                $locked->save();
                CourseCompleted::dispatch($locked->id, $course->id, $locked->student_id, $course->version);
            }

            return [
                'completed_lessons' => count($done),
                'total_lessons' => $ordered->count(),
                'course_completed' => $locked->status === EnrollmentStatus::Completed,
            ];
        });
    }
}
