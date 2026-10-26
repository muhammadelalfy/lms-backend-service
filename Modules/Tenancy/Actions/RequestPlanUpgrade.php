<?php

declare(strict_types=1);

namespace Modules\Tenancy\Actions;

use Modules\Auth\Models\User;
use Modules\Tenancy\DTOs\PlanRequestData;
use Modules\Tenancy\Models\PlanRequest;
use Modules\Tenancy\Models\School;
use Modules\Tenancy\Services\PlanCatalog;

/** An upgrade request of the current center, into the operator's central inbox (type `upgrade`). */
final class RequestPlanUpgrade
{
    public function __construct(private readonly SubmitPlanRequest $submit, private readonly PlanCatalog $catalog) {}

    public function handle(School $school, User $admin, ?string $planSlug, ?string $message): PlanRequest
    {
        $target = $planSlug ?? $this->catalog->nextPlan($school->plan, $school->limit('students'))['slug'] ?? null;
        $message = filled($message)
            ? trim($message)
            : 'طلب ترقية الباقة من '.$this->catalog->label($school->plan)." ({$school->plan})"
                .($target ? ' إلى '.$this->catalog->label($target)." ({$target})" : '').'.';

        return $this->submit->handle(new PlanRequestData(
            type: 'upgrade',
            name: $admin->name,
            message: $message,
            email: $admin->email,
            orgName: $school->name,
            audience: $school->kind,
            planSlug: $target,
            schoolId: (string) $school->getTenantKey(),
        ));
    }
}
