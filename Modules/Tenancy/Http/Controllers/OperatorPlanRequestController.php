<?php

declare(strict_types=1);

namespace Modules\Tenancy\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Tenancy\Http\Resources\PlanRequestResource;
use Modules\Tenancy\Models\PlanRequest;

/** The operator's inbox of plan requests (custom-plan leads and upgrade requests of every center). */
class OperatorPlanRequestController
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(PlanRequest::STATUSES)],
            'type' => ['nullable', Rule::in(PlanRequest::TYPES)],
        ]);

        $page = PlanRequest::query()
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->latest('created_at')->latest('id')
            ->paginate(25);

        $counts = PlanRequest::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return PlanRequestResource::collection($page)->additional(['meta' => ['counts' => collect(PlanRequest::STATUSES)
            ->mapWithKeys(fn (string $status) => [$status => (int) ($counts[$status] ?? 0)])]]);
    }

    public function update(Request $request, PlanRequest $planRequest)
    {
        $data = $request->validate(['status' => ['required', Rule::in(PlanRequest::STATUSES)]], [
            'status.*' => 'الحالة يجب أن تكون new أو contacted أو closed.',
        ]);
        $planRequest->update($data);

        return new PlanRequestResource($planRequest);
    }
}
