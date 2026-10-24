<?php

declare(strict_types=1);

namespace Modules\Courses\Listeners;

use Modules\Courses\Actions\ActivateEnrollment;
use Modules\Courses\Enums\EnrollmentStatus;
use Modules\Courses\Models\Enrollment;
use Modules\Payments\Events\PaymentSucceeded;

/** A course bought with a payment opens as soon as that payment is paid. */
final class ActivateEnrollmentOnPayment
{
    public function __construct(private readonly ActivateEnrollment $activate) {}

    public function handle(PaymentSucceeded $event): void
    {
        Enrollment::query()->where('payment_id', $event->paymentId)->where('status', EnrollmentStatus::Pending)
            ->get()->each(fn (Enrollment $enrollment) => $this->activate->handle($enrollment));
    }
}
