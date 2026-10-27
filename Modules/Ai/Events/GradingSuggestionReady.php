<?php

declare(strict_types=1);

namespace Modules\Ai\Events;

/** The AI's suggestion for a written answer is saved; dispatched after the transaction committed. */
final class GradingSuggestionReady
{
    public function __construct(public readonly int $suggestionId, public readonly int $sessionId) {}
}
