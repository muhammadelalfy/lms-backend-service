<?php

declare(strict_types=1);

namespace Modules\Courses\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Courses\Actions\CancelEnrollment;
use Modules\Courses\Actions\ChangeContentStatus;
use Modules\Courses\Actions\CreateCourse;
use Modules\Courses\Actions\DeleteContent;
use Modules\Courses\Actions\SyncInstructors;
use Modules\Courses\Actions\UpdateCourse;
use Modules\Courses\DTOs\CourseData;
use Modules\Courses\Enums\ContentStatus;
use Modules\Courses\Http\Requests\ChangeStatusRequest;
use Modules\Courses\Http\Requests\CourseStaffRequest;
use Modules\Courses\Http\Requests\CourseViewRequest;
use Modules\Courses\Http\Requests\InstructorsRequest;
use Modules\Courses\Http\Requests\ListCoursesRequest;
use Modules\Courses\Http\Requests\StoreCourseRequest;
use Modules\Courses\Http\Requests\UpdateCourseRequest;
use Modules\Courses\Models\Course;
use Modules\Courses\Models\Enrollment;
use Modules\Courses\Models\LessonProgress;
use Modules\Courses\Services\CoursePresenter;

/** Running courses: create, edit, publish, name the teachers, see who is enrolled and how the course changed. */
class CourseController extends Controller
{
    public function index(ListCoursesRequest $request)
    {
        $user = $request->user();

        $query = Course::query()
            ->withCount(['lessons as published_lessons_count' => fn ($q) => $q->where('course_lessons.status', ContentStatus::Published->value)])
            ->with('instructors:id,name')
            ->when($request->validated('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('id');
        if (! $user->isAnyRole('admin')) {
            $query->whereHas('instructors', fn ($q) => $q->where('users.id', $user->id));
        }

        return $query->cursorPaginate(25)->through(fn (Course $course) => CoursePresenter::summary($course));
    }

    public function store(StoreCourseRequest $request, CreateCourse $create)
    {
        $course = $create->handle($request->user(), CourseData::fromValidated($request->validated()));

        return response()->json(CoursePresenter::staff($this->loaded($course)), 201);
    }

    public function show(CourseViewRequest $request, Course $course)
    {
        return CoursePresenter::staff($this->loaded($course));
    }

    public function update(UpdateCourseRequest $request, Course $course, UpdateCourse $update)
    {
        $update->handle($course, CourseData::fromValidated($request->validated()), $request->user());

        return CoursePresenter::staff($this->loaded($course));
    }

    public function destroy(CourseStaffRequest $request, Course $course, DeleteContent $delete)
    {
        $delete->handle($course);

        return response()->noContent();
    }

    public function instructors(InstructorsRequest $request, Course $course, SyncInstructors $sync)
    {
        $sync->handle($course, $request->validated('instructor_ids'));

        return CoursePresenter::staff($this->loaded($course));
    }

    public function status(ChangeStatusRequest $request, Course $course, ChangeContentStatus $change)
    {
        $change->handle($course, $request->target(), $request->user());

        return CoursePresenter::staff($this->loaded($course));
    }

    public function versions(CourseViewRequest $request, Course $course)
    {
        return ['data' => $course->versions()->with('author:id,name')->get()->map(fn ($version) => CoursePresenter::version($version))->all()];
    }

    public function enrollments(CourseViewRequest $request, Course $course)
    {
        $lessonIds = $course->lessons()->where('course_lessons.status', ContentStatus::Published->value)->pluck('course_lessons.id');
        $done = LessonProgress::query()->whereIn('lesson_id', $lessonIds)->selectRaw('student_id, count(*) as total')->groupBy('student_id')->pluck('total', 'student_id');

        return $course->enrollments()->with(['student:id,name', 'payment:id,status,amount'])->orderByDesc('id')->cursorPaginate(50)
            ->through(fn (Enrollment $e) => [
                'id' => $e->id,
                'student' => ['id' => $e->student->id, 'name' => $e->student->name],
                'status' => CoursePresenter::enrollment($e)['status'],
                'payment' => $e->payment ? ['id' => $e->payment->id, 'status' => $e->payment->status, 'amount' => $e->payment->amount] : null,
                'completed_lessons' => $e->grantsAccess() ? (int) ($done[$e->student_id] ?? 0) : 0,
                'published_lessons' => $lessonIds->count(),
                'course_version' => $e->course_version,
                'completed_version' => $e->completed_version,
                'completed_at' => $e->completed_at?->toIso8601String(),
                'expires_at' => $e->expires_at?->toIso8601String(),
            ]);
    }

    public function cancelEnrollment(CourseStaffRequest $request, Course $course, Enrollment $enrollment, CancelEnrollment $cancel)
    {
        abort_unless($enrollment->course_id === $course->id, 404);
        $cancel->handle($enrollment, $request->user());

        return response()->noContent();
    }

    private function loaded(Course $course): Course
    {
        return $course->fresh()->load(['subjects.lessons', 'instructors:id,name'])
            ->loadCount(['lessons as published_lessons_count' => fn ($q) => $q->where('course_lessons.status', ContentStatus::Published->value)]);
    }
}
