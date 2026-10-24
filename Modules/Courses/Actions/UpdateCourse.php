<?php

declare(strict_types=1);

namespace Modules\Courses\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Models\User;
use Modules\Courses\DTOs\CourseData;
use Modules\Courses\Enums\OutlineStyle;
use Modules\Courses\Models\Course;
use Modules\Courses\Support\CoursePricing;

final class UpdateCourse
{
    /** Changing any of these changes what learners experience, so a live course gets a new version. */
    private const LEARNER_FACING = ['title', 'description', 'sequencing_mode', 'outline_style', 'access_days'];

    public function __construct(private readonly RecordCourseVersion $versions) {}

    public function handle(Course $course, CourseData $data, ?User $actor = null): Course
    {
        return DB::transaction(function () use ($course, $data, $actor): Course {
            $course->newQuery()->whereKey($course->getKey())->lockForUpdate()->value('id');
            $course->refresh();

            $attributes = CoursePricing::settle($data->attributes, $course);
            if (($attributes['outline_style'] ?? null) === OutlineStyle::Lessons->value && $course->subjects()->count() > 1) {
                throw ValidationException::withMessages(['outline_style' => 'لا يصلح مخطط الدروس المباشر لدورة فيها أكثر من قسم.']);
            }

            $course->fill($attributes);
            $changed = array_keys($course->getDirty());
            $course->save();

            if (array_intersect($changed, self::LEARNER_FACING)) {
                $this->versions->touch($course, 'settings', $actor);
            }

            return $course;
        });
    }
}
