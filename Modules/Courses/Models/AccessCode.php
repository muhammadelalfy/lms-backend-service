<?php

declare(strict_types=1);

namespace Modules\Courses\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A code a center hands out (printed, sent on WhatsApp) that opens one course for whoever enters it. */
class AccessCode extends Model
{
    protected $table = 'course_access_codes';

    protected $fillable = ['course_id', 'code', 'max_uses', 'expires_at', 'created_by'];

    protected function casts(): array
    {
        return [
            'max_uses' => 'integer',
            'used_count' => 'integer',
            'expires_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isSpent(): bool
    {
        return $this->used_count >= $this->max_uses;
    }

    /** What staff see in the list. */
    public function state(): string
    {
        return match (true) {
            $this->isDisabled() => 'disabled',
            $this->isExpired() => 'expired',
            $this->isSpent() => 'spent',
            default => 'active',
        };
    }

    /** What a person typed, reduced to the stored form: capitals, no spaces or dashes. */
    public static function normalize(string $typed): string
    {
        return strtoupper(preg_replace('/[\s\-_]+/', '', $typed) ?? '');
    }
}
