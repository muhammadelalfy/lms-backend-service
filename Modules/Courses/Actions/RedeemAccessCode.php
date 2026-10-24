<?php

declare(strict_types=1);

namespace Modules\Courses\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Courses\Enums\ContentStatus;
use Modules\Courses\Enums\EnrollmentStatus;
use Modules\Courses\Events\EnrollmentCreated;
use Modules\Courses\Exceptions\ContentRuleViolation;
use Modules\Courses\Models\AccessCode;
use Modules\Courses\Models\Enrollment;
use Modules\Students\Models\Student;

/**
 * A student enters a code and the course opens, free of any price (the center was paid another way).
 * A student who already has the course open is told so and no use of the code is spent.
 */
final class RedeemAccessCode
{
    public function __construct(private readonly ActivateEnrollment $activate) {}

    public function handle(string $typed, Student $student): Enrollment
    {
        return DB::transaction(function () use ($typed, $student): Enrollment {
            $code = AccessCode::query()->with('course')->where('code', AccessCode::normalize($typed))->lockForUpdate()->first()
                ?? throw ContentRuleViolation::codeUnknown();

            $course = $code->course;
            $existing = Enrollment::query()->where(['course_id' => $course->id, 'student_id' => $student->id])->first();

            if ($existing && in_array($existing->status, [EnrollmentStatus::Active, EnrollmentStatus::Completed], true)) {
                return $existing;
            }
            if ($existing?->status === EnrollmentStatus::Cancelled) {
                throw ContentRuleViolation::enrollmentCancelled();
            }
            if ($code->state() !== 'active') {
                throw ContentRuleViolation::codeUnusable($code->state());
            }
            if (! in_array($course->status, [ContentStatus::Published, ContentStatus::Hidden], true)) {
                throw ContentRuleViolation::courseNotOpen();
            }

            $enrollment = $existing;
            if (! $enrollment) {
                $enrollment = new Enrollment(['course_id' => $course->id, 'student_id' => $student->id]);
                $enrollment->course_version = $course->version;
                $enrollment->save();
                EnrollmentCreated::dispatch($enrollment->id, $course->id, $student->id);
            }

            $enrollment = $this->activate->handle($enrollment);
            $code->increment('used_count');
            DB::table('course_access_code_redemptions')->insert([
                'access_code_id' => $code->id, 'student_id' => $student->id,
                'enrollment_id' => $enrollment->id, 'created_at' => now(),
            ]);

            return $enrollment;
        });
    }
}
