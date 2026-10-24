<?php

declare(strict_types=1);

namespace Modules\Courses\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Auth\Models\User;
use Modules\Courses\Actions\RecordCourseVersion;
use Modules\Courses\Enums\ContentStatus;
use Modules\Courses\Enums\EnrollmentStatus;
use Modules\Courses\Models\Course;
use Modules\Courses\Models\CourseVersion;
use Modules\Courses\Models\Enrollment;
use Modules\Courses\Tests\Concerns\BuildsCourses;
use Modules\Students\Models\StudentAccount;
use Tests\TestCase;

/** A live course never changes silently: each change to what learners see is a new, recorded version. */
class CourseVersionsTest extends TestCase
{
    use BuildsCourses;
    use RefreshDatabase;

    private function versions(Course $course): array
    {
        return CourseVersion::where('course_id', $course->id)->orderBy('number')->pluck('reason', 'number')->all();
    }

    public function test_a_course_in_draft_has_no_versions_and_its_first_publication_is_version_one(): void
    {
        Sanctum::actingAs($this->admin());
        $id = $this->postJson('/api/courses', ['title' => 'دورة'])->assertCreated()->json('id');
        $subjectId = $this->postJson("/api/courses/{$id}/subjects", ['title' => 'قسم'])->json('id');
        $lessonId = $this->postJson("/api/course-subjects/{$subjectId}/lessons", ['title' => 'درس'])->json('id');
        $this->patchJson("/api/course-lessons/{$lessonId}", ['title' => 'درس معدّل']);
        $this->assertSame([], CourseVersion::where('course_id', $id)->pluck('number')->all(), 'drafts are free to change');

        $this->postJson("/api/course-lessons/{$lessonId}/status", ['status' => 'published'])->assertOk();
        $this->postJson("/api/course-subjects/{$subjectId}/status", ['status' => 'published'])->assertOk();
        $this->postJson("/api/courses/{$id}/status", ['status' => 'published'])->assertOk()->assertJsonPath('version', 1);

        $first = CourseVersion::where('course_id', $id)->firstOrFail();
        $this->assertSame([1, 'published'], [$first->number, $first->reason]);
        $this->assertSame([$lessonId], $first->snapshot['required_lesson_ids']);
        $this->assertSame('درس معدّل', $first->snapshot['outline'][0]['lessons'][0]['title']);
    }

    public function test_changing_what_learners_see_makes_a_new_version_but_housekeeping_does_not(): void
    {
        $course = $this->course(lessons: 1);
        Sanctum::actingAs($this->admin());
        app(RecordCourseVersion::class)->handle($course->fresh(), 'published'); // as publishing records it
        $this->postJson("/api/courses/{$course->id}/status", ['status' => 'hidden']);
        $this->postJson("/api/courses/{$course->id}/status", ['status' => 'published']);
        $subject = $course->subjects()->firstOrFail();
        $this->assertSame([1 => 'published'], $this->versions($course), 'hiding and republishing alone records nothing more');

        // A new draft lesson is invisible to learners: no version.
        $draftId = $this->postJson("/api/course-subjects/{$subject->id}/lessons", ['title' => 'مسودة'])->json('id');
        $this->assertSame([1 => 'published'], $this->versions($course));

        // Publishing it, editing it, and reordering are changes learners see.
        $this->postJson("/api/course-lessons/{$draftId}/status", ['status' => 'published']);
        $this->patchJson("/api/course-lessons/{$draftId}", ['title' => 'مسودة معدّلة']);
        $existing = $subject->lessons()->orderBy('position')->pluck('id')->all();
        $this->putJson("/api/course-subjects/{$subject->id}/lessons/order", ['ids' => array_reverse($existing)])->assertNoContent();
        $this->assertSame([1 => 'published', 2 => 'content', 3 => 'content', 4 => 'content'], $this->versions($course));

        // Price and "featured" do not change the lessons or the rules.
        $this->patchJson("/api/courses/{$course->id}", ['is_featured' => true, 'pricing' => 'paid', 'price_amount' => 100])->assertOk();
        $this->assertCount(4, $this->versions($course));

        // The completion rules and the access period do.
        $this->patchJson("/api/courses/{$course->id}", ['sequencing_mode' => 'after_previous_completed', 'access_days' => 60])->assertOk()->assertJsonPath('version', 5);
        $this->assertSame('settings', $this->versions($course)[5]);
    }

    public function test_a_version_snapshot_keeps_the_course_as_it_was_when_it_was_recorded(): void
    {
        $course = $this->course(lessons: 1);
        Sanctum::actingAs($this->admin());
        $subject = $course->subjects()->firstOrFail();
        $lesson = $subject->lessons()->firstOrFail();
        $this->postJson("/api/courses/{$course->id}/status", ['status' => 'hidden']);
        $this->postJson("/api/courses/{$course->id}/status", ['status' => 'published']);
        // Version 1 for a course published before versions existed: take it now.
        app(RecordCourseVersion::class)->handle($course->fresh(), 'published');
        $before = CourseVersion::where('course_id', $course->id)->where('number', 1)->firstOrFail()->snapshot;

        $this->patchJson("/api/course-lessons/{$lesson->id}", ['title' => 'عنوان جديد'])->assertOk();
        $this->postJson("/api/course-lessons/{$lesson->id}/status", ['status' => 'hidden'])->assertOk();

        $this->assertSame($before, CourseVersion::where('course_id', $course->id)->where('number', 1)->firstOrFail()->snapshot);
        $this->assertSame('درس 1', $before['outline'][0]['lessons'][0]['title']);
    }

    public function test_enrollments_record_the_version_they_took_and_the_one_they_completed(): void
    {
        $course = $this->course(lessons: 1);
        app(RecordCourseVersion::class)->handle($course->fresh(), 'published');
        $this->learner();
        $this->postJson("/api/catalog/{$course->slug}/enroll")->assertCreated()->assertJsonPath('enrollment.course_version', 1);

        // The course changes after they joined...
        Sanctum::actingAs($this->admin());
        $subject = $course->subjects()->firstOrFail();
        $newId = $this->postJson("/api/course-subjects/{$subject->id}/lessons", ['title' => 'درس جديد'])->json('id');
        $this->postJson("/api/course-lessons/{$newId}/status", ['status' => 'published'])->assertOk();
        $this->assertSame(2, $course->fresh()->version);

        // ...so they complete it at version 2, with both lessons required.
        $enrollment = Enrollment::firstOrFail();
        $user = User::findOrFail(StudentAccount::where('student_id', $enrollment->student_id)->value('user_id'));
        Sanctum::actingAs($user);
        foreach ($course->fresh()->lessons()->pluck('course_lessons.id') as $id) {
            $this->postJson("/api/course-lessons/{$id}/complete")->assertOk();
        }
        $enrollment = $enrollment->fresh();
        $this->assertSame([EnrollmentStatus::Completed, 1, 2], [$enrollment->status, $enrollment->course_version, $enrollment->completed_version]);
    }

    public function test_a_completion_is_never_undone_when_the_course_later_gains_lessons(): void
    {
        $course = $this->course(lessons: 1);
        app(RecordCourseVersion::class)->handle($course->fresh(), 'published');
        [$user] = $this->learner();
        $this->postJson("/api/catalog/{$course->slug}/enroll")->assertCreated();
        $lessonId = $this->getJson("/api/my-courses/{$course->id}")->json('subjects.0.lessons.0.id');
        $this->postJson("/api/course-lessons/{$lessonId}/complete")->assertOk()->assertJsonPath('course_completed', true);

        Sanctum::actingAs($this->admin());
        $newId = $this->postJson("/api/course-subjects/{$course->subjects()->firstOrFail()->id}/lessons", ['title' => 'درس جديد'])->json('id');
        $this->postJson("/api/course-lessons/{$newId}/status", ['status' => 'published'])->assertOk();

        $enrollment = Enrollment::firstOrFail();
        $this->assertSame([EnrollmentStatus::Completed, 1], [$enrollment->status, $enrollment->completed_version], 'still completed, at the version they finished');
        Sanctum::actingAs($user);
        $this->getJson("/api/my-courses/{$course->id}")->assertOk()->assertJsonPath('enrollment.status', 'completed')->assertJsonPath('enrollment.completed_version', 1);
    }

    public function test_staff_read_the_versions_newest_first_and_others_cannot(): void
    {
        $teacher = $this->teacher();
        $course = $this->course(lessons: 1);
        $course->instructors()->attach($teacher->id, ['position' => 0]);
        $admin = $this->admin();
        Sanctum::actingAs($admin);
        app(RecordCourseVersion::class)->handle($course->fresh(), 'published', $admin);
        $subject = $course->subjects()->firstOrFail();
        $this->patchJson("/api/course-subjects/{$subject->id}", ['title' => 'قسم معدّل'])->assertOk();

        $rows = $this->getJson("/api/courses/{$course->id}/versions")->assertOk()->json('data');
        $this->assertSame([2, 1], array_column($rows, 'number'));
        $this->assertSame(['content', 'published'], array_column($rows, 'reason'));
        $this->assertSame([$admin->name, $admin->name], array_column($rows, 'author'));
        $this->assertSame([1, 1], array_column($rows, 'lessons'));

        Sanctum::actingAs($teacher);
        $this->getJson("/api/courses/{$course->id}/versions")->assertOk();
        Sanctum::actingAs($this->teacher());
        $this->getJson("/api/courses/{$course->id}/versions")->assertForbidden();
        $this->learner();
        $this->getJson("/api/courses/{$course->id}/versions")->assertForbidden();
    }

    public function test_archiving_and_unarchiving_keep_the_history_intact(): void
    {
        $course = $this->course(lessons: 1);
        Sanctum::actingAs($this->admin());
        app(RecordCourseVersion::class)->handle($course->fresh(), 'published');

        $this->postJson("/api/courses/{$course->id}/status", ['status' => 'archived'])->assertOk();
        $this->postJson("/api/courses/{$course->id}/status", ['status' => 'hidden'])->assertOk();

        $this->assertSame([1 => 'published'], $this->versions($course));
        $this->assertSame(ContentStatus::Hidden, $course->fresh()->status);
    }
}
