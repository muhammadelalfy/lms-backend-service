<?php

declare(strict_types=1);

namespace Modules\Courses\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Auth\Models\User;
use Modules\Courses\Enums\ContentStatus;
use Modules\Courses\Models\Course;
use Modules\Courses\Models\Enrollment;
use Modules\Payments\Models\Payment;
use Modules\Students\Models\Student;
use Modules\Students\Models\StudentAccount;
use Tests\TestCase;

/** What the school offers and what a student gets: the catalog, joining, paying, lessons and progress. */
class CourseEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    /** A published course with one published section and `$lessons` published lessons; lesson 1 is live. */
    private function course(array $attributes = [], int $lessons = 2): Course
    {
        $course = Course::create($attributes + ['title' => 'الرياضيات', 'slug' => 'math-'.Str::random(6)]);
        $subject = $course->subjects()->make(['title' => 'القسم الأول']);
        $subject->position = 1;
        $subject->status = ContentStatus::Published;
        $subject->published_at = now();
        $subject->save();

        for ($i = 1; $i <= $lessons; $i++) {
            $lesson = $subject->lessons()->make([
                'title' => "درس {$i}",
                'video_url' => "https://videos.example/{$i}",
                ...($i === 1 ? ['live_at' => now()->addDay(), 'live_provider' => 'meet', 'live_link' => 'https://meet.example/room'] : []),
            ]);
            $lesson->position = $i;
            $lesson->status = ContentStatus::Published;
            $lesson->published_at = now();
            $lesson->save();
        }
        $course->status = ContentStatus::Published;
        $course->published_at = now();
        $course->save();

        return $course;
    }

    /** @return array{0: User, 1: Student} */
    private function learner(string $role = 'student'): array
    {
        $student = Student::factory()->create();
        $user = User::factory()->create(['role' => $role]);
        StudentAccount::create(['user_id' => $user->id, 'student_id' => $student->id, 'relationship' => $role]);
        Sanctum::actingAs($user);

        return [$user, $student];
    }

    public function test_the_catalog_lists_only_published_visible_courses_and_never_the_paid_content(): void
    {
        $open = $this->course(['title' => 'منشورة']);
        $draft = $this->course(['title' => 'مسودة']);
        $draft->forceFill(['status' => ContentStatus::Draft])->save();
        $hidden = $this->course(['title' => 'مخفية']);
        $hidden->forceFill(['status' => ContentStatus::Hidden])->save();
        $later = $this->course(['title' => 'لاحقاً', 'visible_from' => now()->addWeek()]);
        $expired = $this->course(['title' => 'منتهية', 'visible_until' => now()->subDay()]);
        $this->learner();

        $titles = collect($this->getJson('/api/catalog')->assertOk()->json('data'))->pluck('title')->all();
        $this->assertSame(['منشورة'], $titles);

        $detail = $this->getJson("/api/catalog/{$open->slug}")->assertOk()->assertJsonPath('subjects.0.lessons.0.title', 'درس 1')
            ->assertJsonPath('subjects.0.lessons.0.is_live', true)->assertJsonPath('enrollment', null);
        $this->assertStringNotContainsString('videos.example', $detail->getContent());
        $this->assertStringNotContainsString('meet.example', $detail->getContent());

        $this->getJson("/api/catalog/{$draft->slug}")->assertNotFound();
        $this->getJson("/api/catalog/{$hidden->slug}")->assertNotFound();
    }

    public function test_catalog_filters_by_pricing(): void
    {
        $this->course(['title' => 'مجانية']);
        $this->course(['title' => 'مدفوعة', 'pricing' => 'paid', 'price_amount' => 300]);
        $this->learner();

        $this->assertSame(['مدفوعة'], collect($this->getJson('/api/catalog?pricing=paid')->json('data'))->pluck('title')->all());
        $this->assertSame(['مجانية'], collect($this->getJson('/api/catalog?pricing=free')->json('data'))->pluck('title')->all());
        $this->getJson('/api/catalog?pricing=bogus')->assertUnprocessable();
    }

    public function test_a_student_joins_a_free_course_at_once_and_sees_its_lessons_including_the_live_link(): void
    {
        $course = $this->course();
        [, $student] = $this->learner();

        $this->postJson("/api/catalog/{$course->slug}/enroll")->assertCreated()->assertJsonPath('enrollment.status', 'active');
        $this->postJson("/api/catalog/{$course->slug}/enroll")->assertOk(); // joining twice changes nothing
        $this->assertSame(1, Enrollment::where('student_id', $student->id)->count());
        $this->assertSame(0, Payment::count());

        $page = $this->getJson("/api/my-courses/{$course->id}")->assertOk()
            ->assertJsonPath('subjects.0.lessons.0.video_url', 'https://videos.example/1')
            ->assertJsonPath('subjects.0.lessons.0.live_link', 'https://meet.example/room')
            ->assertJsonPath('subjects.0.lessons.0.completed', false)
            ->assertJsonPath('progress.total_lessons', 2);
        $this->assertSame('active', $page->json('enrollment.status'));

        $this->getJson('/api/my-courses')->assertOk()->assertJsonPath('data.0.progress.completed_lessons', 0);
        $this->getJson("/api/catalog/{$course->slug}")->assertJsonPath('enrollment.status', 'active');
    }

    public function test_a_parent_can_join_for_their_child(): void
    {
        $course = $this->course();
        [, $student] = $this->learner('parent');

        $this->postJson("/api/catalog/{$course->slug}/enroll")->assertCreated();
        $this->assertTrue(Enrollment::where('student_id', $student->id)->exists());
    }

    public function test_a_paid_course_makes_a_pending_payment_and_opens_only_when_it_is_paid(): void
    {
        $course = $this->course(['pricing' => 'paid', 'price_amount' => 500, 'discounted_price_amount' => 400]);
        [$user, $student] = $this->learner();

        $join = $this->postJson("/api/catalog/{$course->slug}/enroll")->assertCreated()
            ->assertJsonPath('enrollment.status', 'pending')->assertJsonPath('enrollment.amount', 400);
        $payment = Payment::findOrFail($join->json('enrollment.payment_id'));
        $this->assertSame([$student->id, 400, 'pending', $user->id], [$payment->student_id, $payment->amount, $payment->status, $payment->recorded_by]);
        $this->assertStringContainsString('الرياضيات', $payment->note);

        // The lessons are listed but their content is not handed out, and nothing can be completed.
        $locked = $this->getJson("/api/my-courses/{$course->id}")->assertOk()->assertJsonPath('enrollment.status', 'pending');
        $this->assertStringNotContainsString('videos.example', $locked->getContent());
        $this->assertStringNotContainsString('meet.example', $locked->getContent());
        $lessonId = $locked->json('subjects.0.lessons.0.id');
        $this->postJson("/api/course-lessons/{$lessonId}/complete")->assertStatus(402);
        $this->postJson("/api/catalog/{$course->slug}/enroll")->assertOk();
        $this->assertSame(1, Payment::count());

        // Staff settle it the usual way; the course opens with no further step.
        $payment->update(['status' => 'paid', 'paid_at' => now()->toDateString()]);
        $this->getJson("/api/my-courses/{$course->id}")->assertOk()->assertJsonPath('enrollment.status', 'active')
            ->assertJsonPath('subjects.0.lessons.0.video_url', 'https://videos.example/1');
        $this->postJson("/api/course-lessons/{$lessonId}/complete")->assertOk();
    }

    public function test_an_overdue_payment_keeps_the_course_closed(): void
    {
        $course = $this->course(['pricing' => 'paid', 'price_amount' => 500]);
        $this->learner();
        $paymentId = $this->postJson("/api/catalog/{$course->slug}/enroll")->json('enrollment.payment_id');
        Payment::whereKey($paymentId)->update(['status' => 'overdue']);

        $this->getJson("/api/my-courses/{$course->id}")->assertJsonPath('enrollment.status', 'pending');
    }

    public function test_staff_cannot_join_courses_and_a_student_must_join_before_opening_one(): void
    {
        $course = $this->course();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson("/api/catalog/{$course->slug}/enroll")->assertForbidden();
        $this->getJson('/api/my-courses')->assertForbidden();

        $this->learner();
        $this->getJson("/api/my-courses/{$course->id}")->assertNotFound();
        $this->postJson('/api/catalog/no-such-course/enroll')->assertNotFound();
    }

    public function test_a_student_cannot_open_another_students_course_page(): void
    {
        $course = $this->course();
        $this->learner();
        $this->postJson("/api/catalog/{$course->slug}/enroll")->assertCreated();

        $this->learner();
        $this->getJson("/api/my-courses/{$course->id}")->assertNotFound();
        $this->getJson('/api/my-courses')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_hidden_course_keeps_its_students_but_an_archived_one_is_closed(): void
    {
        $course = $this->course();
        $this->learner();
        $this->postJson("/api/catalog/{$course->slug}/enroll")->assertCreated();

        $course->forceFill(['status' => ContentStatus::Hidden])->save();
        $this->getJson("/api/my-courses/{$course->id}")->assertOk();
        $this->getJson('/api/catalog/'.$course->slug)->assertNotFound();

        $course->forceFill(['status' => ContentStatus::Archived])->save();
        $this->getJson("/api/my-courses/{$course->id}")->assertNotFound();
        $this->getJson('/api/my-courses')->assertJsonCount(0, 'data');
    }

    public function test_sequenced_lessons_open_one_after_the_one_before_and_finishing_all_completes_the_course(): void
    {
        $course = $this->course(['sequencing_mode' => 'after_previous_completed'], lessons: 3);
        $this->learner();
        $this->postJson("/api/catalog/{$course->slug}/enroll")->assertCreated();

        $ids = collect($this->getJson("/api/my-courses/{$course->id}")->assertOk()
            ->assertJsonPath('subjects.0.lessons.0.locked', false)->assertJsonPath('subjects.0.lessons.1.locked', true)
            ->assertJsonPath('subjects.0.lessons.2.locked', true)->json('subjects.0.lessons'))->pluck('id');
        // A locked lesson hands out none of its content.
        $locked = $this->getJson("/api/my-courses/{$course->id}")->json('subjects.0.lessons.1');
        $this->assertArrayNotHasKey('video_url', $locked);

        $this->postJson("/api/course-lessons/{$ids[1]}/complete")->assertForbidden();
        $this->postJson("/api/course-lessons/{$ids[0]}/complete")->assertOk()->assertJsonPath('completed_lessons', 1)->assertJsonPath('course_completed', false);
        $this->getJson("/api/my-courses/{$course->id}")->assertJsonPath('subjects.0.lessons.1.locked', false)->assertJsonPath('subjects.0.lessons.2.locked', true);
        $this->postJson("/api/course-lessons/{$ids[2]}/complete")->assertForbidden();
        $this->postJson("/api/course-lessons/{$ids[1]}/complete")->assertOk();
        $this->postJson("/api/course-lessons/{$ids[1]}/complete")->assertOk()->assertJsonPath('completed_lessons', 2); // idempotent
        $this->postJson("/api/course-lessons/{$ids[2]}/complete")->assertOk()->assertJsonPath('course_completed', true);

        $this->getJson('/api/my-courses')->assertJsonPath('data.0.enrollment.status', 'completed')->assertJsonPath('data.0.progress.completed_lessons', 3);
    }

    public function test_open_sequencing_lets_a_student_take_lessons_in_any_order(): void
    {
        $course = $this->course(lessons: 3);
        $this->learner();
        $this->postJson("/api/catalog/{$course->slug}/enroll");
        $ids = collect($this->getJson("/api/my-courses/{$course->id}")->json('subjects.0.lessons'))->pluck('id');

        $this->postJson("/api/course-lessons/{$ids[2]}/complete")->assertOk()->assertJsonPath('completed_lessons', 1);
    }

    public function test_unpublished_lessons_are_neither_shown_nor_completable(): void
    {
        $course = $this->course(lessons: 2);
        $draft = $course->lessons()->first();
        $draft->forceFill(['status' => ContentStatus::Draft])->save();
        $this->learner();
        $this->postJson("/api/catalog/{$course->slug}/enroll");

        $lessons = $this->getJson("/api/my-courses/{$course->id}")->assertJsonPath('progress.total_lessons', 1)->json('subjects.0.lessons');
        $this->assertCount(1, $lessons);
        $this->postJson("/api/course-lessons/{$draft->id}/complete")->assertNotFound();
    }

    public function test_staff_see_who_is_enrolled_and_how_far_they_are(): void
    {
        $course = $this->course(['pricing' => 'paid', 'price_amount' => 100], lessons: 2);
        $teacher = User::factory()->create(['role' => 'teacher']);
        $course->instructors()->attach($teacher->id, ['position' => 0]);

        [, $paying] = $this->learner();
        $paymentId = $this->postJson("/api/catalog/{$course->slug}/enroll")->json('enrollment.payment_id');
        Payment::whereKey($paymentId)->update(['status' => 'paid', 'paid_at' => now()->toDateString()]);
        $lesson = $this->getJson("/api/my-courses/{$course->id}")->json('subjects.0.lessons.0.id');
        $this->postJson("/api/course-lessons/{$lesson}/complete")->assertOk();
        $this->learner();
        $this->postJson("/api/catalog/{$course->slug}/enroll");

        Sanctum::actingAs($teacher);
        $rows = collect($this->getJson("/api/courses/{$course->id}/enrollments")->assertOk()->json('data'))->keyBy('student.id');
        $this->assertCount(2, $rows);
        $this->assertSame(['active', 1, 2], [$rows[$paying->id]['status'], $rows[$paying->id]['completed_lessons'], $rows[$paying->id]['published_lessons']]);
        $this->assertSame(['pending', 0], [$rows->except($paying->id)->first()['status'], $rows->except($paying->id)->first()['completed_lessons']]);

        Sanctum::actingAs(User::factory()->create(['role' => 'teacher']));
        $this->getJson("/api/courses/{$course->id}/enrollments")->assertForbidden();
    }
}
