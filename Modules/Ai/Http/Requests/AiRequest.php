<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Requests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Ai\Services\AiEntitlements;

/** Base of the AI requests: the role first, then the center's plan (flag `ai_exams`). */
abstract class AiRequest extends FormRequest
{
    /** Whether the plan flag must be on; deleting stays possible after a downgrade. */
    protected function needsPlan(): bool
    {
        return true;
    }

    protected function failedAuthorization(): never
    {
        throw new AuthorizationException($this->deniedMessage());
    }

    protected function deniedMessage(): string
    {
        return 'لا تملك صلاحية هذه المادة.';
    }

    protected function allowed(bool $role): bool
    {
        if (! $role) {
            return false;
        }
        if ($this->needsPlan()) {
            app(AiEntitlements::class)->ensureEnabled();
        }

        return true;
    }
}
