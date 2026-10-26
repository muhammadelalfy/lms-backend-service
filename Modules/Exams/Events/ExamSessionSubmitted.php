<?php

declare(strict_types=1);

namespace Modules\Exams\Events;

/** A student submitted an exam session; dispatched after the transaction committed. */
final class ExamSessionSubmitted
{
    public function __construct(public readonly int $sessionId) {}
}
