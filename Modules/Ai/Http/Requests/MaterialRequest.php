<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Requests;

use Modules\Ai\Models\Material;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** A request about one material (show, retry, download). Someone else's material is "not found", not "forbidden". */
class MaterialRequest extends AiRequest
{
    public function authorize(): bool
    {
        $material = $this->route('material');
        if (! $material instanceof Material) {
            throw new \LogicException('The route names no material.');
        }
        if (! $this->user()->isAnyRole('admin', 'teacher')) {
            return false;
        }
        if (! $this->user()->can('view', $material)) {
            throw new NotFoundHttpException;
        }

        return $this->allowed(true);
    }

    public function rules(): array
    {
        return [];
    }
}
