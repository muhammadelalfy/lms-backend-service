<?php

declare(strict_types=1);

namespace Modules\Courses\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Courses\DTOs\CourseData;
use Modules\Courses\Models\Course;
use Modules\Courses\Services\CourseSlugs;
use Modules\Courses\Support\CoursePricing;

final class CreateCourse
{
    public function __construct(private readonly CourseSlugs $slugs, private readonly SyncInstructors $instructors) {}

    public function handle(User $actor, CourseData $data): Course
    {
        return DB::transaction(function () use ($actor, $data): Course {
            $attributes = CoursePricing::settle($data->attributes);
            $course = Course::create($attributes + ['slug' => $this->slugs->uniqueFrom($attributes['title']), 'created_by' => $actor->id]);

            // A teacher who creates a course teaches it. An admin who creates one does not (only teachers can
            // be instructors); they name who teaches it, now or later.
            $this->instructors->handle($course, $data->instructorIds ?? ($actor->isAnyRole('teacher') ? [$actor->id] : []));

            return $course;
        });
    }
}
