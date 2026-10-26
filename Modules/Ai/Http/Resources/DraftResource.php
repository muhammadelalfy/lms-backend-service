<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Ai\Enums\QuestionState;
use Modules\Ai\Models\DraftExam;
use Modules\Ai\Models\DraftQuestion;
use Modules\Ai\Support\DraftReadiness;

/**
 * A draft with its questions (`questions` loaded: live ones; with `?history=1` also removed and
 * replaced ones) and what blocks publishing. `jobs` loaded = its active jobs.
 *
 * @mixin DraftExam
 */
class DraftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $detail = $this->relationLoaded('questions');
        $questions = $detail ? $this->questions : collect();
        $live = $questions->filter(fn (DraftQuestion $q) => $q->state->isLive())->values();
        $activeJob = $this->relationLoaded('jobs') ? $this->jobs->first() : null;
        $blockers = $detail ? DraftReadiness::blockers($this->resource, $live, $activeJob !== null) : null;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status->value,
            'template_id' => $this->template_id,
            'total_marks' => $this->total_marks,
            'duration_minutes' => $this->duration_minutes,
            'grade_level' => $this->grade_level,
            'owner_user_id' => $this->owner_user_id,
            'owner' => $this->whenLoaded('owner', fn () => ['id' => $this->owner->id, 'name' => $this->owner->name]),
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'frozen' => $this->isFrozen(),
            'active_job_id' => $activeJob?->id,
            'active_job_kind' => $activeJob?->kind->value,
            $this->mergeWhen($detail, fn () => [
                'marks_sum' => (int) $live->sum('marks'),
                'counts' => [
                    'questions' => $live->count(),
                    'approved' => $live->where('state', QuestionState::Approved)->count(),
                    'pending' => $live->where('state', QuestionState::Pending)->count(),
                    'flagged' => $live->where('flagged', true)->count(),
                ],
                'can_publish' => $blockers === [],
                'blockers' => array_map(fn ($code) => ['code' => $code, 'message' => DraftReadiness::MESSAGES[$code]], (array) $blockers),
                'questions' => DraftQuestionResource::collection($request->boolean('flagged_first')
                    ? $questions->sortBy(fn (DraftQuestion $q) => [$q->flagged ? 0 : 1, $q->ordinal, $q->id])->values()
                    : $questions),
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
