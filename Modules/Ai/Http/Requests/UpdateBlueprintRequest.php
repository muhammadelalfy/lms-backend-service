<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Requests;

use Illuminate\Validation\Validator;
use Modules\Ai\Http\Requests\Concerns\DescribesBlueprint;

/** Edit or rename a blueprint: only the sent fields change. */
class UpdateBlueprintRequest extends BlueprintRequest
{
    use DescribesBlueprint;

    protected string $ability = 'update';

    public function rules(): array
    {
        return $this->blueprintRules(partial: true);
    }

    public function messages(): array
    {
        return $this->blueprintMessages();
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->checkBlueprint($validator, $this->blueprint())];
    }
}
