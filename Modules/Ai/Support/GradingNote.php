<?php

declare(strict_types=1);

namespace Modules\Ai\Support;

/** Why a written answer has no AI suggestion: a stable code and the Arabic line the queue shows. */
final class GradingNote
{
    public const NO_RUBRIC = 'no_rubric';

    public const PLAN_FEATURE = 'plan_feature';

    public const CAP_REACHED = 'cap_reached';

    public const AI_UNAVAILABLE = 'ai_unavailable';

    public const AI_REJECTED = 'ai_rejected';

    public const EMPTY_ANSWER = 'empty_answer';

    private const MESSAGES = [
        self::NO_RUBRIC => 'لا يوجد اقتراح: لا توجد إجابة نموذجية أو معيار تصحيح لهذا السؤال. صحّحه يدوياً.',
        self::PLAN_FEATURE => 'لا يوجد اقتراح: مساعد التصحيح غير متاح في باقتك. صحّحه يدوياً.',
        self::CAP_REACHED => 'لا يوجد اقتراح: بلغ مركزك حد وحدات الذكاء الاصطناعي لهذا الشهر. صحّحه يدوياً.',
        self::AI_UNAVAILABLE => 'لا يوجد اقتراح: المساعد غير متاح الآن. صحّحه يدوياً.',
        self::AI_REJECTED => 'لا يوجد اقتراح: تعذّر على المساعد تقييم هذه الإجابة. صحّحها يدوياً.',
        self::EMPTY_ANSWER => 'لم يُجب الطالب عن هذا السؤال.',
    ];

    public static function message(?string $code): ?string
    {
        return $code === null ? null : (self::MESSAGES[$code] ?? self::MESSAGES[self::AI_REJECTED]);
    }
}
