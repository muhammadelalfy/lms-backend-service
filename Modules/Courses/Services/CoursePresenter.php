<?php

declare(strict_types=1);

namespace Modules\Courses\Services;

use Modules\Courses\Enums\ContentStatus;
use Modules\Courses\Enums\EnrollmentStatus;
use Modules\Courses\Models\Course;
use Modules\Courses\Models\CourseLesson;
use Modules\Courses\Models\CourseSubject;
use Modules\Courses\Models\CourseVersion;
use Modules\Courses\Models\Enrollment;

/** The shapes the API returns: what staff see, what the public catalog shows, and what an enrolled student gets. */
final class CoursePresenter
{
    public static function pricing(Course $course): array
    {
        return [
            'model' => $course->pricing->value,
            'price_amount' => $course->price_amount,
            'discounted_price_amount' => $course->discounted_price_amount,
            'final_price_amount' => $course->finalPrice(),
        ];
    }

    public static function summary(Course $course): array
    {
        return [
            'id' => $course->id,
            'slug' => $course->slug,
            'kind' => $course->kind->value,
            'title' => $course->title,
            'summary' => $course->summary,
            'status' => $course->status->value,
            'is_featured' => $course->is_featured,
            'pricing' => self::pricing($course),
            'starts_at' => $course->starts_at?->toIso8601String(),
            'ends_at' => $course->ends_at?->toIso8601String(),
            'estimated_duration_minutes' => $course->estimated_duration_minutes,
            'published_lessons_count' => (int) ($course->published_lessons_count ?? 0),
            'instructors' => $course->relationLoaded('instructors')
                ? $course->instructors->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()->all()
                : [],
        ];
    }

    private static function details(Course $course): array
    {
        return self::summary($course) + [
            'description' => $course->description,
            'goals' => $course->goals ?? [],
            'target_audience' => $course->target_audience,
            'season_name' => $course->season_name,
            'version' => $course->version,
            'access_days' => $course->access_days,
            'sequencing_mode' => $course->sequencing_mode->value,
            'outline_style' => $course->outline_style->value,
        ];
    }

    /**
     * Where a student stands in a course. An active enrollment past its end reads as expired even before the
     * scheduled job has recorded it, so what the student sees matches what they can open.
     */
    public static function enrollment(?Enrollment $enrollment): ?array
    {
        if (! $enrollment) {
            return null;
        }
        $status = $enrollment->status === EnrollmentStatus::Active && ! $enrollment->grantsAccess() ? EnrollmentStatus::Expired : $enrollment->status;

        return [
            'status' => $status->value,
            'payment_id' => $enrollment->payment_id,
            'amount' => $enrollment->payment?->amount,
            'expires_at' => $enrollment->expires_at?->toIso8601String(),
            'completed_at' => $enrollment->completed_at?->toIso8601String(),
            'course_version' => $enrollment->course_version,
            'completed_version' => $enrollment->completed_version,
        ];
    }

    public static function version(CourseVersion $version): array
    {
        $outline = collect($version->snapshot['outline'] ?? []);

        return [
            'number' => $version->number,
            'reason' => $version->reason,
            'created_at' => $version->created_at->toIso8601String(),
            'author' => $version->author?->name,
            'sections' => $outline->where('status', ContentStatus::Published->value)->count(),
            'lessons' => count($version->snapshot['required_lesson_ids'] ?? []),
        ];
    }

    /** Everything about a course for the people who run it, drafts included. */
    public static function staff(Course $course): array
    {
        return self::details($course) + [
            'visible_from' => $course->visible_from?->toIso8601String(),
            'visible_until' => $course->visible_until?->toIso8601String(),
            'published_at' => $course->published_at?->toIso8601String(),
            'subjects' => $course->subjects->map(fn (CourseSubject $subject) => [
                'id' => $subject->id,
                'title' => $subject->title,
                'description' => $subject->description,
                'position' => $subject->position,
                'status' => $subject->status->value,
                'lessons' => $subject->lessons->map(fn (CourseLesson $lesson) => self::lesson($lesson, protected: true))->all(),
            ])->all(),
        ];
    }

    /**
     * Published sections and lessons only. The video and live-join link are the course's paid content, so
     * they are included only when `$protected` says this reader has access.
     *
     * @param  list<int>|null  $completed  lesson ids the student finished (null on the public page)
     * @param  list<int>  $locked  lessons not open yet because of sequencing
     */
    public static function outline(Course $course, bool $protected, ?array $completed = null, array $locked = []): array
    {
        $subjects = $course->subjects
            ->where('status', ContentStatus::Published)
            ->map(function (CourseSubject $subject) use ($protected, $completed, $locked) {
                $lessons = $subject->lessons->where('status', ContentStatus::Published)->map(function (CourseLesson $lesson) use ($protected, $completed, $locked) {
                    $isLocked = in_array($lesson->id, $locked, true);

                    return self::lesson($lesson, protected: $protected && ! $isLocked) + ($completed === null ? [] : [
                        'completed' => in_array($lesson->id, $completed, true),
                        'locked' => $isLocked,
                    ]);
                })->values()->all();

                return ['id' => $subject->id, 'title' => $subject->title, 'description' => $subject->description, 'lessons' => $lessons];
            })
            ->filter(fn (array $subject) => $subject['lessons'] !== [])
            ->values()->all();

        return self::details($course) + ['subjects' => $subjects];
    }

    private static function lesson(CourseLesson $lesson, bool $protected): array
    {
        $shape = [
            'id' => $lesson->id,
            'title' => $lesson->title,
            'description' => $lesson->description,
            'summary' => $lesson->summary,
            'key_points' => $lesson->key_points ?? [],
            'estimated_duration_minutes' => $lesson->estimated_duration_minutes,
            'position' => $lesson->position,
            'status' => $lesson->status->value,
            'is_live' => $lesson->live_at !== null,
            'live_at' => $lesson->live_at?->toIso8601String(),
            'has_video' => $lesson->video_url !== null,
        ];

        return $protected ? $shape + ['video_url' => $lesson->video_url, 'live_provider' => $lesson->live_provider, 'live_link' => $lesson->live_link] : $shape;
    }
}
