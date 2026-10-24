<?php

declare(strict_types=1);

namespace Modules\Courses\Services;

use Illuminate\Support\Collection;
use Modules\Auth\Models\User;
use Modules\Courses\Enums\ContentStatus;
use Modules\Courses\Enums\SequencingMode;
use Modules\Courses\Models\Course;
use Modules\Courses\Models\CourseLesson;
use Modules\Courses\Models\LessonProgress;
use Modules\Students\Models\Student;

/** Reading how far a student has got: the lessons in order, which are done, and which are not open yet. */
final class CourseProgress
{
    /** The student a signed-in student or parent acts for. */
    public function studentOf(User $user): ?Student
    {
        return $user->isAnyRole('student', 'parent') ? $user->studentAccount?->student : null;
    }

    /** The published lessons of published sections, in the order a student meets them. */
    public function orderedLessons(Course $course): Collection
    {
        $published = ContentStatus::Published->value;

        return CourseLesson::query()
            ->select('course_lessons.*')
            ->join('course_subjects', 'course_subjects.id', '=', 'course_lessons.subject_id')
            ->where('course_subjects.course_id', $course->id)
            ->where('course_subjects.status', $published)
            ->where('course_lessons.status', $published)
            ->orderBy('course_subjects.position')->orderBy('course_subjects.id')
            ->orderBy('course_lessons.position')->orderBy('course_lessons.id')
            ->get();
    }

    /** @return list<int> */
    public function completedLessonIds(Course $course, Student $student): array
    {
        return LessonProgress::query()
            ->where('student_id', $student->id)
            ->whereIn('lesson_id', $this->orderedLessons($course)->pluck('id'))
            ->pluck('lesson_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Lessons a student cannot open yet: with "after the previous one" sequencing, everything after the
     * first lesson they have not completed.
     *
     * @param  list<int>  $completed
     * @return list<int>
     */
    public function lockedLessonIds(Course $course, Collection $orderedLessons, array $completed): array
    {
        if ($course->sequencing_mode === SequencingMode::Open) {
            return [];
        }

        $locked = [];
        $previousDone = true;
        foreach ($orderedLessons as $lesson) {
            if (! $previousDone) {
                $locked[] = $lesson->id;
            }
            if (! in_array($lesson->id, $completed, true)) {
                $previousDone = false;
            }
        }

        return $locked;
    }
}
