<?php

declare(strict_types=1);

namespace Modules\Ai\Exceptions;

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

    public static function unavailable(?int $status = null, bool $transient = true): self
    {
        return new self(FailureReason::AI_UNAVAILABLE, $transient, $status);
    }
}
