<?php

declare(strict_types=1);

namespace Modules\Ai\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Ai\Actions\ExtractMaterial;
use Modules\Ai\Exceptions\AiException;

/**
 * Extracts a material and ingests its chunks (queue `ai`). Idempotent: the action only works on a
 * material still waiting. A down or slow AI service is retried with backoff, then the material fails.
 * Keep REDIS_QUEUE_RETRY_AFTER above `$timeout`.
 */
class ExtractMaterialJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 150;

    public function __construct(public readonly int $materialId)
    {
        $this->onQueue((string) config('ai.queue'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(ExtractMaterial $extract): void
    {
        try {
            $extract->handle($this->materialId, final: $this->attempts() >= $this->tries);
        } catch (AiException) {
            $this->release($this->backoff()[min($this->attempts() - 1, 1)]);
        }
    }
}
