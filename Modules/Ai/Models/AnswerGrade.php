<?php

declare(strict_types=1);

namespace Modules\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Ai\Enums\GradeDecision;
use Modules\Auth\Models\User;

/** The teacher's final grade of one written answer: who decided, when, and how. */
class AnswerGrade extends Model
{
    protected $table = 'exam_answer_grades';

    protected $fillable = [
        'suggestion_id', 'session_id', 'question_id', 'session_answer_id', 'final_score', 'final_feedback',
        'decision', 'decided_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return ['decision' => GradeDecision::class, 'final_score' => 'decimal:2', 'decided_at' => 'datetime'];
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
