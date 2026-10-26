<?php

declare(strict_types=1);

namespace Modules\Tenancy\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Tenancy\Models\PlanRequest;

/** @mixin PlanRequest */
class PlanRequestResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'audience' => $this->audience,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'org_name' => $this->org_name,
            'students_estimate' => $this->students_estimate,
            'plan_slug' => $this->plan_slug,
            'message' => $this->message,
            'school_id' => $this->school_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
