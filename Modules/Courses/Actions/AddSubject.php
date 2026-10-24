<?php

declare(strict_types=1);

namespace Modules\Courses\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Courses\DTOs\SubjectData;
use Modules\Courses\Enums\OutlineStyle;
use Modules\Courses\Exceptions\ContentRuleViolation;
use Modules\Courses\Models\Course;
use Modules\Courses\Models\CourseSubject;

final class AddSubject
{
    public function handle(Course $course, SubjectData $data): CourseSubject
    {
        return DB::transaction(function () use ($course, $data): CourseSubject {
            $course->newQuery()->whereKey($course->getKey())->lockForUpdate()->value('id');
            $course->refresh();

            if ($course->outline_style === OutlineStyle::Lessons && $course->subjects()->count() >= 1) {
                throw ContentRuleViolation::singleSubjectOutline();
            }

            $subject = $course->subjects()->make($data->attributes);
            $subject->position = (int) $course->subjects()->max('position') + 1;
            $subject->save();

            return $subject;
        });
    }
}
