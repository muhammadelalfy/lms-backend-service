<?php

declare(strict_types=1);

namespace Modules\Tenancy\Actions;

use Modules\Auth\Actions\SendEmailVerification;
use Modules\Auth\Models\User;
use Modules\Tenancy\DTOs\SignUpData;
use Modules\Tenancy\Models\School;
use Modules\Tenancy\Services\PlanCatalog;
use Modules\Tenancy\Services\SchoolProvisioner;

/**
 * Opens a trial center on the plan the visitor picked, clamped to `trial_max_plan`. The plan they
 * wanted and their billing choice are kept for the operator. The admin is sent an email confirmation link.
 */
final class StartTrial
{
    public function __construct(
        private readonly SchoolProvisioner $provisioner,
        private readonly PlanCatalog $catalog,
        private readonly SendEmailVerification $verification,
    ) {}

    public function handle(SignUpData $data): School
    {
        $desired = $data->plan ?? $this->catalog->trialPlanFor($data->kind);

        $school = $this->provisioner->open([
            'slug' => $data->slug,
            'name' => $data->name,
            'plan' => $this->catalog->effectiveTrialPlan($desired),
            'trial' => true,
            'admin' => $data->admin,
        ]);
        $school->forceFill([
            'kind' => $data->kind,
            'desired_plan' => $desired,
            'preferred_billing' => $data->billing,
            'email_verification_required' => true,
        ])->save();

        // The admin confirms their email with a signed link; a mail failure never fails the sign-up.
        $school->run(function () use ($school) {
            $admin = User::query()->where('role', 'admin')->orderBy('id')->first();
            if ($admin !== null) {
                $this->verification->handle($school, $admin);
            }
        });

        return $school;
    }
}
