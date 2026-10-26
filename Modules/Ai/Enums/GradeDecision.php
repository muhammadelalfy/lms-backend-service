<?php

declare(strict_types=1);

namespace Modules\Ai\Enums;

/**
 * How the teacher decided a written answer: took the suggestion as is (`accepted`), adjusted it
 * (`edited`), rejected it for their own score (`overridden`), or graded with no suggestion (`manual`).
 */
enum GradeDecision: string
{
    case Accepted = 'accepted';
    case Edited = 'edited';
    case Overridden = 'overridden';
    case Manual = 'manual';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $d) => $d->value, self::cases());
    }
}
