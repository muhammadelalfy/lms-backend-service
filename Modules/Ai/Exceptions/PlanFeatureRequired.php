<?php

declare(strict_types=1);

namespace Modules\Ai\Exceptions;

use Illuminate\Http\JsonResponse;

/** The center's plan does not include AI exams (yet). */
final class PlanFeatureRequired extends \RuntimeException
{
    public function __construct(public readonly bool $soon = false)
    {
        parent::__construct($soon ? 'الامتحانات بالذكاء الاصطناعي قادمة قريباً في باقتك.' : 'الامتحانات بالذكاء الاصطناعي غير متاحة في باقتك الحالية. رقِّ الباقة لتفعيلها.');
    }

    /** An expected refusal, not an error to log. */
    public function report(): bool
    {
        return true;
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => 'plan_feature', 'feature' => 'ai_exams', 'soon' => $this->soon], 403);
    }
}
