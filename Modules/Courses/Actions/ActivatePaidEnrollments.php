<?php

declare(strict_types=1);

namespace Modules\Courses\Actions;

use Modules\Courses\Enums\EnrollmentStatus;
use Modules\Courses\Models\Enrollment;
use Modules\Students\Models\Student;

/**
 * Opens a student's pending enrollments whose payment is already paid. Normally the payment event does this;
 * this catches a payment settled by a path that raised no event, so a paid student is never left waiting.
 */
final class ActivatePaidEnrollments
{
    public function __construct(private readonly ActivateEnrollment $activate) {}

    public function forStudent(Student $student): void
    {
        Enrollment::query()->where('student_id', $student->id)->where('status', EnrollmentStatus::Pending)
            ->whereHas('payment', fn ($payment) => $payment->where('status', 'paid'))
            ->get()->each(fn (Enrollment $enrollment) => $this->activate->handle($enrollment));
    }
}
