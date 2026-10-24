<?php

declare(strict_types=1);

namespace Modules\Courses\Actions;

use Modules\Auth\Models\User;
use Modules\Courses\DTOs\SubjectData;
use Modules\Courses\Enums\ContentStatus;
use Modules\Courses\Models\CourseSubject;

final class UpdateSubject
{
    public function __construct(private readonly RecordCourseVersion $versions) {}

    public function handle(CourseSubject $subject, SubjectData $data, ?User $actor = null): CourseSubject
    {
        $subject->update($data->attributes);
        if ($subject->status === ContentStatus::Published) {
            $this->versions->touch($subject->course, 'content', $actor);
        }

        return $subject;
    }
}
