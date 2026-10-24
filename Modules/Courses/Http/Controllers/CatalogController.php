<?php

declare(strict_types=1);

namespace Modules\Courses\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Courses\Actions\ActivatePaidEnrollments;
use Modules\Courses\Actions\EnrollStudent;
use Modules\Courses\Enums\ContentStatus;
use Modules\Courses\Http\Requests\CatalogRequest;
use Modules\Courses\Models\Course;
use Modules\Courses\Models\Enrollment;
use Modules\Courses\Services\CoursePresenter;
use Modules\Courses\Services\CourseProgress;

/** What the school offers: browse the published courses, read one, and join it. */
class CatalogController extends Controller
{
    public function __construct(private readonly CourseProgress $progress, private readonly ActivatePaidEnrollments $activatePaid) {}

    public function index(CatalogRequest $request)
    {
        $filters = $request->validated();
        $mine = $this->enrollmentsOf($request)->keyBy('course_id');

        return Course::query()
            ->publiclyVisible()
            ->withCount(['lessons as published_lessons_count' => fn ($q) => $q
                ->where('course_lessons.status', ContentStatus::Published->value)
                ->where('course_subjects.status', ContentStatus::Published->value)])
            ->with('instructors:id,name')
            ->when($filters['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->when($filters['pricing'] ?? null, fn ($q, $pricing) => $q->where('pricing', $pricing))
            ->when($request->boolean('featured'), fn ($q) => $q->where('is_featured', true))
            ->orderByDesc('id')
            ->cursorPaginate(12)
            ->through(fn (Course $course) => CoursePresenter::summary($course) + ['enrollment' => CoursePresenter::enrollment($mine->get($course->id))]);
    }

    public function show(Request $request, string $slug)
    {
        $course = $this->visible($slug)->load(['subjects.lessons', 'instructors:id,name']);
        $enrollment = $this->enrollmentsOf($request)->firstWhere('course_id', $course->id);

        return CoursePresenter::outline($course, protected: false) + ['enrollment' => CoursePresenter::enrollment($enrollment)];
    }

    public function enroll(Request $request, string $slug, EnrollStudent $enrollStudent)
    {
        $user = $request->user();
        $student = $this->progress->studentOf($user);
        abort_unless($student, 403, 'التسجيل في الدورات متاح لحساب الطالب أو ولي الأمر.');

        $course = $this->visible($slug);
        $enrollment = $enrollStudent->handle($course, $user, $student);

        return response()->json(['enrollment' => CoursePresenter::enrollment($enrollment), 'course_id' => $course->id], $enrollment->wasRecentlyCreated ? 201 : 200);
    }

    private function visible(string $slug): Course
    {
        return Course::query()->publiclyVisible()->where('slug', $slug)->firstOrFail();
    }

    private function enrollmentsOf(Request $request)
    {
        $student = $this->progress->studentOf($request->user());
        if (! $student) {
            return collect();
        }
        $this->activatePaid->forStudent($student);

        return Enrollment::query()->where('student_id', $student->id)->with('payment')->get();
    }
}
