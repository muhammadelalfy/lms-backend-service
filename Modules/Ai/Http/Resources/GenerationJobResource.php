<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Ai\Actions\RetryGeneration;
use Modules\Ai\Enums\JobStatus;
use Modules\Ai\Models\GenerationJob;
use Modules\Ai\Support\JobError;

/** @mixin GenerationJob */
class GenerationJobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $requested = $this->questions_requested;
        $generated = $this->questions_generated;
        $batches = count((array) $this->plan);
        // Generation is most of the work; verification the rest.
        $percent = match (true) {
            $this->status === JobStatus::Ready || $this->status === JobStatus::NeedsAttention => 100,
            $batches === 0 => 0,
            default => (int) min(99, round(80 * $this->batches_done / $batches + 20 * ($generated > 0 ? $this->questions_verified / $generated : 0))),
        };

        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'status' => $this->status->value,
            'blueprint_id' => $this->blueprint_id,
            'draft_id' => $this->draft_id,
            'target_question_id' => $this->target_question_id,
            'estimate_units' => $this->estimate_units,
            'reserved_units' => $this->reserved_units,
            'units_used' => $this->units_used,
            'partial' => $this->partial,
            'progress' => [
                'batches_total' => $batches,
                'batches_done' => $this->batches_done,
                'questions_requested' => $requested,
                'questions_generated' => $generated,
                'questions_verified' => $this->questions_verified,
                'percent' => $percent,
            ],
            'shortfall' => array_values((array) $this->shortfall),
            'notice' => $this->notice($requested, $generated),
            'error_code' => $this->error_code,
            'error_message' => JobError::message($this->error_code),
            'can_cancel' => $this->status->isActive(),
            'can_retry' => RetryGeneration::retryable($this->resource),
            'model_generate' => $this->model_generate,
            'model_verify' => $this->model_verify,
            'prompt_versions' => (object) ((array) $this->prompt_versions),
            'queued_at' => $this->queued_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** The under-fill sentence: "تم توليد 12 من 20: المحتوى لا يكفي". */
    private function notice(int $requested, int $generated): ?string
    {
        if ($this->status->isActive() || $generated >= $requested || $generated === 0) {
            return null;
        }
        $why = $this->error_code === JobError::CAP_REACHED ? 'انتهت وحدات الشهر' : 'المحتوى لا يكفي';

        return "تم توليد {$generated} من {$requested}: {$why}";
    }
}
