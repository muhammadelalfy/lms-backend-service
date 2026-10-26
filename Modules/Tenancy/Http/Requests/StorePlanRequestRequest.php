<?php

declare(strict_types=1);

namespace Modules\Tenancy\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Tenancy\Services\PlanCatalog;

/** A visitor of the landing asks for a custom plan. Public: honeypot, throttle and server-side limits. */
class StorePlanRequestRequest extends FormRequest
{
    public const AUDIENCES = ['solo', 'group', 'center'];

    /** An Egyptian mobile (01x…, +201x…, 201x…) or any international number in +E.164. */
    public const PHONE = '/^(?:(?:\+?20|0)?1[0125]\d{8}|\+\d{8,15})$/';

    public const MAX_LINKS = 2;

    public function authorize(): bool
    {
        return true;
    }

    /** A hidden field no person fills: whoever does is answered as usual and nothing is kept. */
    public function isBot(): bool
    {
        return filled($this->input('website'));
    }

    protected function prepareForValidation(): void
    {
        if (is_string($phone = $this->input('phone'))) {
            $this->merge(['phone' => preg_replace('/[\s\-().]/', '', $phone)]);
        }
        foreach (['name', 'org_name', 'message', 'email'] as $key) {
            if (is_string($value = $this->input($key))) {
                $this->merge([$key => trim($value)]);
            }
        }
    }

    public function rules(): array
    {
        if ($this->isBot()) {
            return [];
        }

        return [
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'phone' => ['required', 'string', 'regex:'.self::PHONE],
            'email' => ['nullable', 'email', 'max:190'],
            'org_name' => ['nullable', 'string', 'max:120'],
            'audience' => ['required', Rule::in(self::AUDIENCES)],
            'students_estimate' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'plan_slug' => ['nullable', 'string', Rule::in(app(PlanCatalog::class)->publicSlugs())],
            'message' => ['required', 'string', 'min:10', 'max:800', $this->fewLinks(...)],
        ];
    }

    public function messages(): array
    {
        return [
            'name.*' => 'اكتب اسمك (حرفين على الأقل).',
            'phone.*' => 'اكتب رقم موبايل صحيح.',
            'email.*' => 'البريد الإلكتروني غير صحيح.',
            'org_name.*' => 'اسم المركز طويل، الحد الأقصى 120 حرف.',
            'audience.*' => 'اختار نوع نشاطك.',
            'students_estimate.*' => 'عدد الطلاب لازم يكون رقم صحيح.',
            'plan_slug.*' => 'الباقة المختارة غير متاحة.',
            'message.required' => 'اوصف احتياجك في 10 حروف على الأقل.',
            'message.string' => 'اوصف احتياجك في 10 حروف على الأقل.',
            'message.min' => 'اوصف احتياجك في 10 حروف على الأقل.',
            'message.max' => 'الوصف طويل، الحد الأقصى 800 حرف.',
        ];
    }

    private function fewLinks(string $attribute, mixed $value, Closure $fail): void
    {
        if (preg_match_all('~(?:https?://|www\.)~i', (string) $value) > self::MAX_LINKS) {
            $fail('ماينفعش أكتر من رابطين في الرسالة.');
        }
    }
}
