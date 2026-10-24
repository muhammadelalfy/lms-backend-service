<?php

declare(strict_types=1);

namespace Modules\Ai\Support;

/** Why a material failed: a stable code for the app, and the Arabic sentence the teacher reads. */
final class FailureReason
{
    public const AI_UNAVAILABLE = 'ai_unavailable';

    public const SCANNED_NO_TEXT = 'scanned_no_text';

    public const FILE_TOO_LARGE = 'file_too_large';

    public const PASSWORD_PROTECTED = 'password_protected';

    public const CORRUPT = 'corrupt';

    public const NO_TEXT = 'no_text';

    public const TOO_MANY_PAGES = 'too_many_pages';

    public const TOO_MANY_SHEETS = 'too_many_sheets';

    public const INFECTED = 'infected';

    public const FILE_MISSING = 'file_missing';

    public const STALE_VERSION = 'stale_version';

    private const MESSAGES = [
        self::AI_UNAVAILABLE => 'المساعد غير متاح الآن. أعد المحاولة بعد قليل.',
        self::SCANNED_NO_TEXT => 'الملف عبارة عن صور؛ ارفع نسخة نصية أو Word.',
        self::FILE_TOO_LARGE => 'الملف كبير جداً.',
        self::PASSWORD_PROTECTED => 'الملف محمي بكلمة مرور.',
        self::CORRUPT => 'الملف تالف أو لا يمكن قراءته.',
        self::NO_TEXT => 'لا يوجد نص في الملف.',
        self::TOO_MANY_PAGES => 'عدد صفحات الملف أكبر من الحد المسموح.',
        self::TOO_MANY_SHEETS => 'عدد أوراق الملف أكبر من الحد المسموح.',
        self::INFECTED => 'تم رفض الملف لأنه غير آمن.',
        self::STALE_VERSION => 'نسخة المادة قديمة. أعد المحاولة.',
        self::FILE_MISSING => 'تعذر العثور على الملف المرفوع. ارفعه من جديد.',
    ];

    public static function known(string $code): bool
    {
        return isset(self::MESSAGES[$code]);
    }

    public static function message(?string $code): ?string
    {
        return $code === null ? null : (self::MESSAGES[$code] ?? self::MESSAGES[self::CORRUPT]);
    }
}
