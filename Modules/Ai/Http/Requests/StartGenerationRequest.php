<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Requests;

use Modules\Ai\Models\ExamBlueprint;
use Modules\Ai\Models\GenerationJob;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Start generating from a saved blueprint of one's own (the teacher confirmed the estimate). */
class StartGenerationRequest extends AiRequest
{
    private ?ExamBlueprint $blueprint = null;

    protected function deniedMessage(): string
    {
        return 'لا تملك صلاحية توليد الامتحانات.';
    }

    public function authorize(): bool
    {
        if (! $this->user()->can('create', GenerationJob::class)) {
            return false;
        }
        $blueprint = is_numeric($this->input('blueprint_id')) ? ExamBlueprint::query()->find((int) $this->input('blueprint_id')) : null;
        if ($blueprint !== null && ! $this->user()->can('update', $blueprint)) {
            throw new NotFoundHttpException('معايير الامتحان غير موجودة.');
        }
        $this->blueprint = $blueprint;

        return $this->allowed(true);
    }

    public function rules(): array
    {
        return ['blueprint_id' => ['required', 'integer', function ($attribute, $value, $fail) {
            if ($this->blueprint === null) {
                $fail('معايير الامتحان غير موجودة.');
            }
        }]];
    }

    public function messages(): array
    {
        return ['blueprint_id.required' => 'اختر معايير الامتحان.', 'blueprint_id.integer' => 'معايير الامتحان غير موجودة.'];
    }

    public function blueprint(): ExamBlueprint
    {
        return $this->blueprint ?? throw new \LogicException('Validated without a blueprint.');
    }
}
