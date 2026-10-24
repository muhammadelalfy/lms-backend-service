<?php

declare(strict_types=1);

namespace Modules\Courses\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Courses\Enums\EnrollmentStatus;
use Modules\Courses\Exceptions\ContentRuleViolation;
use Modules\Payments\Models\Payment;
use Modules\Students\Models\Student;

/** A student's place in a course; its status, not the payment, is what decides access. */
class Enrollment extends Model
{
    protected $table = 'course_enrollments';

    protected $fillable = ['course_id', 'student_id', 'payment_id'];

    protected $attributes = ['status' => 'pending', 'course_version' => 1];

    protected function casts(): array
    {
        return [
            'status' => EnrollmentStatus::class,
            'activated_at' => 'datetime',
            'expires_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
            'course_version' => 'integer',
            'completed_version' => 'integer',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Whether the student may open the lessons now: while active and not past its end, or for good once
     * completed (someone who finished keeps the course to review).
     */
    public function grantsAccess(): bool
    {
        return match ($this->status) {
            EnrollmentStatus::Completed => true,
            EnrollmentStatus::Active => $this->expires_at === null || $this->expires_at->isFuture(),
            default => false,
        };
    }

    public function transitionTo(EnrollmentStatus $target): void
    {
        if (! $this->status->canTransitionTo($target)) {
            throw ContentRuleViolation::invalidEnrollmentTransition($this->status, $target);
        }
        $this->status = $target;
    }
}
