<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Ai\Enums\DraftStatus;
use Modules\Ai\Models\DraftExam;

class ListDraftsRequest extends AiRequest
{
    protected function deniedMessage(): string
    {
        return 'لا تملك صلاحية المسودات.';
    }

    public function authorize(): bool
    {
        return $this->allowed($this->user()->can('viewAny', DraftExam::class));
    }

    public function rules(): array
    {
        return ['status' => ['nullable', Rule::enum(DraftStatus::class)], 'cursor' => ['nullable', 'string']];
    }
}
