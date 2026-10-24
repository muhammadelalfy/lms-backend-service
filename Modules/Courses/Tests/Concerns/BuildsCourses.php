<?php

declare(strict_types=1);

namespace Modules\Courses\Tests\Concerns;

use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Auth\Models\User;
use Modules\Courses\Enums\ContentStatus;
use Modules\Courses\Models\Course;
use Modules\Students\Models\Student;
use Modules\Students\Models\StudentAccount;

trait BuildsCourses
{
    /** A published course with one published section and `$lessons` published lessons. */
    private function course(array $attributes = [], int $lessons = 2): Course
    {
        $course = Course::create($attributes + ['title' => 'الرياضيات', 'slug' => 'math-'.Str::random(6)]);
        $subject = $course->subjects()->make(['title' => 'القسم الأول']);
        $subject->position = 1;
        $subject->status = ContentStatus::Published;
        $subject->published_at = now();
        $subject->save();

        for ($i = 1; $i <= $lessons; $i++) {
            $lesson = $subject->lessons()->make(['title' => "درس {$i}", 'video_url' => "https://videos.example/{$i}"]);
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

    /** @return array{0: User, 1: Student} signed in as a student with a student record */
    private function learner(string $role = 'student'): array
    {
        $student = Student::factory()->create();
        $user = User::factory()->create(['role' => $role]);
        StudentAccount::create(['user_id' => $user->id, 'student_id' => $student->id, 'relationship' => $role]);
        Sanctum::actingAs($user);

        return [$user, $student];
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function teacher(): User
    {
        return User::factory()->create(['role' => 'teacher']);
    }
}
