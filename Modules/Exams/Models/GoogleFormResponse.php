<?php

namespace Modules\Exams\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Students\Models\Student;

/** One submission; answers: [{question_id, answer, correct, points}], student_id null when nobody matched. */
class GoogleFormResponse extends Model
{
    protected $fillable = ['google_form_id', 'response_id', 'respondent_name', 'respondent_email', 'student_id', 'score', 'max_score', 'answers', 'submitted_at'];

    protected $casts = ['answers' => 'array', 'score' => 'decimal:2', 'max_score' => 'decimal:2', 'submitted_at' => 'datetime'];

    public function form(): BelongsTo { return $this->belongsTo(GoogleForm::class, 'google_form_id'); }

    public function student(): BelongsTo { return $this->belongsTo(Student::class); }
}
