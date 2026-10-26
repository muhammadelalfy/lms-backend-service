<?php

declare(strict_types=1);

namespace Modules\Ai\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Ai\Actions\RunGeneration;
use Modules\Ai\Exceptions\AiException;
use Modules\Ai\Models\GenerationJob;
use Modules\Ai\Support\JobError;

/**
 * Runs one generation job on the `ai` queue (separate, so AI latency never starves other queues).
 * Unique per job (`gen:{id}`); idempotent (saved batches are not redone, a charged call is not charged
 * again). A down, slow or rate-limited AI service is retried with backoff (3 attempts), then the job
 * fails with its partial questions kept. Keep REDIS_QUEUE_RETRY_AFTER above `$timeout`.
 */
class RunGenerationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    // Up to 10 batches of 10 questions plus verification.
    public int $timeout = 1800;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $jobId)
    {
        $this->onQueue((string) config('ai.queue'));
    }

    public function uniqueId(): string
    {
        return 'gen:'.$this->jobId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(RunGeneration $run): void
    {
        try {
            $run->handle($this->jobId, final: $this->attempts() >= $this->tries);
        } catch (AiException) {
            $this->release($this->backoff()[min($this->attempts() - 1, 1)]);
        }
    }

    /** Crashed for good (not an AI failure): the job must not keep its reservation. */
    public function failed(?\Throwable $e): void
    {
        $job = GenerationJob::query()->find($this->jobId);
        if ($job !== null) {
            app(RunGeneration::class)->fail($job, JobError::AI_UNAVAILABLE);
        }
    }
}
