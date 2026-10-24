<?php

declare(strict_types=1);

namespace Modules\Courses\Tests\Feature;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Modules\Auth\Models\User;
use Modules\Courses\Actions\ActivateEnrollment;
use Modules\Courses\Actions\EnrollStudent;
use Modules\Courses\Enums\EnrollmentStatus;
use Modules\Courses\Events\CourseCompleted;
use Modules\Courses\Events\EnrollmentActivated;
use Modules\Courses\Events\EnrollmentCancelled;
use Modules\Courses\Events\EnrollmentCreated;
use Modules\Courses\Events\EnrollmentExpired;
use Modules\Courses\Events\LessonCompleted;
use Modules\Courses\Models\CourseLesson;
use Modules\Courses\Models\Enrollment;
use Modules\Courses\Tests\Concerns\BuildsCourses;
use Modules\Payments\Events\PaymentSucceeded;
use Modules\Payments\Models\Payment;
use Modules\Students\Models\Student;
use Modules\Students\Models\StudentAccount;
use Tests\TestCase;

/** pending → active → completed | cancelled | expired, and the facts it announces. */
class EnrollmentLifecycleTest extends TestCase
{
    use BuildsCourses;
    use RefreshDatabase;

    public function test_the_state_machine_allows_only_the_documented_moves(): void
    {
        $this->assertTrue(EnrollmentStatus::Pending->canTransitionTo(EnrollmentStatus::Active));
        $this->assertTrue(EnrollmentStatus::Pending->canTransitionTo(EnrollmentStatus::Cancelled));
        $this->assertFalse(EnrollmentStatus::Pending->canTransitionTo(EnrollmentStatus::Completed));
        $this->assertTrue(EnrollmentStatus::Active->canTransitionTo(EnrollmentStatus::Completed));
        $this->assertTrue(EnrollmentStatus::Active->canTransitionTo(EnrollmentStatus::Expired));
        $this->assertTrue(EnrollmentStatus::Expired->canTransitionTo(EnrollmentStatus::Active), 'an expired enrollment can be renewed');
        $this->assertSame([], array_filter(EnrollmentStatus::cases(), fn ($to) => EnrollmentStatus::Completed->canTransitionTo($to)));
        $this->assertSame([], array_filter(EnrollmentStatus::cases(), fn ($to) => EnrollmentStatus::Cancelled->canTransitionTo($to)));
    }

    public function test_every_course_and_payment_fact_is_dispatched_only_after_the_change_commits(): void
    {
        foreach ([new EnrollmentCreated(1, 1, 1), new EnrollmentActivated(1, 1, 1), new EnrollmentExpired(1, 1, 1), new EnrollmentCancelled(1, 1, 1, null), new LessonCompleted(1, 1, 1, 1), new CourseCompleted(1, 1, 1, 1), new PaymentSucceeded(1, 1)] as $event) {
            $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event, $event::class);
        }
    }

    public function test_a_free_course_is_active_at_once_and_announces_it(): void
    {
        Event::fake([EnrollmentCreated::class, EnrollmentActivated::class]);
        $course = $this->course();
        [, $student] = $this->learner();

        $this->postJson("/api/catalog/{$course->slug}/enroll")->assertCreated()->assertJsonPath('enrollment.status', 'active');

        $enrollment = Enrollment::firstOrFail();
        $this->assertSame([EnrollmentStatus::Active, 1], [$enrollment->status, $enrollment->course_version]);
        $this->assertNotNull($enrollment->activated_at);
        Event::assertDispatchedTimes(EnrollmentCreated::class, 1);
        Event::assertDispatched(EnrollmentActivated::class, fn ($e) => $e->studentId === $student->id && $e->courseId === $course->id);
    }

    public function test_a_paid_course_waits_pending_and_opens_by_itself_when_its_payment_is_paid(): void
    {
        $course = $this->course(['pricing' => 'paid', 'price_amount' => 500]);
        $this->learner();
        $paymentId = $this->postJson("/api/catalog/{$course->slug}/enroll")->assertCreated()->assertJsonPath('enrollment.status', 'pending')->json('enrollment.payment_id');
        $this->assertSame(EnrollmentStatus::Pending, Enrollment::firstOrFail()->status);

        Event::fake([EnrollmentActivated::class]);
        // However the payment gets settled (online, QR, staff), nobody has to open the course for it to unlock.
        Payment::findOrFail($paymentId)->update(['status' => 'paid', 'paid_at' => now()->toDateString()]);

        $this->assertSame(EnrollmentStatus::Active, Enrollment::firstOrFail()->status);
        Event::assertDispatchedTimes(EnrollmentActivated::class, 1);
    }

    public function test_a_payment_that_is_not_paid_leaves_the_enrollment_waiting(): void
    {
        $course = $this->course(['pricing' => 'paid', 'price_amount' => 500]);
        $this->learner();
        $paymentId = $this->postJson("/api/catalog/{$course->slug}/enroll")->json('enrollment.payment_id');

        Payment::findOrFail($paymentId)->update(['status' => 'overdue']);
        Payment::findOrFail($paymentId)->update(['note' => 'تذكير']);

        $this->assertSame(EnrollmentStatus::Pending, Enrollment::firstOrFail()->status);
    }

    public function test_the_payment_fact_is_announced_once_per_payment_not_per_save(): void
    {
        Event::fake([PaymentSucceeded::class]);
        $student = Student::factory()->create();
        $payment = Payment::create(['student_id' => $student->id, 'amount' => 100, 'status' => 'pending', 'due_at' => now()->toDateString(), 'recorded_by' => $this->admin()->id]);

        $payment->update(['status' => 'paid', 'paid_at' => now()->toDateString()]);
        $payment->update(['note' => 'تم']);
        Payment::create(['student_id' => $student->id, 'amount' => 50, 'status' => 'paid', 'due_at' => now()->toDateString(), 'recorded_by' => $this->admin()->id]);

        Event::assertDispatchedTimes(PaymentSucceeded::class, 2); // the first payment once, the second as it was created paid
    }

    public function test_a_payment_settled_without_an_event_is_still_noticed_when_the_student_looks(): void
    {
        $course = $this->course(['pricing' => 'paid', 'price_amount' => 500]);
        $this->learner();
        $paymentId = $this->postJson("/api/catalog/{$course->slug}/enroll")->json('enrollment.payment_id');

        // A raw query raises no model event.
        Payment::whereKey($paymentId)->update(['status' => 'paid', 'paid_at' => now()->toDateString()]);
        $this->assertSame(EnrollmentStatus::Pending, Enrollment::firstOrFail()->status);

        $this->getJson("/api/my-courses/{$course->id}")->assertOk()->assertJsonPath('enrollment.status', 'active');
        $this->assertSame(EnrollmentStatus::Active, Enrollment::firstOrFail()->status);
    }

    public function test_activating_twice_changes_nothing_and_announces_once(): void
    {
        Event::fake([EnrollmentActivated::class]);
        $course = $this->course(['pricing' => 'paid', 'price_amount' => 100]);
        [$user, $student] = $this->learner();
        $enrollment = app(EnrollStudent::class)->handle($course, $user, $student);

        app(ActivateEnrollment::class)->handle($enrollment);
        app(ActivateEnrollment::class)->handle($enrollment);

        Event::assertDispatchedTimes(EnrollmentActivated::class, 1);
    }

    public function test_joining_twice_makes_one_enrollment_and_one_payment(): void
    {
        $course = $this->course(['pricing' => 'paid', 'price_amount' => 100]);
        [$user, $student] = $this->learner();

        $first = app(EnrollStudent::class)->handle($course, $user, $student);
        $second = app(EnrollStudent::class)->handle($course, $user, $student);

        $this->assertSame($first->id, $second->id);
        $this->assertSame([1, 1], [Enrollment::count(), Payment::count()]);
    }

    public function test_access_ends_with_the_access_period_and_a_scheduled_job_records_it(): void
    {
        Event::fake([EnrollmentExpired::class]);
        $course = $this->course(['access_days' => 30]);
        $this->learner();
        $this->postJson("/api/catalog/{$course->slug}/enroll")->assertCreated();
        $enrollment = Enrollment::firstOrFail();
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $enrollment->expires_at->timestamp, 5);
        $lessonId = $this->getJson("/api/my-courses/{$course->id}")->assertOk()->json('subjects.0.lessons.0.id');

        $this->travel(31)->days();

        // By the clock alone the student is out, even before the job has run.
        $this->getJson("/api/my-courses/{$course->id}")->assertOk()->assertJsonPath('enrollment.status', 'expired');
        $this->assertStringNotContainsString('videos.example', $this->getJson("/api/my-courses/{$course->id}")->getContent());
        $this->postJson("/api/course-lessons/{$lessonId}/complete")->assertForbidden()->assertJsonPath('message', 'انتهى اشتراكك في هذه الدورة. تواصل مع إدارة المدرسة لتجديده.');
        $this->assertSame(EnrollmentStatus::Active, $enrollment->fresh()->status, 'not recorded yet');

        Artisan::call('courses:expire-enrollments');
        $this->assertSame(EnrollmentStatus::Expired, $enrollment->fresh()->status);
        Artisan::call('courses:expire-enrollments');
        Event::assertDispatchedTimes(EnrollmentExpired::class, 1);
    }

    public function test_an_expired_enrollment_can_be_renewed_with_a_fresh_period(): void
    {
        $course = $this->course(['access_days' => 10]);
        [$user, $student] = $this->learner();
        $enrollment = app(EnrollStudent::class)->handle($course, $user, $student);
        $this->travel(11)->days();
        Artisan::call('courses:expire-enrollments');

        app(ActivateEnrollment::class)->handle($enrollment->fresh());

        $renewed = $enrollment->fresh();
        $this->assertSame(EnrollmentStatus::Active, $renewed->status);
        $this->assertTrue($renewed->expires_at->isFuture());
        $this->getJson("/api/my-courses/{$course->id}")->assertOk()->assertJsonPath('enrollment.status', 'active');
    }

    public function test_finishing_every_lesson_completes_the_enrollment_once_and_announces_it(): void
    {
        Event::fake([LessonCompleted::class, CourseCompleted::class]);
        $course = $this->course(lessons: 2);
        $this->learner();
        $this->postJson("/api/catalog/{$course->slug}/enroll")->assertCreated();
        $ids = collect($this->getJson("/api/my-courses/{$course->id}")->json('subjects.0.lessons'))->pluck('id');

        $this->postJson("/api/course-lessons/{$ids[0]}/complete")->assertOk()->assertJsonPath('course_completed', false);
        $this->postJson("/api/course-lessons/{$ids[1]}/complete")->assertOk()->assertJsonPath('course_completed', true);
        $this->postJson("/api/course-lessons/{$ids[1]}/complete")->assertOk(); // again: nothing new

        $enrollment = Enrollment::firstOrFail();
        $this->assertSame([EnrollmentStatus::Completed, 1], [$enrollment->status, $enrollment->completed_version]);
        Event::assertDispatchedTimes(LessonCompleted::class, 2);
        Event::assertDispatchedTimes(CourseCompleted::class, 1);
        Event::assertDispatched(CourseCompleted::class, fn ($e) => $e->courseVersion === 1 && $e->enrollmentId === $enrollment->id);
    }

    public function test_a_completed_enrollment_keeps_its_access_even_past_the_access_period(): void
    {
        $course = $this->course(['access_days' => 5], lessons: 1);
        $this->learner();
        $this->postJson("/api/catalog/{$course->slug}/enroll")->assertCreated();
        $lessonId = $this->getJson("/api/my-courses/{$course->id}")->json('subjects.0.lessons.0.id');
        $this->postJson("/api/course-lessons/{$lessonId}/complete")->assertOk()->assertJsonPath('course_completed', true);

        $this->travel(30)->days();
        Artisan::call('courses:expire-enrollments');

        $this->assertSame(EnrollmentStatus::Completed, Enrollment::firstOrFail()->status);
        $this->getJson("/api/my-courses/{$course->id}")->assertOk()->assertJsonPath('enrollment.status', 'completed')
            ->assertJsonPath('subjects.0.lessons.0.video_url', 'https://videos.example/1');
    }

    public function test_staff_who_run_the_course_can_cancel_an_enrollment_and_the_student_is_locked_out(): void
    {
        $teacher = $this->teacher();
        $course = $this->course();
        $course->instructors()->attach($teacher->id, ['position' => 0]);
        [, $student] = $this->learner();
        $this->postJson("/api/catalog/{$course->slug}/enroll")->assertCreated();
        $lessonId = $this->getJson("/api/my-courses/{$course->id}")->json('subjects.0.lessons.0.id');
        $enrollment = Enrollment::firstOrFail();

        Event::fake([EnrollmentCancelled::class]);
        Sanctum::actingAs($teacher);
        $this->postJson("/api/courses/{$course->id}/enrollments/{$enrollment->id}/cancel")->assertNoContent();
        Event::assertDispatched(EnrollmentCancelled::class, fn ($e) => $e->actorId === $teacher->id && $e->studentId === $student->id);

        $this->assertSame(EnrollmentStatus::Cancelled, $enrollment->fresh()->status);
        Sanctum::actingAs($student->account->user ?? User::findOrFail(StudentAccount::where('student_id', $student->id)->value('user_id')));
        $closed = $this->getJson("/api/my-courses/{$course->id}")->assertOk()->assertJsonPath('enrollment.status', 'cancelled');
        $this->assertStringNotContainsString('videos.example', $closed->getContent());
        $this->postJson("/api/course-lessons/{$lessonId}/complete")->assertForbidden()->assertJsonPath('message', 'أُلغي تسجيلك في هذه الدورة. تواصل مع إدارة المدرسة.');
        $this->postJson("/api/catalog/{$course->slug}/enroll")->assertUnprocessable();
    }

    public function test_only_staff_of_the_course_cancel_and_only_an_open_enrollment_can_be_cancelled(): void
    {
        $course = $this->course(lessons: 1);
        [$user] = $this->learner();
        $this->postJson("/api/catalog/{$course->slug}/enroll")->assertCreated();
        $enrollment = Enrollment::firstOrFail();

        $this->postJson("/api/courses/{$course->id}/enrollments/{$enrollment->id}/cancel")->assertForbidden(); // a student
        Sanctum::actingAs($this->teacher());
        $this->postJson("/api/courses/{$course->id}/enrollments/{$enrollment->id}/cancel")->assertForbidden(); // a teacher who does not teach it

        Sanctum::actingAs($this->admin());
        $other = $this->course();
        $this->postJson("/api/courses/{$other->id}/enrollments/{$enrollment->id}/cancel")->assertNotFound(); // not this course's enrollment

        $lessonId = CourseLesson::firstOrFail()->id;
        Sanctum::actingAs($user);
        $this->postJson("/api/course-lessons/{$lessonId}/complete")->assertOk(); // completes the one-lesson course
        Sanctum::actingAs($this->admin());
        $this->postJson("/api/courses/{$course->id}/enrollments/{$enrollment->id}/cancel")->assertUnprocessable(); // completed
    }

    public function test_the_staff_list_shows_each_enrollment_with_its_state_and_versions(): void
    {
        $course = $this->course(['access_days' => 7]);
        $this->learner();
        $this->postJson("/api/catalog/{$course->slug}/enroll")->assertCreated();

        Sanctum::actingAs($this->admin());
        $row = $this->getJson("/api/courses/{$course->id}/enrollments")->assertOk()->json('data.0');
        $this->assertSame(['active', 1, null], [$row['status'], $row['course_version'], $row['completed_version']]);
        $this->assertNotNull($row['expires_at']);
    }
}
