<?php
namespace Modules\Exams\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Exams\Enums\ExamResultStatus;
use Modules\Students\Models\Student;

class ExamResult extends Model {
    use HasFactory, \Modules\Reports\Models\Concerns\BumpsReportVersion;

    protected static function newFactory(): \Database\Factories\ExamResultFactory
    {
        return \Database\Factories\ExamResultFactory::new();
    }

    /** A result recorded by new code starts as a draft (S6); the column default keeps old code's meaning. */
    protected $attributes = ['status' => 'draft'];

    protected $fillable = ['student_id', 'title', 'score', 'max_score', 'taken_at', 'recorded_by', 'status', 'published_at', 'published_by', 'last_corrected_at', 'session_id', 'score_exact'];

    protected function casts(): array
    {
        return ['taken_at' => 'datetime', 'status' => ExamResultStatus::class, 'published_at' => 'datetime', 'last_corrected_at' => 'datetime', 'score_exact' => 'decimal:2'];
    }

    public function student(): BelongsTo { return $this->belongsTo(Student::class); }

    public function corrections(): HasMany { return $this->hasMany(ExamResultCorrection::class); }

    public function isDraft(): bool
    {
        return $this->status === ExamResultStatus::Draft;
    }

    /** What a student or parent may read: never a draft. */
    public function scopeVisibleToStudents(Builder $query): Builder
    {
        return $query->whereIn('status', ExamResultStatus::visibleValues());
    }
}
