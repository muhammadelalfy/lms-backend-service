<?php

declare(strict_types=1);

namespace Modules\Courses\Actions;

use Modules\Auth\Models\User;
use Modules\Courses\Enums\ContentStatus;
use Modules\Courses\Models\Course;
use Modules\Courses\Models\CourseVersion;

/**
 * A live course never changes silently: its first publication is version 1, and every later change to what
 * learners see makes a new version with a snapshot. Completions record the version they were made against.
 */
final class RecordCourseVersion
{
    /** Records a new version when the course is live (published, or hidden with students still in it). */
    public function touch(Course $course, string $reason, ?User $actor = null): void
    {
        if (in_array($course->status, [ContentStatus::Published, ContentStatus::Hidden], true)) {
            $this->handle($course, $reason, $actor);
        }
    }

    public function handle(Course $course, string $reason, ?User $actor = null): CourseVersion
    {
        $number = $course->versions()->exists() ? $course->version + 1 : 1;

        $version = CourseVersion::create([
            'course_id' => $course->id,
            'number' => $number,
            'reason' => $reason,
            'snapshot' => $this->snapshot($course),
            'created_by' => $actor?->id,
            'created_at' => now(),
        ]);
        $course->forceFill(['version' => $number])->saveQuietly();

        return $version;
    }

    /** @return array{outline: list<array<string, mixed>>, rules: array<string, mixed>, required_lesson_ids: list<int>} */
    private function snapshot(Course $course): array
    {
        $outline = $course->subjects()->with('lessons')->get()->map(fn ($subject) => [
            'id' => $subject->id,
            'title' => $subject->title,
            'status' => $subject->status->value,
            'lessons' => $subject->lessons->map(fn ($lesson) => ['id' => $lesson->id, 'title' => $lesson->title, 'status' => $lesson->status->value])->all(),
        ])->all();

        $required = collect($outline)->where('status', ContentStatus::Published->value)
            ->flatMap(fn (array $subject) => collect($subject['lessons'])->where('status', ContentStatus::Published->value)->pluck('id'))
            ->map(fn ($id) => (int) $id)->values()->all();

        return [
            'outline' => $outline,
            // Completing the course means these lessons, as they were in this version.
            'required_lesson_ids' => $required,
            'rules' => [
                'sequencing_mode' => $course->sequencing_mode->value,
                'outline_style' => $course->outline_style->value,
                'access_days' => $course->access_days,
                'pricing' => $course->pricing->value,
            ],
        ];
    }
}
