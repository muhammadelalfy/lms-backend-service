<?php

declare(strict_types=1);

namespace Modules\Ai\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Ai\Enums\JobKind;
use Modules\Ai\Enums\JobStatus;
use Modules\Ai\Events\GenerationRequested;
use Modules\Ai\Exceptions\AiException;
use Modules\Ai\Exceptions\CapReached;
use Modules\Ai\Exceptions\GenerationRefused;
use Modules\Ai\Jobs\RunGenerationJob;
use Modules\Ai\Models\DraftExam;
use Modules\Ai\Models\DraftQuestion;
use Modules\Ai\Models\GenerationJob;
use Modules\Ai\Services\AiClient;
use Modules\Ai\Services\DraftAudit;
use Modules\Ai\Services\GenerationPayload;
use Modules\Ai\Services\UnitBudget;
use Modules\Auth\Models\User;

/**
 * "Regenerate one": a job (kind regenerate) that asks the AI for one replacement question from the
 * same blueprint slice (type, marks, outcomes, sources), with the teacher's optional instruction.
 * It costs units, so it is estimated and reserved like a generation; the original question is kept
 * as history (state `replaced`). One job per Idempotency-Key; one regeneration per question at a time.
 */
final class RegenerateDraftQuestion
{
    public function __construct(
        private readonly AiClient $ai,
        private readonly UnitBudget $budget,
        private readonly GenerationPayload $payload,
        private readonly DraftAudit $audit,
    ) {}

    /**
     * @throws GenerationRefused frozen draft, removed question, or a regeneration already running
     * @throws CapReached
     * @throws AiException the AI service is down (nothing was created)
     */
    public function handle(User $actor, DraftExam $draft, DraftQuestion $question, ?string $instruction, ?string $idempotencyKey): GenerationJob
    {
        $key = $idempotencyKey === null ? null : $actor->id.':'.$idempotencyKey;
        if ($key !== null && ($existing = GenerationJob::query()->where('idempotency_key', $key)->first()) !== null) {
            return $existing;
        }
        if ($draft->isFrozen()) {
            throw new GenerationRefused('draft_published', 'تم نشر هذه المسودة؛ عدّل الامتحان من محرر الامتحانات.', 409);
        }
        if (! $question->state->isLive()) {
            throw new GenerationRefused('question_not_live', 'هذا السؤال محذوف أو تم استبداله.', 409);
        }
        if ($this->running($draft, $question)) {
            throw new GenerationRefused('regenerating', 'يجري توليد بديل لهذا السؤال بالفعل.', 409);
        }
        $source = GenerationJob::query()->where('draft_id', $draft->id)->where('kind', JobKind::Generate->value)->oldest('id')->firstOrFail();

        $snapshot = $source->blueprint_snapshot;
        $wanted = (array) $question->outcome_ids;
        $outcomes = array_values(array_filter((array) $snapshot['outcomes'], fn ($o) => in_array($o['id'], $wanted, true)));
        $snapshot['outcomes'] = $outcomes ?: $snapshot['outcomes'];
        $snapshot['types'] = [['type' => $question->type->value, 'count' => 1, 'marks' => $question->marks]];
        $snapshot['total_marks'] = $question->marks;
        $estimate = $this->ai->estimate($this->payload->request($snapshot));

        try {
            $job = $this->budget->reserve($estimate['units'], function (int $held) use ($actor, $draft, $question, $instruction, $key, $snapshot, $source, $estimate) {
                if ($this->running($draft, $question)) {
                    throw new GenerationRefused('regenerating', 'يجري توليد بديل لهذا السؤال بالفعل.', 409);
                }
                $job = GenerationJob::create([
                    'kind' => JobKind::Regenerate->value, 'blueprint_id' => $source->blueprint_id, 'blueprint_snapshot' => $snapshot,
                    'draft_id' => $draft->id, 'target_question_id' => $question->id, 'instruction' => $instruction,
                    'requested_by' => $actor->id, 'idempotency_key' => $key, 'status' => JobStatus::Queued->value,
                    'estimate_units' => $estimate['units'], 'reserved_units' => $held, 'plan' => [$snapshot['types']],
                    'questions_requested' => 1, 'queued_at' => now(),
                ]);
                $this->audit->record($draft->id, $actor, 'regenerate_requested', $question->id, ['job_id' => $job->id, 'with_instruction' => $instruction !== null && $instruction !== '']);

                return $job;
            });
        } catch (UniqueConstraintViolationException) {
            return GenerationJob::query()->where('idempotency_key', $key)->firstOrFail();
        }

        event(new GenerationRequested($job->id, $draft->id, $actor->id, JobKind::Regenerate->value));
        RunGenerationJob::dispatch($job->id);

        return $job;
    }

    private function running(DraftExam $draft, DraftQuestion $question): bool
    {
        return GenerationJob::query()->where('draft_id', $draft->id)->where('target_question_id', $question->id)
            ->whereIn('status', JobStatus::activeValues())->exists();
    }
}
