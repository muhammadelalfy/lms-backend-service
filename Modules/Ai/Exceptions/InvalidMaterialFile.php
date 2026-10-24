<?php

declare(strict_types=1);

namespace Modules\Ai\Exceptions;

use Illuminate\Http\JsonResponse;

/** The uploaded file is not an acceptable material: 422 in the same shape as a validation error. */
final class InvalidMaterialFile extends \RuntimeException
{
    /** An expected refusal, not an error to log. */
    public function report(): bool
    {
        return true;
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => 'invalid_file', 'errors' => ['file' => [$this->getMessage()]]], 422);
    }
}
