<?php

declare(strict_types=1);

namespace Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Tenancy\Services\OnboardingChecklist;

/** The admin's welcome checklist and verification banner; dismissing hides the checklist for good. */
class OnboardingController extends Controller
{
    public function __construct(private readonly OnboardingChecklist $checklist) {}

    public function show(Request $request): array
    {
        $this->authorizeAdmin($request);

        return $this->checklist->for(tenant(), $request->user());
    }

    public function dismiss(Request $request): array
    {
        $this->authorizeAdmin($request);
        $school = tenant();
        if ($school->onboarding_dismissed_at === null) {
            $school->forceFill(['onboarding_dismissed_at' => now()->toIso8601String()])->save();
        }

        return $this->checklist->for($school, $request->user());
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()->isAnyRole('admin'), 403, 'هذه الصفحة للمشرف فقط.');
    }
}
