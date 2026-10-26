<?php

declare(strict_types=1);

namespace Modules\Exams\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Auth\Models\User;

/** One changed field of an audited correction of a published exam result (append-only). */
class ExamResultCorrection extends Model
{
    public $timestamps = false;

    protected $fillable = ['exam_result_id', 'correction_group_id', 'field', 'old_value', 'new_value', 'reason', 'corrected_by', 'corrected_at'];

    protected function casts(): array
    {
        return ['corrected_at' => 'datetime'];
    }

    public function corrector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }
}
