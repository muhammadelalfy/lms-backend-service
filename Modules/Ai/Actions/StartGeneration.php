<?php

declare(strict_types=1);

namespace Modules\Ai\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Ai\Enums\DraftStatus;
use Modules\Ai\Enums\JobKind;
use Modules\Ai\Enums\JobStatus;
use Modules\Ai\Events\GenerationRequested;
use Modules\Ai\Exceptions\AiException;
use Modules\Ai\Exceptions\CapReached;
use Modules\Ai\Exceptions\GenerationRefused;
use Modules\Ai\Jobs\RunGenerationJob;
use Modules\Ai\Models\DraftExam;
use Modules\Ai\Models\ExamBlueprint;
use Modules\Ai\Models\GenerationJob;
use Modules\Ai\Services\AiClient;
use Modules\Ai\Services\GenerationPayload;
use Modules\Ai\Services\UnitBudget;
use Modules\Auth\Models\User;

/**
 * Starts a generation job from a blueprint: estimate (no LLM), reserve the units (refused with
 * `cap_reached` when they do not fit), create the empty draft and the job with a frozen blueprint
 * snapshot, then queue it after the commit. One job per Idempotency-Key: the same key returns the
 * same job, also when the response cache has forgotten it.
 */
final class StartGeneration
{
    public function __construct(private readonly AiClient $ai, private readonly UnitBudget $budget, private readonly GenerationPayload $payload) {}

    /**
     * @throws GenerationRefused no ready material
     * @throws CapReached the month's units do not cover the estimate
     * @throws AiException the AI service is down (nothing was created)
     */
    public function handle(User $actor, ExamBlueprint $blueprint, ?string $idempotencyKey): GenerationJob
    {
        $key = $idempotencyKey === null ? null : $actor->id.':'.$idempotencyKey;
        if ($key !== null && ($existing = GenerationJob::query()->where('idempotency_key', $key)->first()) !== null) {
            return $existing;
        }

        $snapshot = $blueprint->snapshot();
        $snapshot['ai_sources'] = $this->payload->resolveSources($snapshot['sources'], $actor);
        $estimate = $this->ai->estimate($this->payload->request($snapshot));

        try {
            $job = $this->budget->reserve($estimate['units'], function (int $held) use ($actor, $blueprint, $snapshot, $estimate, $key) {
                $draft = DraftExam::create([
                    'owner_user_id' => $actor->id, 'title' => $snapshot['title'], 'status' => DraftStatus::Draft->value,
                    'total_marks' => $snapshot['total_marks'], 'duration_minutes' => $snapshot['duration_minutes'],
                    'grade_level' => $snapshot['grade_level'],
                ]);

                return GenerationJob::create([
                    'kind' => JobKind::Generate->value, 'blueprint_id' => $blueprint->id, 'blueprint_snapshot' => $snapshot,
                    'draft_id' => $draft->id, 'requested_by' => $actor->id, 'idempotency_key' => $key,
                    'status' => JobStatus::Queued->value, 'estimate_units' => $estimate['units'], 'reserved_units' => $held,
                    'plan' => $this->payload->plan($snapshot['types']),
                    'questions_requested' => array_sum(array_column($snapshot['types'], 'count')),
                    'queued_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // The same key arrived twice at once: the other request created the job.
            return GenerationJob::query()->where('idempotency_key', $key)->firstOrFail();
        }

        event(new GenerationRequested($job->id, $job->draft_id, $actor->id, JobKind::Generate->value));
        RunGenerationJob::dispatch($job->id);

        return $job;
    }
}
