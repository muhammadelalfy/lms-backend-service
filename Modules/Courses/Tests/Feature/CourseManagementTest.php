<?php

declare(strict_types=1);

namespace Modules\Courses\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Modules\Auth\Models\User;
use Modules\Courses\Enums\ContentStatus;
use Modules\Courses\Models\Course;
use Modules\Courses\Models\CourseLesson;
use Modules\Courses\Models\CourseSubject;
use Tests\TestCase;

/** Running courses: who may, and the publish, delete, reorder and pricing rules. */
class CourseManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function teacher(): User
    {
        return User::factory()->create(['role' => 'teacher']);
    }

    private function create(array $overrides = []): array
    {
        return $overrides + ['title' => 'الرياضيات للمرحلة الثانوية', 'summary' => 'دورة شاملة'];
    }

    /** A draft course with one section holding one draft lesson, built through the API. */
    private function outlined(): array
    {
        $courseId = $this->postJson('/api/courses', $this->create())->assertCreated()->json('id');
        $subjectId = $this->postJson("/api/courses/{$courseId}/subjects", ['title' => 'الجبر'])->assertCreated()->json('id');
        $lessonId = $this->postJson("/api/course-subjects/{$subjectId}/lessons", ['title' => 'المعادلات'])->assertCreated()->json('id');

        return [$courseId, $subjectId, $lessonId];
    }

    public function test_an_admin_creates_a_draft_course_with_a_readable_unique_address(): void
    {
        Sanctum::actingAs($this->admin());

        $first = $this->postJson('/api/courses', $this->create())->assertCreated()
            ->assertJsonPath('status', 'draft')->assertJsonPath('pricing.model', 'free')->assertJsonPath('kind', 'course');
        $second = $this->postJson('/api/courses', $this->create())->assertCreated();

        $this->assertSame('الرياضيات-للمرحلة-الثانوية', $first->json('slug'));
        $this->assertSame('الرياضيات-للمرحلة-الثانوية-2', $second->json('slug'));
    }

    public function test_a_teacher_who_creates_a_course_teaches_it_and_sees_only_their_own(): void
    {
        $mine = $this->teacher();
        $other = $this->teacher();
        Sanctum::actingAs($other);
        $theirs = $this->postJson('/api/courses', $this->create(['title' => 'دورة الآخر']))->assertCreated()->json('id');

        Sanctum::actingAs($mine);
        $own = $this->postJson('/api/courses', $this->create(['title' => 'دورتي']))->assertCreated();
        $this->assertSame([$mine->id], collect($own->json('instructors'))->pluck('id')->all());

        $titles = collect($this->getJson('/api/courses')->assertOk()->json('data'))->pluck('title')->all();
        $this->assertSame(['دورتي'], $titles);
        $this->getJson("/api/courses/{$theirs}")->assertForbidden();
        $this->patchJson("/api/courses/{$theirs}", ['title' => 'x'])->assertForbidden();

        Sanctum::actingAs($this->admin());
        $this->getJson('/api/courses')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_an_admin_who_creates_a_course_is_not_made_its_instructor_and_names_the_teachers(): void
    {
        $teacher = $this->teacher();
        Sanctum::actingAs($this->admin());

        $id = $this->postJson('/api/courses', $this->create())->assertCreated()->assertJsonPath('instructors', [])->json('id');
        $this->putJson("/api/courses/{$id}/instructors", ['instructor_ids' => [$teacher->id]])->assertOk()->assertJsonPath('instructors.0.id', $teacher->id);

        $named = $this->postJson('/api/courses', $this->create(['instructor_ids' => [$teacher->id]]))->assertCreated();
        $this->assertSame([$teacher->id], collect($named->json('instructors'))->pluck('id')->all());
    }

    public function test_students_and_parents_cannot_run_courses(): void
    {
        foreach (['student', 'parent'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->postJson('/api/courses', $this->create())->assertForbidden();
            $this->getJson('/api/courses')->assertForbidden();
        }
    }

    public function test_only_an_admin_names_the_teachers_and_only_teachers_can_be_named(): void
    {
        $teacher = $this->teacher();
        $student = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($teacher);
        $id = $this->postJson('/api/courses', $this->create())->assertCreated()->json('id');
        $this->putJson("/api/courses/{$id}/instructors", ['instructor_ids' => [$teacher->id]])->assertForbidden();

        Sanctum::actingAs($this->admin());
        $this->putJson("/api/courses/{$id}/instructors", ['instructor_ids' => [$student->id]])->assertUnprocessable();
        $other = $this->teacher();
        $this->putJson("/api/courses/{$id}/instructors", ['instructor_ids' => [$other->id, $teacher->id]])
            ->assertOk()->assertJsonPath('instructors.0.id', $other->id)->assertJsonPath('instructors.1.id', $teacher->id);
    }

    public function test_a_course_cannot_be_published_until_it_has_a_published_lesson_in_a_published_section(): void
    {
        Sanctum::actingAs($this->admin());
        [$courseId, $subjectId, $lessonId] = $this->outlined();

        $this->postJson("/api/courses/{$courseId}/status", ['status' => 'published'])
            ->assertUnprocessable()->assertJsonPath('message', 'لا يمكن نشر دورة بلا درس منشور داخل قسم منشور.');
        $this->postJson("/api/course-subjects/{$subjectId}/status", ['status' => 'published'])
            ->assertUnprocessable()->assertJsonPath('message', 'لا يمكن نشر قسم بلا درس منشور.');

        $this->postJson("/api/course-lessons/{$lessonId}/status", ['status' => 'published'])->assertOk();
        $this->postJson("/api/course-subjects/{$subjectId}/status", ['status' => 'published'])->assertOk();
        $this->postJson("/api/courses/{$courseId}/status", ['status' => 'published'])
            ->assertOk()->assertJsonPath('status', 'published');
    }

    public function test_the_status_moves_only_along_draft_published_hidden_archived(): void
    {
        Sanctum::actingAs($this->admin());
        [$courseId, $subjectId, $lessonId] = $this->outlined();
        $this->postJson("/api/course-lessons/{$lessonId}/status", ['status' => 'published']);
        $this->postJson("/api/course-subjects/{$subjectId}/status", ['status' => 'published']);

        $this->postJson("/api/courses/{$courseId}/status", ['status' => 'hidden'])->assertUnprocessable();
        $this->postJson("/api/courses/{$courseId}/status", ['status' => 'published'])->assertOk();
        $firstPublication = Course::find($courseId)->published_at;
        $this->postJson("/api/courses/{$courseId}/status", ['status' => 'hidden'])->assertOk();
        $this->postJson("/api/courses/{$courseId}/status", ['status' => 'published'])->assertOk();
        // The date a course first went live is kept when it comes back.
        $this->assertTrue($firstPublication->equalTo(Course::find($courseId)->published_at));
        $this->postJson("/api/courses/{$courseId}/status", ['status' => 'archived'])->assertOk();
        $this->postJson("/api/courses/{$courseId}/status", ['status' => 'published'])->assertUnprocessable();
    }

    public function test_only_content_that_was_never_published_can_be_deleted(): void
    {
        Sanctum::actingAs($this->admin());
        [$courseId, $subjectId, $lessonId] = $this->outlined();
        $this->postJson("/api/course-lessons/{$lessonId}/status", ['status' => 'published']);

        $this->deleteJson("/api/course-lessons/{$lessonId}")->assertUnprocessable()
            ->assertJsonPath('message', 'لا يمكن حذف محتوى سبق نشره؛ أخفِه أو أرشفه بدلاً من ذلك.');
        $this->postJson("/api/course-lessons/{$lessonId}/status", ['status' => 'hidden']);
        $this->deleteJson("/api/course-lessons/{$lessonId}")->assertUnprocessable();

        $draft = $this->postJson("/api/course-subjects/{$subjectId}/lessons", ['title' => 'مسودة'])->json('id');
        $this->deleteJson("/api/course-lessons/{$draft}")->assertNoContent();
        $this->assertNull(CourseLesson::find($draft));
        $this->deleteJson("/api/courses/{$courseId}")->assertNoContent();
        $this->assertNull(Course::find($courseId));
    }

    public function test_reordering_must_name_every_item_exactly_once(): void
    {
        Sanctum::actingAs($this->admin());
        [$courseId, $subjectId] = $this->outlined();
        $second = $this->postJson("/api/course-subjects/{$subjectId}/lessons", ['title' => 'ثاني'])->json('id');
        $third = $this->postJson("/api/course-subjects/{$subjectId}/lessons", ['title' => 'ثالث'])->json('id');
        $first = CourseLesson::where('title', 'المعادلات')->value('id');

        $this->putJson("/api/course-subjects/{$subjectId}/lessons/order", ['ids' => [$first, $second]])->assertUnprocessable();
        $this->putJson("/api/course-subjects/{$subjectId}/lessons/order", ['ids' => [$first, $second, $second]])->assertUnprocessable();
        $this->putJson("/api/course-subjects/{$subjectId}/lessons/order", ['ids' => [$third, $first, $second]])->assertNoContent();

        $this->assertSame([$third, $first, $second], CourseSubject::find($subjectId)->lessons->pluck('id')->all());

        $other = $this->postJson("/api/courses/{$courseId}/subjects", ['title' => 'الهندسة'])->json('id');
        $this->putJson("/api/courses/{$courseId}/subjects/order", ['ids' => [$other, $subjectId]])->assertNoContent();
        $this->assertSame([$other, $subjectId], Course::find($courseId)->subjects->pluck('id')->all());
    }

    public function test_a_lessons_outline_holds_a_single_section(): void
    {
        Sanctum::actingAs($this->admin());
        $id = $this->postJson('/api/courses', $this->create(['outline_style' => 'lessons']))->assertCreated()->json('id');
        $this->postJson("/api/courses/{$id}/subjects", ['title' => 'الدروس'])->assertCreated();
        $this->postJson("/api/courses/{$id}/subjects", ['title' => 'قسم آخر'])->assertUnprocessable()
            ->assertJsonPath('message', 'هذه الدورة بمخطط دروس مباشر فلا تحتمل إلا قسماً واحداً.');

        $many = $this->postJson('/api/courses', $this->create())->json('id');
        $this->postJson("/api/courses/{$many}/subjects", ['title' => 'أ']);
        $this->postJson("/api/courses/{$many}/subjects", ['title' => 'ب']);
        $this->patchJson("/api/courses/{$many}", ['outline_style' => 'lessons'])->assertUnprocessable();
    }

    public function test_a_paid_course_needs_a_price_and_a_discount_below_it_and_a_free_one_carries_none(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/courses', $this->create(['pricing' => 'paid']))->assertUnprocessable()->assertJsonValidationErrors('price_amount');
        $this->postJson('/api/courses', $this->create(['pricing' => 'paid', 'price_amount' => 500, 'discounted_price_amount' => 500]))
            ->assertUnprocessable()->assertJsonValidationErrors('discounted_price_amount');

        $id = $this->postJson('/api/courses', $this->create(['pricing' => 'paid', 'price_amount' => 500, 'discounted_price_amount' => 400]))
            ->assertCreated()->assertJsonPath('pricing.final_price_amount', 400)->json('id');

        $this->patchJson("/api/courses/{$id}", ['pricing' => 'free'])->assertOk()
            ->assertJsonPath('pricing.model', 'free')->assertJsonPath('pricing.price_amount', null)->assertJsonPath('pricing.final_price_amount', null);
    }

    public function test_a_live_lesson_needs_a_join_link_and_defaults_to_another_kind_of_link(): void
    {
        Sanctum::actingAs($this->admin());
        [, $subjectId] = $this->outlined();

        $this->postJson("/api/course-subjects/{$subjectId}/lessons", ['title' => 'مباشر', 'live_at' => '2026-11-01 18:00:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('live_link');
        $this->postJson("/api/course-subjects/{$subjectId}/lessons", ['title' => 'مباشر', 'live_at' => '2026-11-01 18:00:00', 'live_link' => 'not a url'])
            ->assertUnprocessable();

        $this->postJson("/api/course-subjects/{$subjectId}/lessons", ['title' => 'مباشر', 'live_at' => '2026-11-01 18:00:00', 'live_link' => 'https://meet.google.com/abc-defg-hij'])
            ->assertCreated()->assertJsonPath('live_provider', 'other');
        $this->postJson("/api/course-subjects/{$subjectId}/lessons", ['title' => 'زوم', 'live_at' => '2026-11-02 18:00:00', 'live_link' => 'https://zoom.us/j/123', 'live_provider' => 'zoom'])
            ->assertCreated()->assertJsonPath('live_provider', 'zoom');
    }

    public function test_a_teacher_cannot_edit_the_outline_of_a_course_they_do_not_teach(): void
    {
        Sanctum::actingAs($this->admin());
        [$courseId, $subjectId, $lessonId] = $this->outlined();

        Sanctum::actingAs($this->teacher());
        $this->postJson("/api/courses/{$courseId}/subjects", ['title' => 'x'])->assertForbidden();
        $this->patchJson("/api/course-subjects/{$subjectId}", ['title' => 'x'])->assertForbidden();
        $this->postJson("/api/course-subjects/{$subjectId}/lessons", ['title' => 'x'])->assertForbidden();
        $this->postJson("/api/course-lessons/{$lessonId}/status", ['status' => 'published'])->assertForbidden();
        $this->deleteJson("/api/course-lessons/{$lessonId}")->assertForbidden();
        $this->assertSame(ContentStatus::Draft, CourseLesson::find($lessonId)->status);
    }

    public function test_a_session_time_keeps_its_instant_whatever_offset_it_arrives_with(): void
    {
        Sanctum::actingAs($this->admin());
        [, $subjectId] = $this->outlined();
        // 16:00 UTC, as a browser sends it; the school runs on Cairo time.
        $response = $this->postJson("/api/course-subjects/{$subjectId}/lessons", ['title' => 'مباشر', 'live_at' => '2027-01-15T16:00:00.000Z', 'live_link' => 'https://meet.example/x'])->assertCreated();

        $this->assertTrue(Carbon::parse($response->json('live_at'))->equalTo(Carbon::parse('2027-01-15T16:00:00Z')));
        $stored = CourseLesson::findOrFail($response->json('id'));
        $this->assertTrue($stored->live_at->equalTo(Carbon::parse('2027-01-15T16:00:00Z')));
        $this->assertSame('2027-01-15 18:00:00', $stored->getRawOriginal('live_at'), 'stored as the school\'s wall-clock time');

        // Editing it with another offset for the same instant changes nothing.
        $this->patchJson("/api/course-lessons/{$stored->id}", ['live_at' => '2027-01-15T18:00:00+02:00', 'live_link' => 'https://meet.example/x'])->assertOk();
        $this->assertTrue($stored->fresh()->live_at->equalTo(Carbon::parse('2027-01-15T16:00:00Z')));
    }

    public function test_a_courses_dates_are_kept_the_same_way(): void
    {
        Sanctum::actingAs($this->admin());

        $id = $this->postJson('/api/courses', $this->create(['starts_at' => '2027-03-01T10:00:00Z', 'ends_at' => '2027-03-02T10:00:00Z']))->assertCreated()->json('id');

        $course = Course::findOrFail($id);
        $this->assertTrue($course->starts_at->equalTo(Carbon::parse('2027-03-01T10:00:00Z')));
        $this->assertTrue($course->ends_at->equalTo(Carbon::parse('2027-03-02T10:00:00Z')));
    }
}
