<?php

declare(strict_types=1);

namespace Modules\Tenancy\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Tenancy\Actions\SubmitPlanRequest;
use Modules\Tenancy\DTOs\PlanRequestData;
use Modules\Tenancy\Http\Requests\StorePlanRequestRequest;

/** The landing's "اطلب باقة مخصصة" form. Public, central addresses only, a few per visitor per hour. */
class PlanRequestController
{
    public const RECEIVED = 'وصلنا طلبك. هنكلمك على الموبايل في أقرب وقت.';

    public function __invoke(StorePlanRequestRequest $request, SubmitPlanRequest $submit): JsonResponse
    {
        // A bot is told what a person is told, so it learns nothing; nothing is kept or mailed.
        if (! $request->isBot()) {
            $submit->handle(PlanRequestData::custom($request->validated(), $request->ip(), $request->userAgent()));
        }

        return response()->json(['message' => self::RECEIVED], 201);
    }
}
