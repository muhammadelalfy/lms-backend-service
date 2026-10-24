<?php

declare(strict_types=1);

namespace Modules\Courses\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Auth\Models\User;

/** What a live course looked like at one point: its outline and the rules that decide completion. */
class CourseVersion extends Model
{
    public $timestamps = false;

    protected $fillable = ['course_id', 'number', 'reason', 'snapshot', 'created_by', 'created_at'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'number' => 'integer', 'created_at' => 'datetime'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
