<?php

declare(strict_types=1);

namespace Modules\Courses\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Courses\Enums\EnrollmentStatus;
use Modules\Courses\Events\EnrollmentCreated;
use Modules\Courses\Exceptions\ContentRuleViolation;
use Modules\Courses\Models\Course;
use Modules\Courses\Models\Enrollment;
use Modules\Payments\Models\Payment;
use Modules\Students\Models\Student;

/**
 * Puts a student in a course. A free course is active at once. A paid one creates an ordinary payment for
 * the student and waits (pending) for it to be paid, through the existing online or staff-recorded flow.
 */
final class EnrollStudent
{
    public function __construct(private readonly ActivateEnrollment $activate) {}

    public function handle(Course $course, User $user, Student $student): Enrollment
    {
        $existing = $this->existing($course, $student);
        if ($existing) {
            return $this->joined($existing);
        }

        try {
            $enrollment = DB::transaction(function () use ($course, $user, $student): Enrollment {
                $price = $course->finalPrice();
                $payment = $price === null ? null : Payment::create([
                    'student_id' => $student->id,
                    'amount' => $price,
                    'status' => 'pending',
                    'due_at' => now()->toDateString(),
                    'note' => 'اشتراك في دورة: '.$course->title,
                    'recorded_by' => $user->id,
                ]);

                $enrollment = new Enrollment(['course_id' => $course->id, 'student_id' => $student->id, 'payment_id' => $payment?->id]);
                $enrollment->course_version = $course->version;
                $enrollment->save();
                EnrollmentCreated::dispatch($enrollment->id, $course->id, $student->id);

                return $enrollment;
            });
        } catch (UniqueConstraintViolationException) {
            // A double click or a retry: the first request won, so this one answers with its enrollment.
            return $this->joined($this->existing($course, $student) ?? throw new \LogicException('The enrollment vanished.'));
        }

        if ($enrollment->payment_id === null) {
            $this->activate->handle($enrollment);
        }

        return $enrollment->refresh()->load('payment');
    }

    private function existing(Course $course, Student $student): ?Enrollment
    {
        return Enrollment::query()->where(['course_id' => $course->id, 'student_id' => $student->id])->first();
    }

    private function joined(Enrollment $enrollment): Enrollment
    {
        if ($enrollment->status === EnrollmentStatus::Cancelled) {
            throw ContentRuleViolation::enrollmentCancelled();
        }

        return $enrollment->load('payment');
    }
}
