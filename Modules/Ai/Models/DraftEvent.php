<?php

declare(strict_types=1);

namespace Modules\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Auth\Models\User;

/** One decision on a draft (the review audit). */
class DraftEvent extends Model
{
    public $timestamps = false;

    protected $table = 'ai_draft_events';

    protected $fillable = ['draft_id', 'question_id', 'actor_id', 'action', 'details', 'created_at'];

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
