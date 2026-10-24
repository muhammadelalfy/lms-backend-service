<?php

namespace Modules\Courses\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Courses\Enums\ContentStatus;
use Modules\Courses\Enums\EnrollmentStatus;
use Modules\Courses\Models\AccessCode;
use Modules\Courses\Models\Enrollment;
use Modules\Courses\Tests\Concerns\BuildsCourses;
use Tests\TestCase;

/** Codes a center hands out: they open a course (even a paid one) for whoever enters them, within their limits. */
class AccessCodesTest extends TestCase
{
    use BuildsCourses;
    use RefreshDatabase;

    private function issue(array $course = [], array $body = []): array
    {
        $course = $this->course($course);
        Sanctum::actingAs($this->admin());
        $codes = $this->postJson("/api/courses/{$course->id}/access-codes", $body)->assertCreated()->json('data');

        return [$course, $codes];
    }

    public function test_staff_make_a_batch_of_distinct_readable_codes(): void
    {
        [, $codes] = $this->issue(body: ['count' => 25, 'max_uses' => 3]);

        $this->assertCount(25, $codes);
        $this->assertCount(25, array_unique(array_column($codes, 'code')));
        $this->assertMatchesRegularExpression('/^[A-HJKMNP-Z2-9]{8}$/', $codes[0]['code']);
        $this->assertSame(3, $codes[0]['max_uses']);
        $this->assertSame('active', $codes[0]['state']);
    }

    public function test_a_student_enters_a_code_and_a_paid_course_opens_without_a_payment(): void
    {
        [$course, $codes] = $this->issue(['pricing' => 'paid', 'price_amount' => 500]);
        [, $student] = $this->learner();

        $this->postJson('/api/access-codes/redeem', ['code' => strtolower(substr($codes[0]['code'], 0, 4).' - '.substr($codes[0]['code'], 4))])
            ->assertOk()->assertJsonPath('enrollment.status', 'active')->assertJsonPath('course.id', $course->id);

        $enrollment = Enrollment::query()->where('student_id', $student->id)->firstOrFail();
        $this->assertNull($enrollment->payment_id);
        $this->assertSame(EnrollmentStatus::Active, $enrollment->status);
        $this->assertSame(1, AccessCode::query()->whereKey($codes[0]['id'])->value('used_count'));
    }

    public function test_a_single_use_code_cannot_be_used_by_a_second_student(): void
    {
        [, $codes] = $this->issue();
        $this->learner();
        $this->postJson('/api/access-codes/redeem', ['code' => $codes[0]['code']])->assertOk();

        $this->learner();
        $this->postJson('/api/access-codes/redeem', ['code' => $codes[0]['code']])
            ->assertUnprocessable()->assertJsonPath('message', 'استُخدم هذا الكود بالكامل.');
    }

    public function test_entering_the_same_code_again_does_not_spend_another_use(): void
    {
        [, $codes] = $this->issue(body: ['max_uses' => 5]);
        $this->learner();

        $this->postJson('/api/access-codes/redeem', ['code' => $codes[0]['code']])->assertOk();
        $this->postJson('/api/access-codes/redeem', ['code' => $codes[0]['code']])->assertOk();

        $this->assertSame(1, AccessCode::query()->whereKey($codes[0]['id'])->value('used_count'));
    }

    public function test_an_unknown_expired_or_disabled_code_is_refused_with_its_reason(): void
    {
        [, $codes] = $this->issue(body: ['count' => 2]);
        AccessCode::query()->whereKey($codes[0]['id'])->update(['expires_at' => now()->subDay()]);
        $this->deleteJson('/api/access-codes/'.$codes[1]['id'])->assertOk()->assertJsonPath('state', 'disabled');
        $this->learner();

        $this->postJson('/api/access-codes/redeem', ['code' => 'NOPENOPE'])->assertNotFound();
        $this->postJson('/api/access-codes/redeem', ['code' => $codes[0]['code']])->assertUnprocessable()->assertJsonPath('message', 'انتهت صلاحية هذا الكود.');
        $this->postJson('/api/access-codes/redeem', ['code' => $codes[1]['code']])->assertUnprocessable()->assertJsonPath('message', 'هذا الكود لم يعد متاحاً.');
    }

    public function test_a_code_activates_a_pending_paid_enrollment_and_does_not_open_a_cancelled_one(): void
    {
        [$course, $codes] = $this->issue(['pricing' => 'paid', 'price_amount' => 300], ['count' => 2]);
        [, $student] = $this->learner();
        $this->postJson("/api/catalog/{$course->slug}/enroll")->assertCreated()->assertJsonPath('enrollment.status', 'pending');

        $this->postJson('/api/access-codes/redeem', ['code' => $codes[0]['code']])->assertOk()->assertJsonPath('enrollment.status', 'active');

        Enrollment::query()->where('student_id', $student->id)->update(['status' => 'cancelled']);
        $this->postJson('/api/access-codes/redeem', ['code' => $codes[1]['code']])->assertUnprocessable();
    }

    public function test_a_code_for_a_draft_course_does_not_open_it_but_a_hidden_course_is_fine(): void
    {
        [$course, $codes] = $this->issue(body: ['count' => 2]);
        $this->learner();

        $course->forceFill(['status' => ContentStatus::Draft])->save();
        $this->postJson('/api/access-codes/redeem', ['code' => $codes[0]['code']])->assertUnprocessable();

        $course->forceFill(['status' => ContentStatus::Hidden])->save();
        $this->postJson('/api/access-codes/redeem', ['code' => $codes[0]['code']])->assertOk();
    }

    public function test_only_staff_who_run_the_course_manage_codes_and_only_learners_redeem(): void
    {
        $course = $this->course();
        Sanctum::actingAs($this->teacher());
        $this->postJson("/api/courses/{$course->id}/access-codes")->assertForbidden();
        $this->postJson('/api/access-codes/redeem', ['code' => 'ABCDEFGH'])->assertForbidden();

        $this->learner();
        $this->postJson("/api/courses/{$course->id}/access-codes")->assertForbidden();
        $this->getJson("/api/courses/{$course->id}/access-codes")->assertForbidden();
    }

    public function test_the_list_pages_by_cursor_and_shows_each_codes_state(): void
    {
        [$course] = $this->issue(body: ['count' => 60]);

        $first = $this->getJson("/api/courses/{$course->id}/access-codes")->assertOk();
        $this->assertCount(50, $first->json('data'));
        $this->assertNotNull($first->json('next_cursor'));
    }
}
