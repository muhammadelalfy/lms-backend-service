<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Requests;

use Modules\Ai\Models\ExamBlueprint;

class ListBlueprintsRequest extends AiRequest
{
    protected function deniedMessage(): string
    {
        return 'لا تملك صلاحية معايير الامتحانات.';
    }

    public function authorize(): bool
    {
        return $this->allowed($this->user()->can('viewAny', ExamBlueprint::class));
    }

    public function rules(): array
    {
        return ['templates' => ['sometimes', 'boolean'], 'cursor' => ['nullable', 'string']];
    }
}
