<?php

declare(strict_types=1);

namespace Modules\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Ai\Enums\QuestionState;
use Modules\Ai\Enums\QuestionType;

/** One question of a draft with its provenance (cited material) and verification result. */
class DraftQuestion extends Model
{
    protected $table = 'ai_draft_questions';

    protected $fillable = [
        'draft_id', 'job_id', 'ordinal', 'type', 'prompt', 'options', 'correct_answer', 'explanation', 'model_answer',
        'rubric', 'marks', 'difficulty', 'bloom', 'outcome_ids', 'source_material_id', 'source_chunk_ref', 'source_label',
        'source_quote', 'source_ocr', 'ai_ref', 'state', 'origin', 'replaces_id', 'prompt_version', 'model_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => QuestionType::class,
            'state' => QuestionState::class,
            'options' => 'array',
            'rubric' => 'array',
            'outcome_ids' => 'array',
            'verify_issues' => 'array',
            'verify_confidence' => 'float',
            'flagged' => 'boolean',
            'source_ocr' => 'boolean',
            'edited_by_teacher' => 'boolean',
            'ordinal' => 'integer',
            'marks' => 'integer',
            'source_material_id' => 'integer',
            'duplicate_of' => 'integer',
            'replaces_id' => 'integer',
            'replaced_by_id' => 'integer',
        ];
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(DraftExam::class, 'draft_id');
    }

    /** The id the AI service knows this question by (verify, regenerate). */
    public function ref(): string
    {
        return 'd'.$this->id;
    }
}
