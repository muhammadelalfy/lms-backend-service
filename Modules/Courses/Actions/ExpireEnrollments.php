<?php

declare(strict_types=1);

namespace Modules\Courses\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Courses\Enums\EnrollmentStatus;
use Modules\Courses\Events\EnrollmentExpired;
use Modules\Courses\Models\Enrollment;

/** Ends the access of enrollments past their period. Access also stops by the clock alone; this makes it official. */
final class ExpireEnrollments
{
    public function handle(): int
    {
        $expired = 0;

        Enrollment::query()->where('status', EnrollmentStatus::Active)->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->pluck('id')->each(function (int $id) use (&$expired): void {
                DB::transaction(function () use ($id, &$expired): void {
                    $locked = Enrollment::query()->whereKey($id)->lockForUpdate()->first();
                    if (! $locked || $locked->status !== EnrollmentStatus::Active || $locked->expires_at?->isFuture() !== false) {
                        return;
                    }
                    $locked->transitionTo(EnrollmentStatus::Expired);
                    $locked->save();
                    EnrollmentExpired::dispatch($locked->id, $locked->course_id, $locked->student_id);
                    $expired++;
                });
            });

        return $expired;
    }
}
