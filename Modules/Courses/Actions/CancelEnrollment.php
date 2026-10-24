<?php

declare(strict_types=1);

namespace Modules\Courses\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Courses\Enums\EnrollmentStatus;
use Modules\Courses\Events\EnrollmentCancelled;
use Modules\Courses\Models\Enrollment;

final class CancelEnrollment
{
    public function handle(Enrollment $enrollment, ?User $actor = null): Enrollment
    {
        return DB::transaction(function () use ($enrollment, $actor): Enrollment {
            $locked = Enrollment::query()->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();

            $locked->transitionTo(EnrollmentStatus::Cancelled);
            $locked->cancelled_at = now();
            $locked->save();
            EnrollmentCancelled::dispatch($locked->id, $locked->course_id, $locked->student_id, $actor?->id);

            return $locked;
        });
    }
}
