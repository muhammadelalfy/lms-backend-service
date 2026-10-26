<?php

declare(strict_types=1);

namespace Modules\Exams\Http\Requests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Exams\Models\ExamResult;

/**
 * Base of the staff requests about one exam result. Staff only (403 otherwise); the policy `ability`
 * on the routed result is checked too, with the module's Arabic message.
 */
abstract class ExamResultRequest extends FormRequest
{
    protected string $ability = 'update';

    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user->isAnyRole('admin', 'teacher')) {
            return false;
        }
        if (! $user->can($this->ability, $this->result())) {
            throw new AuthorizationException($this->deniedMessage());
        }

        return true;
    }

    protected function deniedMessage(): string
    {
        return 'لا تملك صلاحية هذا الطالب.';
    }

    public function rules(): array
    {
        return [];
    }

    public function result(): ExamResult
    {
        $result = $this->route('exam');
        if (! $result instanceof ExamResult) {
            throw new \LogicException('The route names no exam result.');
        }

        return $result;
    }
}
