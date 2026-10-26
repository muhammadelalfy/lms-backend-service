<?php

declare(strict_types=1);

namespace Modules\Ai\Exceptions;

use Illuminate\Http\JsonResponse;
use Modules\Ai\Support\FailureReason;

/**
 * The AI service could not do what was asked. `reason` is a FailureReason code, the message is Arabic
 * and safe to show, `transient` says whether trying again later may work (down, slow, 429, 5xx).
 */
final class AiException extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly bool $transient,
        public readonly ?int $status = null,
        ?string $message = null,
    ) {
        parent::__construct($message ?? (string) FailureReason::message($reason));
    }

    /** When a request waits on the AI service (estimate, regenerate): 503 if it is down, 422 if it refused. */
    public function render(): JsonResponse
    {
        $down = $this->reason === FailureReason::AI_UNAVAILABLE;

        return response()->json(['message' => $this->getMessage(), 'code' => $this->reason], $down ? 503 : 422);
    }

    /** Expected (the AI service is optional), already logged by the client without content. */
    public function report(): bool
    {
        return true;
    }

    public static function unavailable(?int $status = null, bool $transient = true): self
    {
        return new self(FailureReason::AI_UNAVAILABLE, $transient, $status);
    }
}
