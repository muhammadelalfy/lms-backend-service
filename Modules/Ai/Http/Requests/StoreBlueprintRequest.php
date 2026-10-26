<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Requests;

use Illuminate\Validation\Validator;
use Modules\Ai\Http\Requests\Concerns\DescribesBlueprint;
use Modules\Ai\Models\ExamBlueprint;

class StoreBlueprintRequest extends AiRequest
{
    use DescribesBlueprint;

    protected function deniedMessage(): string
    {
        return 'لا تملك صلاحية معايير الامتحانات.';
    }

    public function authorize(): bool
    {
        return $this->allowed($this->user()->can('create', ExamBlueprint::class));
    }

    public function rules(): array
    {
        return $this->blueprintRules(partial: false);
    }

    public function messages(): array
    {
        return $this->blueprintMessages();
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->checkBlueprint($validator)];
    }
}
