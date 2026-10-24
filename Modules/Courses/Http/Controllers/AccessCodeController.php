<?php

declare(strict_types=1);

namespace Modules\Courses\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Carbon;
use Modules\Courses\Actions\CreateAccessCodes;
use Modules\Courses\Actions\RedeemAccessCode;
use Modules\Courses\Http\Requests\CourseStaffRequest;
use Modules\Courses\Http\Requests\CourseViewRequest;
use Modules\Courses\Http\Requests\CreateAccessCodesRequest;
use Modules\Courses\Http\Requests\RedeemAccessCodeRequest;
use Modules\Courses\Models\AccessCode;
use Modules\Courses\Models\Course;
use Modules\Courses\Services\CoursePresenter;
use Modules\Courses\Services\CourseProgress;

/** Codes a center hands out, and the student's box where one is entered. */
class AccessCodeController extends Controller
{
    public function index(CourseViewRequest $request, Course $course)
    {
        return $course->accessCodes()->orderByDesc('id')->cursorPaginate(50)->through(fn (AccessCode $code) => $this->present($code));
    }

    public function store(CreateAccessCodesRequest $request, Course $course, CreateAccessCodes $create)
    {
        $data = $request->validated();
        $codes = $create->handle(
            $course,
            $data['count'] ?? 1,
            $data['max_uses'] ?? 1,
            isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
            $request->user()->id,
        );

        return response()->json(['data' => $codes->map(fn (AccessCode $code) => $this->present($code))->values()], 201);
    }

    public function destroy(CourseStaffRequest $request, AccessCode $code)
    {
        $code->forceFill(['disabled_at' => now()])->save();

        return $this->present($code);
    }

    public function redeem(RedeemAccessCodeRequest $request, RedeemAccessCode $redeem, CourseProgress $progress)
    {
        $student = $progress->studentOf($request->user());
        abort_unless($student, 403, 'إدخال الكود متاح لحساب الطالب أو ولي الأمر.');

        $enrollment = $redeem->handle($request->validated('code'), $student)->load('course');

        return ['enrollment' => CoursePresenter::enrollment($enrollment), 'course' => ['id' => $enrollment->course->id, 'title' => $enrollment->course->title, 'slug' => $enrollment->course->slug]];
    }

    private function present(AccessCode $code): array
    {
        return [
            'id' => $code->id, 'code' => $code->code, 'state' => $code->state(),
            'max_uses' => $code->max_uses, 'used_count' => $code->used_count,
            'expires_at' => $code->expires_at?->toIso8601String(), 'created_at' => $code->created_at?->toIso8601String(),
        ];
    }
}
