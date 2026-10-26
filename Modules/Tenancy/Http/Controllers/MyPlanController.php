<?php

declare(strict_types=1);

namespace Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Tenancy\Actions\RequestPlanUpgrade;
use Modules\Tenancy\Http\Requests\RequestUpgradeRequest;
use Modules\Tenancy\Services\PlanCatalog;
use Modules\Tenancy\Services\PlanUsage;

/** "باقتي": the current center's plan, its use, and a request for more. Admin only; this center only. */
class MyPlanController extends Controller
{
    public function show(Request $request, PlanCatalog $catalog, PlanUsage $usage): array
    {
        abort_unless($request->user()->isAnyRole('admin'), 403, 'هذه الصفحة للمشرف فقط.');
        $school = tenant();
        $next = $catalog->nextPlan($school->plan, $school->limit('students'));
        $endsAt = $school->trial_ends_at;

        return [
            'plan' => ['slug' => $school->plan, 'name' => $catalog->label($school->plan)],
            'trial' => $endsAt !== null,
            'trial_ends_at' => $endsAt?->toIso8601String(),
            'days_left' => $endsAt === null ? null : max(0, (int) ceil(now()->diffInSeconds($endsAt, false) / 86400)),
            // A locked center never reaches here (EnsureSchoolIsActive answers first).
            'locked' => false,
            'desired_plan' => $school->desired_plan,
            'limits' => $school->limits() + array_fill_keys(PlanUsage::CAPS, null),
            'usage' => $usage->of($school),
            'next_plan' => $next === null ? null : [
                'slug' => $next['slug'],
                'name' => $next['name'],
                'limits' => $next['limits'],
                'price' => $next['price'],
            ],
        ];
    }

    public function upgrade(RequestUpgradeRequest $request, RequestPlanUpgrade $upgrade): JsonResponse
    {
        $row = $upgrade->handle(tenant(), $request->user(), $request->validated('plan_slug'), $request->validated('message'));

        return response()->json([
            'message' => 'وصل طلبك وهنكلمك قريب',
            'request' => [
                'id' => $row->id,
                'status' => $row->status,
                'plan_slug' => $row->plan_slug,
                'created_at' => $row->created_at?->toIso8601String(),
            ],
        ], 201);
    }
}
