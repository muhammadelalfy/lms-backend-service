<?php

declare(strict_types=1);

namespace Modules\Courses\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Courses\Enums\ContentStatus;
use Modules\Courses\Models\Course;
use Modules\Courses\Models\CourseLesson;
use Modules\Courses\Models\CourseSubject;

/** Moves a course, section or lesson along draft → published → hidden → archived. */
final class ChangeContentStatus
{
    public function __construct(private readonly RecordCourseVersion $versions) {}

    public function handle(Course|CourseSubject|CourseLesson $content, ContentStatus $target, ?User $actor = null): Model
    {
        return DB::transaction(function () use ($content, $target, $actor): Model {
            $content->newQuery()->whereKey($content->getKey())->lockForUpdate()->value($content->getKeyName());
            $content->refresh();

            if ($content->status === $target) {
                return $content;
            }
            $from = $content->status;
            $content->transitionTo($target);
            $content->save();

            if ($content instanceof Course) {
                // The first publication is version 1.
                if ($target === ContentStatus::Published && ! $content->versions()->exists()) {
                    $this->versions->handle($content, 'published', $actor);
                }
            } elseif ($from === ContentStatus::Published || $target === ContentStatus::Published) {
                $course = $content instanceof CourseSubject ? $content->course : $content->subject->course;
                $this->versions->touch($course, 'content', $actor);
            }

            return $content;
        });
    }
}
