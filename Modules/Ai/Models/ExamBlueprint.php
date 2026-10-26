<?php

declare(strict_types=1);

namespace Modules\Ai\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Auth\Models\User;

/** A teacher's standards for an AI exam, or (is_template) a reusable standards template of the center. */
class ExamBlueprint extends Model
{
    protected $table = 'exam_blueprints';

    protected $fillable = [
        'owner_user_id', 'title', 'is_template', 'is_private', 'grade_level', 'subject', 'outcomes',
        'difficulty_mix', 'bloom_mix', 'type_counts', 'marks_per_type', 'total_marks', 'duration_minutes',
        'language', 'source_selection', 'instructions', 'avoid_topics',
    ];

    protected function casts(): array
    {
        return [
            'is_template' => 'boolean',
            'is_private' => 'boolean',
            'outcomes' => 'array',
            'difficulty_mix' => 'array',
            'bloom_mix' => 'array',
            'type_counts' => 'array',
            'marks_per_type' => 'array',
            'source_selection' => 'array',
            'total_marks' => 'integer',
            'duration_minutes' => 'integer',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** Templates a user may see: their own, and the shared (non-private) ones of the center. */
    public function scopeVisibleTemplatesFor(Builder $query, User $user): Builder
    {
        return $query->where('is_template', true)->when(
            ! $user->isAnyRole('admin'),
            fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('owner_user_id', $user->id)->orWhere('is_private', false)),
        );
    }

    /** @return list<array{type: string, count: int, marks: int}> the question types in the teacher's order */
    public function types(): array
    {
        $types = [];
        foreach ((array) $this->type_counts as $type => $count) {
            $types[] = ['type' => (string) $type, 'count' => (int) $count, 'marks' => (int) ($this->marks_per_type[$type] ?? 1)];
        }

        return $types;
    }

    public function totalQuestions(): int
    {
        return (int) array_sum((array) $this->type_counts);
    }

    /** Everything a generation job needs, frozen: later edits of the blueprint never change a job. */
    public function snapshot(): array
    {
        return [
            'blueprint_id' => $this->id,
            'title' => $this->title,
            'grade_level' => $this->grade_level,
            'subject' => $this->subject,
            'language' => $this->language,
            'outcomes' => array_values((array) $this->outcomes),
            'difficulty_mix' => $this->difficulty_mix,
            'bloom_mix' => $this->bloom_mix,
            'types' => $this->types(),
            'total_marks' => $this->total_marks,
            'duration_minutes' => $this->duration_minutes,
            'sources' => array_values((array) $this->source_selection),
            'instructions' => (string) $this->instructions,
            'avoid_topics' => (string) $this->avoid_topics,
        ];
    }
}
