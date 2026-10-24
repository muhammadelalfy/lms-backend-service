<?php

declare(strict_types=1);

namespace Modules\Courses\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Courses\Actions\ActivatePaidEnrollments;
use Modules\Courses\Actions\CompleteLesson;
use Modules\Courses\Models\Course;
use Modules\Courses\Models\CourseLesson;
use Modules\Courses\Models\Enrollment;
use Modules\Courses\Services\CoursePresenter;
use Modules\Courses\Services\CourseProgress;
use Modules\Students\Models\Student;

/** A student's own courses: the list, one course with their progress, and finishing lessons. */
class MyCoursesController extends Controller
{
    public function __construct(private readonly CourseProgress $progress, private readonly ActivatePaidEnrollments $activatePaid) {}

    public function index(Request $request)
    {
        $student = $this->student($request);
        $this->activatePaid->forStudent($student);

        $enrollments = Enrollment::query()->where('student_id', $student->id)->with(['course.instructors:id,name', 'payment', 'student'])->latest('id')->get()
            ->filter(fn (Enrollment $e) => $e->course->isOpenToStudents());

        return ['data' => $enrollments->values()->map(function (Enrollment $e) {
            $state = CoursePresenter::enrollment($e);
            $total = $this->progress->orderedLessons($e->course)->count();
            $done = $e->grantsAccess() ? count($this->progress->completedLessonIds($e->course, $e->student)) : 0;

            return CoursePresenter::summary($e->course) + [
                'enrollment' => $state,
                'progress' => ['completed_lessons' => $done, 'total_lessons' => $total],
            ];
        })->all()];
    }

    public function show(Request $request, Course $course)
    {
        [$student, $enrollment] = $this->enrollmentFor($request, $course);
        $course->load(['subjects.lessons', 'instructors:id,name']);
        $state = CoursePresenter::enrollment($enrollment);

        if (! $enrollment->grantsAccess()) {
            // Not open (waiting for payment, cancelled or expired): the outline is shown, the lessons' content is not.
            return CoursePresenter::outline($course, protected: false) + ['enrollment' => $state];
        }

        $ordered = $this->progress->orderedLessons($course);
        $completed = $this->progress->completedLessonIds($course, $student);
        $locked = $this->progress->lockedLessonIds($course, $ordered, $completed);

        return CoursePresenter::outline($course, protected: true, completed: $completed, locked: $locked) + [
            'enrollment' => $state,
            'progress' => ['completed_lessons' => count($completed), 'total_lessons' => $ordered->count()],
        ];
    }

    public function complete(Request $request, CourseLesson $lesson, CompleteLesson $complete)
    {
        [, $enrollment] = $this->enrollmentFor($request, $lesson->subject->course);

        return $complete->handle($enrollment, $lesson);
    }

    private function student(Request $request): Student
    {
        $student = $this->progress->studentOf($request->user());
        abort_unless($student, 403, 'هذه الصفحة لحساب الطالب أو ولي الأمر.');

        return $student;
    }

    /** @return array{0: Student, 1: Enrollment} */
    private function enrollmentFor(Request $request, Course $course): array
    {
        $student = $this->student($request);
        $this->activatePaid->forStudent($student);
        $enrollment = Enrollment::query()->where(['course_id' => $course->id, 'student_id' => $student->id])->with('payment')->first();
        abort_unless($enrollment && $course->isOpenToStudents(), 404);

        return [$student, $enrollment];
    }
}
