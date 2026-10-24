<?php

declare(strict_types=1);

namespace Modules\Courses\Actions;

use Modules\Auth\Models\User;
use Modules\Courses\DTOs\LessonData;
use Modules\Courses\Enums\ContentStatus;
use Modules\Courses\Models\CourseLesson;

final class UpdateLesson
{
    public function __construct(private readonly RecordCourseVersion $versions) {}

    public function handle(CourseLesson $lesson, LessonData $data, ?User $actor = null): CourseLesson
    {
        $lesson->update($data->attributes);
        if ($lesson->status === ContentStatus::Published) {
            $this->versions->touch($lesson->subject->course, 'content', $actor);
        }

        return $lesson;
    }
}
