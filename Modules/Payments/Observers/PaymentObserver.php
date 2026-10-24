<?php

declare(strict_types=1);

namespace Modules\Payments\Observers;

use Modules\Payments\Events\PaymentSucceeded;
use Modules\Payments\Models\Payment;

final class PaymentObserver
{
    /** A payment recorded as paid from the start (collected at the desk, say). */
    public function created(Payment $payment): void
    {
        if ($payment->status === 'paid') {
            PaymentSucceeded::dispatch($payment->id, $payment->student_id);
        }
    }

    /** A payment that just turned paid; later edits to a paid payment announce nothing. */
    public function updated(Payment $payment): void
    {
        if ($payment->status === 'paid' && $payment->wasChanged('status')) {
            PaymentSucceeded::dispatch($payment->id, $payment->student_id);
        }
    }
}
