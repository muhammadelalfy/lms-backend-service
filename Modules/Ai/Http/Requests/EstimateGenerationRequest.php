<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Requests;

use Illuminate\Validation\Validator;
use Modules\Ai\DTOs\BlueprintData;
use Modules\Ai\Http\Requests\Concerns\DescribesBlueprint;
use Modules\Ai\Models\ExamBlueprint;
use Modules\Ai\Models\GenerationJob;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** The cost of a saved blueprint (`blueprint_id`) or of one being filled in (the blueprint fields inline). */
class EstimateGenerationRequest extends AiRequest
{
    use DescribesBlueprint;

    private ?ExamBlueprint $stored = null;

    protected function deniedMessage(): string
    {
        return 'لا تملك صلاحية توليد الامتحانات.';
    }

    public function authorize(): bool
    {
        if (! $this->user()->can('create', GenerationJob::class)) {
            return false;
        }
        if ($this->filled('blueprint_id') && $this->storedBlueprint() === null) {
            throw new NotFoundHttpException('معايير الامتحان غير موجودة.');
        }

        return $this->allowed(true);
    }

    public function rules(): array
    {
        if ($this->filled('blueprint_id')) {
            return ['blueprint_id' => ['required', 'integer']];
        }

        return array_merge($this->blueprintRules(partial: false), ['sources' => ['required', 'array', 'min:1', 'max:'.(int) config('ai.generation.max_materials')]]);
    }

    public function messages(): array
    {
        return $this->blueprintMessages() + ['sources.required' => 'اختر مادة واحدة على الأقل.', 'sources.min' => 'اختر مادة واحدة على الأقل.'];
    }

    public function after(): array
    {
        return $this->filled('blueprint_id') ? [] : [fn (Validator $validator) => $this->checkBlueprint($validator)];
    }

    /** The frozen form of what is being estimated. */
    public function snapshot(): array
    {
        $stored = $this->storedBlueprint();
        if ($stored !== null) {
            return $stored->snapshot();
        }

        return (new ExamBlueprint(BlueprintData::fromValidated($this->validated())->forCreate()))->snapshot();
    }

    /** A blueprint the user may run: their own (any, for the admin). */
    private function storedBlueprint(): ?ExamBlueprint
    {
        if ($this->stored === null && is_numeric($this->input('blueprint_id'))) {
            $blueprint = ExamBlueprint::query()->find((int) $this->input('blueprint_id'));
            $this->stored = $blueprint !== null && $this->user()->can('update', $blueprint) ? $blueprint : null;
        }

        return $this->stored;
    }
}
