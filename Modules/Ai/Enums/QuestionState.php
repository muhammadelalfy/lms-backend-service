<?php

declare(strict_types=1);

namespace Modules\Ai\Enums;

/** pending -> approved by the teacher; removed and replaced questions stay as history. */
enum QuestionState: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Removed = 'removed';
    case Replaced = 'replaced';

    /** @return list<string> the states of a question that is part of the exam */
    public static function liveValues(): array
    {
        return [self::Pending->value, self::Approved->value];
    }

    public function isLive(): bool
    {
        return in_array($this->value, self::liveValues(), true);
    }
}
