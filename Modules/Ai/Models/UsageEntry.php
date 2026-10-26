<?php

declare(strict_types=1);

namespace Modules\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Ai\Enums\UsageKind;

/** AI units spent by one AI call (the ledger the monthly cap is counted from). */
class UsageEntry extends Model
{
    public $timestamps = false;

    protected $table = 'ai_usage_ledger';

    protected $fillable = [
        'kind', 'job_id', 'draft_id', 'user_id', 'units', 'input_tokens', 'output_tokens', 'model_id', 'prompt_version',
        'month', 'call_key', 'created_at',
    ];

    protected function casts(): array
    {
        return ['kind' => UsageKind::class, 'units' => 'integer', 'created_at' => 'datetime'];
    }
}
