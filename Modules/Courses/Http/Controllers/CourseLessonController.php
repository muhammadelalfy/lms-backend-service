<?php

declare(strict_types=1);

namespace Modules\Courses\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Courses\Actions\AddLesson;
use Modules\Courses\Actions\ChangeContentStatus;
use Modules\Courses\Actions\DeleteContent;
use Modules\Courses\Actions\ReorderContent;
use Modules\Courses\Actions\UpdateLesson;
use Modules\Courses\DTOs\LessonData;
use Modules\Courses\Http\Requests\ChangeStatusRequest;
use Modules\Courses\Http\Requests\CourseStaffRequest;
use Modules\Courses\Http\Requests\LessonRequest;
use Modules\Courses\Http\Requests\ReorderRequest;
use Modules\Courses\Models\CourseLesson;
use Modules\Courses\Models\CourseSubject;

/** The lessons of a section, including live ones that meet by a Meet or Zoom link. */
class CourseLessonController extends Controller
{
    public function store(LessonRequest $request, CourseSubject $subject, AddLesson $add)
    {
        return response()->json($this->shape($add->handle($subject, LessonData::fromValidated($request->validated()))), 201);
    }

    public function update(LessonRequest $request, CourseLesson $lesson, UpdateLesson $update)
    {
        return $this->shape($update->handle($lesson, LessonData::fromValidated($request->validated()), $request->user())->fresh());
    }

    public function destroy(CourseStaffRequest $request, CourseLesson $lesson, DeleteContent $delete)
    {
        $delete->handle($lesson);

        return response()->noContent();
    }

    public function status(ChangeStatusRequest $request, CourseLesson $lesson, ChangeContentStatus $change)
    {
        return $this->shape($change->handle($lesson, $request->target(), $request->user()));
    }

    public function order(ReorderRequest $request, CourseSubject $subject, ReorderContent $reorder)
    {
        $reorder->handle($subject->lessons(), $request->validated('ids'), $request->user());

        return response()->noContent();
    }

    private function shape(CourseLesson $lesson): array
    {
        return ['id' => $lesson->id, 'subject_id' => $lesson->subject_id, 'title' => $lesson->title, 'position' => $lesson->position,
            'status' => $lesson->status->value, 'live_at' => $lesson->live_at?->toIso8601String(), 'live_provider' => $lesson->live_provider,
            'live_link' => $lesson->live_link, 'video_url' => $lesson->video_url];
    }
}
