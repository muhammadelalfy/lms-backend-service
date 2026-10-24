<?php

declare(strict_types=1);

namespace Modules\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Someone opens their own academy from the public site. */
class SignUpRequest extends FormRequest
{
    public const KINDS = ['center', 'group', 'solo'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(self::KINDS)],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'slug' => ['required', 'string', 'max:40'],
            'admin' => ['required', 'array'],
            'admin.name' => ['required', 'string', 'max:120'],
            'admin.email' => ['required', 'email', 'max:190'],
            'admin.password' => ['required', 'string', 'min:8', 'max:100'],
            // A hidden field no person fills: a bot that does is turned away quietly.
            'website' => ['prohibited'],
        ];
    }
}
