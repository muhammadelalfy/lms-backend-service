<?php

declare(strict_types=1);

namespace Modules\Ai\Exceptions;

use Illuminate\Http\JsonResponse;

/** A cap of the plan (materials count or material storage) is used up: 402. */
final class CapReached extends \RuntimeException
{
    public function __construct(public readonly string $cap, string $message)
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
        return response()->json(['message' => $this->getMessage(), 'code' => 'cap_reached', 'cap' => $this->cap], 402);
    }
}
