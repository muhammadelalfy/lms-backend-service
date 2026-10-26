<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Ai\Enums\MaterialStatus;
use Modules\Ai\Models\Material;

class ListMaterialsRequest extends AiRequest
{
    public function authorize(): bool
    {
        return $this->allowed($this->user()->can('viewAny', Material::class));
    }

    public function rules(): array
    {
        return ['status' => ['nullable', Rule::enum(MaterialStatus::class)], 'cursor' => ['nullable', 'string']];
    }
}
