<?php

namespace Modules\Exams\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The Google Form a teacher published from an exam template; question_map: {googleQuestionId: our question id}. */
class GoogleForm extends Model
{
    protected $fillable = ['template_id', 'created_by', 'form_id', 'responder_uri', 'question_map', 'synced_at'];

    protected $casts = ['question_map' => 'array', 'synced_at' => 'datetime'];

    public function template(): BelongsTo { return $this->belongsTo(ExamTemplate::class, 'template_id'); }

    public function responses(): HasMany { return $this->hasMany(GoogleFormResponse::class); }
}
