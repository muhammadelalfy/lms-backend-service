<?php

declare(strict_types=1);

namespace Modules\Ai\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Ai\Actions\RunGradingSuggestion;
use Modules\Ai\Exceptions\AiException;
use Modules\Ai\Support\GradingNote;

/**
 * Asks the AI to suggest a grade for one written answer (`grade:{suggestion id}`), on the `ai` queue.
 * Idempotent: only a `queued` item is sent, and the call is charged once. A down, slow or rate-limited
 * service is retried with backoff (3 attempts); after that the item is `failed` and the teacher grades
 * it by hand, nothing else waits on it.
 */
class SuggestGradeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $suggestionId)
    {
        $this->onQueue((string) config('ai.queue'));
    }

    public function uniqueId(): string
    {
        return 'grade:'.$this->suggestionId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [20, 90];
    }

    public function handle(RunGradingSuggestion $run): void
    {
        try {
            // A sync queue (development) cannot release a job for later: its only attempt is the last.
            $sync = $this->job === null || $this->job->getConnectionName() === 'sync';
            $run->handle($this->suggestionId, final: $sync || $this->attempts() >= $this->tries);
        } catch (AiException) {
            $this->release($this->backoff()[min($this->attempts() - 1, 1)]);
        }
    }

    public function failed(?\Throwable $e): void
    {
        app(RunGradingSuggestion::class)->fail($this->suggestionId, GradingNote::AI_UNAVAILABLE);
    }
}
