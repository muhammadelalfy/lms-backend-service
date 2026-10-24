<?php

declare(strict_types=1);

namespace Modules\Courses\Tests\Feature;

use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Modules\Auth\Models\User;
use Modules\Courses\Enums\ContentStatus;
use Modules\Courses\Models\Course;
use Modules\Courses\Models\Enrollment;
use Modules\Students\Models\Student;
use Modules\Students\Models\StudentAccount;
use Modules\Tenancy\Models\School;
use Tests\TestCase;

/** Courses belong to one school: another school's students and staff never see them. */
class CourseIsolationTest extends TestCase
{
    private function enter(School $school): void
    {
        tenancy()->initialize($school);
        URL::forceRootUrl("http://{$school->id}.mudarisy.test");
    }

    public function test_a_school_does_not_see_the_courses_or_enrollments_of_another(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $this->postJson('/api/courses', ['title' => 'دورة المركز الأولى'])->assertCreated();
        $course = Course::firstOrFail();
        $course->forceFill(['status' => ContentStatus::Published])->save();
        $student = Student::factory()->create();
        $user = User::factory()->create(['role' => 'student']);
        StudentAccount::create(['user_id' => $user->id, 'student_id' => $student->id, 'relationship' => 'student']);
        Enrollment::create(['course_id' => $course->id, 'student_id' => $student->id]);

        $beta = $this->openSchool('beta');
        $this->assertSame(0, Course::query()->count(), 'a new school starts without courses');
        $this->assertSame(0, Enrollment::query()->count());

        $this->enter($this->school);
        $this->assertSame(1, Course::query()->count());
        $this->assertSame(1, Enrollment::query()->count());

        $this->enter($beta);
        $betaAdmin = User::factory()->create(['role' => 'admin']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($betaAdmin);
        $this->getJson('/api/courses')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/catalog')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/catalog/{$course->slug}")->assertNotFound();
    }
}
