<?php

declare(strict_types=1);

namespace Modules\Tenancy\Http\Controllers;

use Illuminate\Http\Response;

/**
 * The page the platform operator manages schools from. It contains no data: the operator enters
 * CENTRAL_API_TOKEN, and the page then talks to /api/central. Never cached or indexed.
 */
class OperatorDashboardController
{
    public function __invoke(): Response
    {
        $plans = collect(config('schools.plans'))->map(fn (array $plan, string $key) => [
            'key' => $key,
            'label' => $plan['label'],
            'max_students' => $plan['limits']['students'] ?? null,
            'trial_days' => config('schools.trial_days'),
        ])->values()->all();

        return response()
            ->view('operator', ['config' => ['plans' => $plans, 'base_domain' => config('tenancy.base_domain'), 'default_plan' => config('schools.default_plan')]])
            ->header('Cache-Control', 'no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Frame-Options', 'DENY');
    }
}
