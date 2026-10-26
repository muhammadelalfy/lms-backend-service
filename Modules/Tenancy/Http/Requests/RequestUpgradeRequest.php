<?php

declare(strict_types=1);

namespace Modules\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Tenancy\Services\PlanCatalog;

/** A center's admin asks the operator for a bigger plan. */
class RequestUpgradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // A "try it" admin looks around; it does not talk to sales on the center's behalf.
        return $this->user()->isAnyRole('admin') && ! $this->user()->isDemo();
    }

    public function rules(): array
    {
        return [
            'plan_slug' => ['nullable', 'string', Rule::in(app(PlanCatalog::class)->publicSlugs())],
            'message' => ['nullable', 'string', 'max:800'],
        ];
    }

    public function messages(): array
    {
        return [
            'plan_slug.*' => 'الباقة المختارة غير متاحة.',
            'message.*' => 'الرسالة طويلة، الحد الأقصى 800 حرف.',
        ];
    }
}
