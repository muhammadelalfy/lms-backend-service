<?php

declare(strict_types=1);

namespace Modules\Exams\Events;

/** A result became visible to its student and parent; dispatched after the transaction committed. */
final class ExamResultPublished
{
    public function __construct(public readonly int $resultId, public readonly int $publishedBy) {}
}
