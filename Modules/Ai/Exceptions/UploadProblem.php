<?php

declare(strict_types=1);

namespace Modules\Ai\Exceptions;

use Illuminate\Http\JsonResponse;

/** A chunked upload that cannot go on; carries the HTTP status and a stable code the web app reacts to. */
final class UploadProblem extends \RuntimeException
{
    /** @param array<string, mixed> $extra */
    public function __construct(string $message, public readonly string $reason, public readonly int $status, public readonly array $extra = [])
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->reason] + $this->extra, $this->status);
    }
}
