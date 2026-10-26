<?php

declare(strict_types=1);

namespace Modules\Ai\Actions;

use Modules\Ai\Enums\JobStatus;
use Modules\Ai\Exceptions\AiException;
use Modules\Ai\Exceptions\CapReached;
use Modules\Ai\Exceptions\GenerationRefused;
use Modules\Ai\Jobs\RunGenerationJob;
use Modules\Ai\Models\GenerationJob;
use Modules\Ai\Services\AiClient;
use Modules\Ai\Services\GenerationPayload;
use Modules\Ai\Services\UnitBudget;
use Modules\Ai\Support\JobError;

/**
 * Runs a stopped job again (AI was down, or the units ran out and were topped up): it resumes after
 * its last saved batch. It reserves what the rest should cost (the estimate minus what it already
 * spent) under the same cap rule as a new job.
 */
final class RetryGeneration
{
    public function __construct(private readonly AiClient $ai, private readonly UnitBudget $budget, private readonly GenerationPayload $payload) {}

    public static function retryable(GenerationJob $job): bool
    {
        return $job->status === JobStatus::Failed && $job->error_code !== JobError::INSUFFICIENT_CONTENT
            || ($job->status === JobStatus::NeedsAttention && $job->error_code === JobError::CAP_REACHED);
    }

    /**
     * @throws GenerationRefused the job cannot be retried or its draft was published
     * @throws CapReached
     * @throws AiException
     */
    public function handle(GenerationJob $job): GenerationJob
    {
        if (! self::retryable($job) || $job->loadMissing('draft')->draft->isFrozen()) {
            throw new GenerationRefused('not_retryable', 'لا يمكن إعادة محاولة هذا التوليد.', 409);
        }
        // A regeneration's snapshot already holds just its one question type.
        $estimate = $this->ai->estimate($this->payload->request($job->blueprint_snapshot));
        $rest = max(1, $estimate['units'] - $job->units_used);

        $this->budget->reserve($rest, function (int $held) use ($job) {
            $moved = GenerationJob::query()->whereKey($job->id)->whereIn('status', [JobStatus::Failed->value, JobStatus::NeedsAttention->value])
                ->update(['status' => JobStatus::Queued->value, 'error_code' => null, 'reserved_units' => $held, 'finished_at' => null, 'queued_at' => now()]);
            if ($moved === 0) {
                throw new GenerationRefused('not_retryable', 'لا يمكن إعادة محاولة هذا التوليد.', 409);
            }
        });
        RunGenerationJob::dispatch($job->id);

        return $job->refresh();
    }
}
