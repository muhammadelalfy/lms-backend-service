<?php

declare(strict_types=1);

namespace Modules\Courses\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A student (or parent) types a code. */
class RedeemAccessCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAnyRole('student', 'parent');
    }

    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:40']];
    }
}
