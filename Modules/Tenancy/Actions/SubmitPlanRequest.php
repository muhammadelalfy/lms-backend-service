<?php

declare(strict_types=1);

namespace Modules\Tenancy\Actions;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Modules\Tenancy\DTOs\PlanRequestData;
use Modules\Tenancy\Models\PlanRequest;
use Modules\Tenancy\Notifications\PlanRequestReceived;
use Throwable;

/**
 * Keeps a plan request and tells the sales inbox about it. The same request sent again within a day
 * (same phone and message, or the same center asking for the same upgrade) is answered with the first
 * row and mailed only once. The mail is queued and never fails the request: the row is what counts.
 */
final class SubmitPlanRequest
{
    public const DEDUPE_HOURS = 24;

    public function handle(PlanRequestData $data): PlanRequest
    {
        if ($existing = $this->duplicateOf($data)) {
            return $existing;
        }

        $request = PlanRequest::query()->create($data->toRow());
        $this->notifySales($request);

        return $request;
    }

    private function duplicateOf(PlanRequestData $data): ?PlanRequest
    {
        $query = PlanRequest::query()->recent(self::DEDUPE_HOURS)->where('type', $data->type);

        $query = $data->schoolId !== null
            ? $query->where('school_id', $data->schoolId)->where('status', 'new')->where('plan_slug', $data->planSlug)
            : $query->where('phone', $data->phone)->where('message', $data->message);

        return $query->latest('id')->first();
    }

    private function notifySales(PlanRequest $request): void
    {
        $to = (string) config('landing.contact_email');
        if ($to === '') {
            Log::warning('plan request received but landing.contact_email is not set', ['plan_request' => $request->id]);

            return;
        }

        try {
            Notification::route('mail', $to)->notify(new PlanRequestReceived($request));
        } catch (Throwable $error) {
            // Kept and visible in the operator's inbox; the queue retries a failed send on its own.
            report($error);
        }
    }
}
