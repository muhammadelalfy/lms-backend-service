<?php

declare(strict_types=1);

namespace Modules\Exams\Enums;

/**
 * The life of an exam result (S6): a draft is the recorder's own and may be edited or deleted; a
 * published one is final and shown to the student and parent; after publication it only changes
 * through an audited correction (admin), which leaves it `corrected`.
 */
enum ExamResultStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Corrected = 'corrected';

    /** Shown to students and parents. */
    public function isVisible(): bool
    {
        return $this !== self::Draft;
    }

    /** @return list<string> */
    public static function visibleValues(): array
    {
        return [self::Published->value, self::Corrected->value];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
