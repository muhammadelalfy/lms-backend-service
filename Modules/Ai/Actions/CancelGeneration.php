<?php

declare(strict_types=1);

namespace Modules\Ai\Actions;

use Modules\Ai\Enums\JobStatus;
use Modules\Ai\Models\GenerationJob;
use Modules\Ai\Support\JobError;

/** Stops a job that has not finished; it gives back its reservation and keeps the questions saved so far. */
final class CancelGeneration
{
    /** @return bool false when the job already finished */
    public function handle(GenerationJob $job): bool
    {
        return GenerationJob::query()->whereKey($job->id)->whereIn('status', JobStatus::activeValues())->update([
            'status' => JobStatus::Cancelled->value, 'error_code' => JobError::CANCELLED, 'reserved_units' => 0, 'finished_at' => now(),
        ]) > 0;
    }
}
