<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Requests;

/** The AI units usage screen: the center's teachers and its admin. */
class UsageRequest extends AiRequest
{
    protected function deniedMessage(): string
    {
        return 'لا تملك صلاحية عرض استهلاك الذكاء الاصطناعي.';
    }

    public function authorize(): bool
    {
        return $this->allowed($this->user()->isAnyRole('admin', 'teacher'));
    }

    public function rules(): array
    {
        return [];
    }
}
