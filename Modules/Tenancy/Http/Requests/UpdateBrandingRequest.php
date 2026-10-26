<?php

declare(strict_types=1);

namespace Modules\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The center's admin changes how the center looks. */
class UpdateBrandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAnyRole('admin');
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'brand_color' => ['sometimes', 'nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'primary_color' => ['sometimes', 'nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['sometimes', 'nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo_url' => ['sometimes', 'nullable', 'url:https', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'brand_color.regex' => 'اللون يكتب هكذا: #1F4E8C',
            'primary_color.regex' => 'اللون يكتب هكذا: #1F4E8C',
            'secondary_color.regex' => 'اللون يكتب هكذا: #1F4E8C',
            'logo_url.url' => 'رابط الشعار يجب أن يبدأ بـ https.',
        ];
    }
}
