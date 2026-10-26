<?php

declare(strict_types=1);

namespace Modules\Ai\Support;

/** Why a generation job stopped or was refused: a stable code for the app and the Arabic sentence the teacher reads. */
final class JobError
{
    public const AI_UNAVAILABLE = 'ai_unavailable';

    public const AI_REJECTED = 'ai_rejected';

    public const NO_MATERIAL = 'no_material';

    public const CAP_REACHED = 'cap_reached';

    public const INSUFFICIENT_CONTENT = 'insufficient_content';

    public const CANCELLED = 'cancelled';

    private const MESSAGES = [
        self::AI_UNAVAILABLE => 'المساعد غير متاح الآن. أعد المحاولة بعد قليل.',
        self::AI_REJECTED => 'رفض المساعد هذا الطلب. راجع المعايير والمواد ثم أعد المحاولة.',
        self::NO_MATERIAL => 'لا توجد مواد جاهزة لهذا الامتحان.',
        self::CAP_REACHED => 'بلغ مركزك حد وحدات الذكاء الاصطناعي لهذا الشهر؛ تم حفظ ما تم توليده. يمكنك إكمال الامتحان يدوياً أو ترقية الباقة.',
        self::INSUFFICIENT_CONTENT => 'المحتوى لا يكفي لتوليد أسئلة مستندة إلى المادة.',
        self::CANCELLED => 'تم إلغاء التوليد.',
    ];

    public static function message(?string $code): ?string
    {
        return $code === null ? null : (self::MESSAGES[$code] ?? self::MESSAGES[self::AI_REJECTED]);
    }
}
