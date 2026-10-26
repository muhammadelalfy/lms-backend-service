<?php

declare(strict_types=1);

namespace Modules\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Ai\Enums\JobKind;
use Modules\Ai\Enums\JobStatus;
use Modules\Auth\Models\User;

/** One AI run for a draft: generating it (in batches) or regenerating one of its questions. */
class GenerationJob extends Model
{
    protected $table = 'ai_generation_jobs';

    protected $fillable = [
        'kind', 'blueprint_id', 'blueprint_snapshot', 'draft_id', 'target_question_id', 'instruction', 'requested_by',
        'idempotency_key', 'status', 'estimate_units', 'reserved_units', 'plan', 'questions_requested', 'queued_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => JobKind::class,
            'status' => JobStatus::class,
            'blueprint_snapshot' => 'array',
            'plan' => 'array',
            'shortfall' => 'array',
            'prompt_versions' => 'array',
            'partial' => 'boolean',
            'estimate_units' => 'integer',
            'reserved_units' => 'integer',
            'units_used' => 'integer',
            'batches_done' => 'integer',
            'questions_requested' => 'integer',
            'questions_generated' => 'integer',
            'questions_verified' => 'integer',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(DraftExam::class, 'draft_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
