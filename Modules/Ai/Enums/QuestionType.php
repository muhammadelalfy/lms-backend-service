<?php

declare(strict_types=1);

namespace Modules\Ai\Enums;

/** The question types the AI writes. The exam runner only knows mcq, true_false and essay. */
enum QuestionType: string
{
    case Mcq = 'mcq';
    case TrueFalse = 'true_false';
    case ShortAnswer = 'short_answer';
    case Essay = 'essay';
    case FillBlank = 'fill_blank';

    /** The `exam_questions.type` it is published as (mobile-safe: written types become essay). */
    public function examType(): string
    {
        return match ($this) {
            self::Mcq => 'mcq',
            self::TrueFalse => 'true_false',
            default => 'essay',
        };
    }

    /** Answered in free text and marked with a rubric. */
    public function isWritten(): bool
    {
        return ! in_array($this, [self::Mcq, self::TrueFalse], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
