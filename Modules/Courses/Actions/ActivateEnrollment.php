<?php

declare(strict_types=1);

namespace Modules\Courses\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Courses\Enums\EnrollmentStatus;
use Modules\Courses\Events\EnrollmentActivated;
use Modules\Courses\Models\Enrollment;

/** Opens an enrollment (free at once, paid when its payment is paid, or an expired one renewed). Safe to repeat. */
final class ActivateEnrollment
{
    public function handle(Enrollment $enrollment): Enrollment
    {
        return DB::transaction(function () use ($enrollment): Enrollment {
            $locked = Enrollment::query()->with('course')->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();

            // A retried webhook or a second listener finds it already open.
            if (in_array($locked->status, [EnrollmentStatus::Active, EnrollmentStatus::Completed], true)) {
                return $locked;
            }

            $locked->transitionTo(EnrollmentStatus::Active);
            $locked->activated_at = now();
            $days = $locked->course->access_days;
            $locked->expires_at = $days ? now()->addDays($days) : null;
            $locked->save();
            EnrollmentActivated::dispatch($locked->id, $locked->course_id, $locked->student_id);

            return $locked;
        });
    }
}
