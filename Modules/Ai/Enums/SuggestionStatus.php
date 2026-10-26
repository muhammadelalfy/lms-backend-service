<?php

declare(strict_types=1);

namespace Modules\Ai\Enums;

/**
 * A written answer's grading item: `queued` waits for the AI, `ready` holds its suggestion, `failed`
 * the AI could not help (the teacher grades by hand), `skipped` the AI was not asked (no rubric, plan
 * or cap), `empty` the answer was blank (0 suggested without any AI call).
 */
enum SuggestionStatus: string
{
    case Queued = 'queued';
    case Ready = 'ready';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Empty = 'empty';

    /** Has a score the teacher may accept as is. */
    public function hasSuggestion(): bool
    {
        return $this === self::Ready || $this === self::Empty;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
