<?php

declare(strict_types=1);

namespace Modules\Tenancy\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/** The center's owner never confirmed their email and the grace days are over: no paid SMS/WhatsApp (402). */
class EmailUnverified extends RuntimeException implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('أكّد البريد الإلكتروني لمدير المركز علشان تقدر تبعت رسائل SMS وواتساب. ابعت رابط التأكيد تاني من صفحة المركز.');
    }

    /** Throws when the current center may not send SMS/WhatsApp now. */
    public static function guard(): void
    {
        if (tenant()?->smsBlockedForUnverifiedEmail()) {
            throw new self;
        }
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => 'email_unverified'], 402);
    }
}
