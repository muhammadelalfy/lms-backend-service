<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Requests;

use Modules\Ai\Models\ExamBlueprint;

/** Copy a blueprint or a shared template into a blueprint (or template) of one's own. */
class DuplicateBlueprintRequest extends BlueprintRequest
{
    public function authorize(): bool
    {
        return parent::authorize() && $this->user()->can('create', ExamBlueprint::class);
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:300'],
            'is_template' => ['sometimes', 'boolean'],
            'is_private' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['title.max' => 'العنوان طويل جداً.'];
    }
}
