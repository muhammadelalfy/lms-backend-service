<?php

declare(strict_types=1);

namespace Modules\Ai\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Ai\Enums\JobKind;
use Modules\Ai\Enums\JobStatus;
use Modules\Ai\Enums\UsageKind;
use Modules\Ai\Events\GenerationCompleted;
use Modules\Ai\Exceptions\AiException;
use Modules\Ai\Models\DraftQuestion;
use Modules\Ai\Models\GenerationJob;
use Modules\Ai\Services\AiClient;
use Modules\Ai\Services\DraftAudit;
use Modules\Ai\Services\DraftQuestionWriter;
use Modules\Ai\Services\DraftVerifier;
use Modules\Ai\Services\GenerationPayload;
use Modules\Ai\Services\UnitBudget;
use Modules\Ai\Support\JobError;

/**
 * Runs a generation job: the batches (at most 10 questions each) through /v1/exams/generate, then the
 * verification pass, then the final state. Every finished batch is saved with its ledger row in one
 * transaction, so a retried job resumes after the last saved batch and never pays twice. Each call
 * gets `limit_units` = what is left of the job's reservation, so a job never spends past it; when it
 * runs out the job stops and keeps what it has (partial). No transaction is open during an AI call.
 */
class RunGeneration
{
    public function __construct(
        protected readonly AiClient $ai,
        protected readonly UnitBudget $budget,
        protected readonly GenerationPayload $payload,
        protected readonly DraftQuestionWriter $writer,
        protected readonly DraftVerifier $verifier,
        protected readonly DraftAudit $audit,
    ) {}

    /**
     * Safe to run twice: only an active job is worked on.
     *
     * @param  bool  $final  the last attempt: a transient AI failure then fails the job instead of being retried
     *
     * @throws AiException a transient failure on a non-final attempt (the job stays where it was)
     */
    public function handle(int $jobId, bool $final = true): void
    {
        $job = GenerationJob::query()->whereKey($jobId)->whereIn('status', JobStatus::activeValues())->first();
        if ($job === null) {
            return;
        }
        if ($job->status === JobStatus::Queued) {
            $this->move($job, JobStatus::Retrieving, ['started_at' => $job->started_at ?? now()]);
        }

        try {
            $stoppedByCap = $this->run($job);
        } catch (AiException $e) {
            if ($e->transient && ! $final) {
                throw $e;
            }
            $this->fail($job, $e->transient || $e->reason === JobError::AI_UNAVAILABLE ? JobError::AI_UNAVAILABLE : $e->reason);

            return;
        }
        if ($stoppedByCap !== null) {
            $this->finish($job, $stoppedByCap);
        }
    }

    /** Ends a job that could not run (AI down after the retries, refused): partial results stay in the draft. */
    public function fail(GenerationJob $job, string $code): void
    {
        $generated = (int) GenerationJob::query()->whereKey($job->id)->value('questions_generated');
        $moved = GenerationJob::query()->whereKey($job->id)->whereIn('status', JobStatus::activeValues())->update([
            'status' => JobStatus::Failed->value, 'error_code' => mb_substr($code, 0, 40), 'reserved_units' => 0,
            'partial' => $generated > 0, 'finished_at' => now(),
        ]);
        if ($moved > 0) {
            event(new GenerationCompleted($job->id, $job->draft_id, $job->requested_by, JobStatus::Failed->value));
        }
    }

    /**
     * @return bool|null whether the units ran out (then the job is finished by the caller), null when it was cancelled
     *
     * @throws AiException
     */
    protected function run(GenerationJob $job): ?bool
    {
        if ($job->kind === JobKind::Regenerate) {
            return $this->regenerate($job);
        }
        $snapshot = $job->blueprint_snapshot;
        $plan = (array) $job->plan;
        $capped = false;

        for ($i = $job->batches_done; $i < count($plan); $i++) {
            if ($this->cancelled($job)) {
                return null;
            }
            if ($job->reserved_units <= 0) {
                $capped = true;
                break;
            }
            $this->move($job, JobStatus::Generating);
            $response = $this->ai->generate($this->payload->request($snapshot, $plan[$i]) + [
                'variation_key' => "j{$job->id}b{$i}",
                'limit_units' => max(1, $job->reserved_units),
            ]);

            DB::transaction(function () use ($job, $i, $response, $snapshot) {
                if (! $this->budget->charge($job, UsageKind::Generate, "job:{$job->id}:generate:{$i}", $response['units_used'], $response['model'], $response['prompt_version'])) {
                    return; // this batch was saved by an earlier attempt
                }
                $next = (int) DraftQuestion::query()->where('draft_id', $job->draft_id)->max('ordinal') + 1;
                $saved = $this->writer->insert($job, $response['questions'], $snapshot['ai_sources']['material_ids'], $next, $response['prompt_version'], $response['model']);
                $this->recordBatch($job, $i + 1, count($saved), $response);
            });

            if (collect($response['shortfall'])->contains('reason', 'unit_limit')) {
                $capped = true;
                break;
            }
        }

        if ($this->cancelled($job)) {
            return null;
        }
        $this->move($job, JobStatus::Verifying);
        if (! $this->verifier->verifyJob($job)) {
            $capped = true;
        }

        return $capped;
    }

    /**
     * One replacement question: the new question takes the old one's place (ordinal), the old one stays
     * as history (`replaced`). Nothing changes when the question was removed or the draft published
     * meanwhile (the call is still charged: its units were spent).
     *
     * @throws AiException
     */
    protected function regenerate(GenerationJob $job): ?bool
    {
        $target = DraftQuestion::query()->find($job->target_question_id);
        if ($job->batches_done === 0) {
            if ($target === null || ! $target->state->isLive() || $target->draft()->firstOrFail()->isFrozen()) {
                (new CancelGeneration)->handle($job);

                return null;
            }
            if ($job->reserved_units <= 0) {
                return true;
            }
            $this->move($job, JobStatus::Generating);
            $snapshot = $job->blueprint_snapshot;
            $response = $this->ai->generate($this->payload->request($snapshot) + [
                'variation_key' => "r{$job->id}",
                'limit_units' => max(1, $job->reserved_units),
                'replace_ref' => $target->ref(),
                'instruction' => $job->instruction !== null && $job->instruction !== '' ? $job->instruction : null,
            ]);

            DB::transaction(function () use ($job, $target, $response, $snapshot) {
                if (! $this->budget->charge($job, UsageKind::Regenerate, "job:{$job->id}:regenerate", $response['units_used'], $response['model'], $response['prompt_version'])) {
                    return;
                }
                $locked = DraftQuestion::query()->whereKey($target->id)->lockForUpdate()->first();
                $saved = [];
                if ($locked !== null && $locked->state->isLive() && ! $locked->draft()->firstOrFail()->isFrozen()) {
                    $saved = $this->writer->insert($job, array_slice($response['questions'], 0, 1), $snapshot['ai_sources']['material_ids'],
                        $locked->ordinal, $response['prompt_version'], $response['model'], 'regenerated', $locked->id);
                    if ($saved !== []) {
                        DraftQuestion::query()->whereKey($locked->id)->update(['state' => 'replaced', 'replaced_by_id' => $saved[0], 'updated_at' => now()]);
                        $this->audit->record($job->draft_id, $job->requester()->first(), 'regenerated', $locked->id, ['job_id' => $job->id, 'replacement_id' => $saved[0]]);
                    }
                }
                $this->recordBatch($job, 1, count($saved), $response);
            });
        }

        if ($this->cancelled($job)) {
            return null;
        }
        $this->move($job, JobStatus::Verifying);

        return ! $this->verifier->verifyJob($job);
    }

    /** @param  array{prompt_version: string, model: string, partial: bool, shortfall: list<array>}  $response */
    protected function recordBatch(GenerationJob $job, int $batchesDone, int $saved, array $response): void
    {
        $shortfall = array_merge((array) $job->shortfall, array_map(fn ($s) => [
            'type' => (string) ($s['type'] ?? ''), 'requested' => (int) ($s['requested'] ?? 0),
            'produced' => (int) ($s['produced'] ?? 0), 'reason' => (string) ($s['reason'] ?? 'insufficient_content'),
        ], $response['shortfall']));
        $job->batches_done = $batchesDone;
        $job->questions_generated += $saved;
        $job->shortfall = $shortfall;
        $job->partial = $job->partial || $response['partial'];
        $job->prompt_versions = ['generate' => $response['prompt_version']] + (array) $job->prompt_versions;
        GenerationJob::query()->whereKey($job->id)->update([
            'batches_done' => $batchesDone,
            'questions_generated' => $job->questions_generated,
            'shortfall' => json_encode($shortfall),
            'partial' => $job->partial,
            'model_generate' => mb_substr($response['model'], 0, 120),
            'prompt_versions' => json_encode($job->prompt_versions),
        ]);
    }

    /** ready when every requested question exists, needs_attention when under-filled, failed when none. */
    protected function finish(GenerationJob $job, bool $capped): void
    {
        $generated = (int) GenerationJob::query()->whereKey($job->id)->value('questions_generated');
        $status = match (true) {
            $generated === 0 => JobStatus::Failed,
            $capped || $generated < $job->questions_requested => JobStatus::NeedsAttention,
            default => JobStatus::Ready,
        };
        $error = match (true) {
            $capped => JobError::CAP_REACHED,
            $generated === 0 => JobError::INSUFFICIENT_CONTENT,
            default => null,
        };
        $moved = GenerationJob::query()->whereKey($job->id)->whereIn('status', JobStatus::activeValues())->update([
            'status' => $status->value, 'error_code' => $error, 'reserved_units' => 0, 'finished_at' => now(),
            'partial' => $generated < $job->questions_requested,
        ]);
        if ($moved > 0) {
            event(new GenerationCompleted($job->id, $job->draft_id, $job->requested_by, $status->value));
        }
    }

    protected function move(GenerationJob $job, JobStatus $status, array $extra = []): void
    {
        GenerationJob::query()->whereKey($job->id)->whereIn('status', JobStatus::activeValues())->update(['status' => $status->value] + $extra);
        $job->status = $status;
    }

    protected function cancelled(GenerationJob $job): bool
    {
        return GenerationJob::query()->whereKey($job->id)->toBase()->value('status') === JobStatus::Cancelled->value;
    }
}
