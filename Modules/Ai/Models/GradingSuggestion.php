<?php

declare(strict_types=1);

namespace Modules\Ai\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Ai\Enums\SuggestionStatus;
use Modules\Auth\Models\User;
use Modules\Exams\Models\ExamQuestion;
use Modules\Exams\Models\ExamSession;
use Modules\Exams\Models\ExamSessionAnswer;
use Modules\Exams\Models\ExamTemplate;

/** A written answer waiting for (or holding) the teacher's grade, with the AI's suggestion kept for audit. */
class GradingSuggestion extends Model
{
    protected $table = 'ai_grading_suggestions';

    protected $fillable = [
        'session_id', 'question_id', 'session_answer_id', 'template_id', 'status', 'failure_code', 'max_score',
        'suggested_score', 'criteria_scores', 'feedback', 'reasoning', 'confidence', 'injection_suspected',
        'needs_attention', 'prompt_version', 'model_id', 'units_used',
    ];

    protected function casts(): array
    {
        return [
            'status' => SuggestionStatus::class,
            'criteria_scores' => 'array',
            'suggested_score' => 'decimal:2',
            'confidence' => 'float',
            'injection_suspected' => 'boolean',
            'needs_attention' => 'boolean',
            'max_score' => 'integer',
            'units_used' => 'integer',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'session_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ExamQuestion::class, 'question_id');
    }

    public function answer(): BelongsTo
    {
        return $this->belongsTo(ExamSessionAnswer::class, 'session_answer_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ExamTemplate::class, 'template_id');
    }

    public function rubric(): BelongsTo
    {
        return $this->belongsTo(QuestionRubric::class, 'question_id', 'question_id');
    }

    public function grade(): HasOne
    {
        return $this->hasOne(AnswerGrade::class, 'suggestion_id');
    }

    /** What this person may grade: every item for the admin, items of their own exams for a teacher. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAnyRole('admin')) {
            return $query;
        }

        return $query->whereIn('template_id', ExamTemplate::query()->select('id')->where('created_by', $user->id));
    }
}
