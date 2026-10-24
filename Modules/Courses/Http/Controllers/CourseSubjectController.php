<?php

declare(strict_types=1);

namespace Modules\Courses\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Courses\Actions\AddSubject;
use Modules\Courses\Actions\ChangeContentStatus;
use Modules\Courses\Actions\DeleteContent;
use Modules\Courses\Actions\ReorderContent;
use Modules\Courses\Actions\UpdateSubject;
use Modules\Courses\DTOs\SubjectData;
use Modules\Courses\Http\Requests\ChangeStatusRequest;
use Modules\Courses\Http\Requests\CourseStaffRequest;
use Modules\Courses\Http\Requests\ReorderRequest;
use Modules\Courses\Http\Requests\SubjectRequest;
use Modules\Courses\Models\Course;
use Modules\Courses\Models\CourseSubject;

/** The sections of a course. */
class CourseSubjectController extends Controller
{
    public function store(SubjectRequest $request, Course $course, AddSubject $add)
    {
        return response()->json($this->shape($add->handle($course, SubjectData::fromValidated($request->validated()))), 201);
    }

    public function update(SubjectRequest $request, CourseSubject $subject, UpdateSubject $update)
    {
        return $this->shape($update->handle($subject, SubjectData::fromValidated($request->validated()), $request->user())->fresh());
    }

    public function destroy(CourseStaffRequest $request, CourseSubject $subject, DeleteContent $delete)
    {
        $delete->handle($subject);

        return response()->noContent();
    }

    public function status(ChangeStatusRequest $request, CourseSubject $subject, ChangeContentStatus $change)
    {
        return $this->shape($change->handle($subject, $request->target(), $request->user()));
    }

    public function order(ReorderRequest $request, Course $course, ReorderContent $reorder)
    {
        $reorder->handle($course->subjects(), $request->validated('ids'), $request->user());

        return response()->noContent();
    }

    private function shape(CourseSubject $subject): array
    {
        return ['id' => $subject->id, 'course_id' => $subject->course_id, 'title' => $subject->title, 'description' => $subject->description,
            'position' => $subject->position, 'status' => $subject->status->value];
    }
}
