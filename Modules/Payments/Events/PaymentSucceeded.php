<?php

declare(strict_types=1);

namespace Modules\Payments\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Fact: a payment became paid, by any route (online, QR, or recorded by staff). Dispatched after the change commits. */
final class PaymentSucceeded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $paymentId, public readonly int $studentId) {}
}
