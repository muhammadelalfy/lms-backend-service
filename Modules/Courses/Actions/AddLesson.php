<?php

declare(strict_types=1);

namespace Modules\Courses\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Courses\DTOs\LessonData;
use Modules\Courses\Models\CourseLesson;
use Modules\Courses\Models\CourseSubject;

final class AddLesson
{
    public function handle(CourseSubject $subject, LessonData $data): CourseLesson
    {
        return DB::transaction(function () use ($subject, $data): CourseLesson {
            $subject->newQuery()->whereKey($subject->getKey())->lockForUpdate()->value('id');

            $lesson = $subject->lessons()->make($data->attributes);
            $lesson->position = (int) $subject->lessons()->max('position') + 1;
            $lesson->save();

            return $lesson;
        });
    }
}
