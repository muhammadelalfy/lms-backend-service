<?php

namespace Modules\Exams\Models;

use Modules\Students\Models\Student;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamSession extends Model
{
    use HasFactory;

    protected static function newFactory(): \Database\Factories\ExamSessionFactory
    {
        return \Database\Factories\ExamSessionFactory::new();
    }

    protected $fillable = ['template_id', 'student_id', 'started_at', 'submitted_at', 'status', 'camera_required', 'fullscreen_required', 'focus_loss_count', 'last_event_at', 'grading_state'];
    protected $casts = ['started_at' => 'datetime', 'submitted_at' => 'datetime', 'last_event_at' => 'datetime', 'camera_required' => 'boolean', 'fullscreen_required' => 'boolean', 'focus_loss_count' => 'integer'];

    public function template(): BelongsTo { return $this->belongsTo(ExamTemplate::class, 'template_id'); }
    public function student(): BelongsTo { return $this->belongsTo(Student::class); }
    public function answers(): HasMany { return $this->hasMany(ExamSessionAnswer::class, 'session_id'); }
    public function events(): HasMany { return $this->hasMany(ExamSessionEvent::class, 'session_id'); }
}
