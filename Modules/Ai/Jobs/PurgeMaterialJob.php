<?php

declare(strict_types=1);

namespace Modules\Ai\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Ai\Exceptions\AiException;
use Modules\Ai\Services\AiClient;

/** Asks the AI service to delete a deleted material's chunks and vectors (queue `ai`). Safe to repeat. */
class PurgeMaterialJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 8;

    public int $timeout = 60;

    public function __construct(public readonly int $materialId)
    {
        $this->onQueue((string) config('ai.queue'));
    }

    /** @return list<int> retries for hours: vectors must be gone within a day */
    public function backoff(): array
    {
        return [60, 300, 900, 1800, 3600, 7200, 14400];
    }

    public function handle(AiClient $ai): void
    {
        try {
            $ai->deleteMaterial($this->materialId);
        } catch (AiException $e) {
            if (! $e->transient || $this->attempts() >= $this->tries) {
                logger()->error('AI material purge failed', ['material_id' => $this->materialId, 'reason' => $e->reason]);

                return;
            }
            $this->release($this->backoff()[min($this->attempts() - 1, 6)]);
        }
    }
}
