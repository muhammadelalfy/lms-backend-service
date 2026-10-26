<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Ai\Models\DraftQuestion;

/** @mixin DraftQuestion */
class DraftQuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ordinal' => $this->ordinal,
            'type' => $this->type->value,
            'exam_type' => $this->type->examType(),
            'prompt' => $this->prompt,
            'options' => $this->options,
            'correct_answer' => $this->correct_answer,
            'explanation' => $this->explanation,
            'model_answer' => $this->model_answer,
            'rubric' => $this->rubric,
            'marks' => $this->marks,
            'difficulty' => $this->difficulty,
            'bloom' => $this->bloom,
            'outcome_ids' => array_values((array) $this->outcome_ids),
            'citation' => $this->source_chunk_ref === null && $this->source_quote === null ? null : [
                'material_id' => $this->source_material_id,
                'chunk_ref' => $this->source_chunk_ref,
                'label' => $this->source_label,
                'quote' => $this->source_quote,
                'ocr' => $this->source_ocr,
            ],
            'verification' => $this->verify_verdict === null && $this->verify_issues === null ? null : [
                'verdict' => $this->verify_verdict,
                'confidence' => $this->verify_confidence,
                'issues' => array_values((array) $this->verify_issues),
                'suggested_fix' => $this->verify_suggested_fix,
                'duplicate_of' => $this->duplicate_of,
                'bank_match' => $this->bank_match,
            ],
            'flagged' => $this->flagged,
            // Unsupported questions never go through "approve all unflagged".
            'auto_approvable' => ! $this->flagged && $this->verify_verdict !== null && $this->verify_verdict !== 'unsupported',
            'state' => $this->state->value,
            'origin' => $this->origin,
            'edited_by_teacher' => $this->edited_by_teacher,
            'replaces_id' => $this->replaces_id,
            'replaced_by_id' => $this->replaced_by_id,
            'prompt_version' => $this->prompt_version,
            'model_id' => $this->model_id,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
