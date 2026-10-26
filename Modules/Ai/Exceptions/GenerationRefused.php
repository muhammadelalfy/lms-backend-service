<?php

declare(strict_types=1);

namespace Modules\Ai\Exceptions;

use Illuminate\Http\JsonResponse;

/** A generation, review or publish action that cannot be done in the current state: 422 or 409 with a code. */
final class GenerationRefused extends \RuntimeException
{
    /** @param  array<string, mixed>  $extra */
    public function __construct(public readonly string $reason, string $message, public readonly int $status = 422, public readonly array $extra = [])
    {
        parent::__construct($message);
    }

    /** An expected refusal, not an error to log. */
    public function report(): bool
    {
        return true;
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->reason] + $this->extra, $this->status);
    }
}
