<?php

declare(strict_types=1);

namespace Modules\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Ai\Enums\DraftStatus;
use Modules\Auth\Models\User;

/** The exam the AI drafted, reviewed by the teacher, then published once into Exams (and frozen). */
class DraftExam extends Model
{
    protected $table = 'ai_draft_exams';

    protected $fillable = ['owner_user_id', 'title', 'status', 'total_marks', 'duration_minutes', 'grade_level'];

    protected function casts(): array
    {
        return [
            'status' => DraftStatus::class,
            'total_marks' => 'integer',
            'duration_minutes' => 'integer',
            'template_id' => 'integer',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(DraftQuestion::class, 'draft_id')->orderBy('ordinal')->orderBy('id');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(GenerationJob::class, 'draft_id');
    }

    public function isFrozen(): bool
    {
        return $this->status === DraftStatus::Published;
    }
}
