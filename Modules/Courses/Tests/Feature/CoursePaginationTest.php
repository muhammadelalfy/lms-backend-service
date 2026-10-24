<?php

declare(strict_types=1);

namespace Modules\Courses\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Courses\Models\Enrollment;
use Modules\Courses\Tests\Concerns\BuildsCourses;
use Modules\Students\Models\Student;
use Tests\TestCase;

/** Lists are paged by cursor, newest first, so a long catalog is never silently cut off. */
class CoursePaginationTest extends TestCase
{
    use BuildsCourses;
    use RefreshDatabase;

    /** Follows next_cursor until the end; returns every row, in the order received. */
    private function everyRow(string $path, int $maxPages = 10): array
    {
        $rows = [];
        $cursor = null;
        for ($page = 0; $page < $maxPages; $page++) {
            $response = $this->getJson($path.($cursor ? (str_contains($path, '?') ? '&' : '?').'cursor='.urlencode($cursor) : ''))->assertOk();
            $rows = array_merge($rows, $response->json('data'));
            $cursor = $response->json('next_cursor');
            if (! $cursor) {
                return ['rows' => $rows, 'pages' => $page + 1];
            }
        }

        $this->fail('the list never ended');
    }

    public function test_the_catalog_pages_by_cursor_without_losing_or_repeating_a_course(): void
    {
        foreach (range(1, 13) as $n) {
            $this->course(['title' => "دورة {$n}", 'slug' => "course-{$n}"], lessons: 1);
        }
        $this->learner();

        $first = $this->getJson('/api/catalog')->assertOk();
        $this->assertCount(12, $first->json('data'));
        $this->assertNotNull($first->json('next_cursor'));

        $all = $this->everyRow('/api/catalog');
        $this->assertSame(2, $all['pages']);
        $titles = array_column($all['rows'], 'title');
        $this->assertCount(13, $titles);
        $this->assertCount(13, array_unique($titles));
        $this->assertSame('دورة 13', $titles[0], 'newest first');
        $this->assertSame('دورة 1', $titles[12]);
    }

    public function test_the_staff_list_pages_the_same_way(): void
    {
        Sanctum::actingAs($this->admin());
        foreach (range(1, 26) as $n) {
            $this->postJson('/api/courses', ['title' => "دورة {$n}"])->assertCreated();
        }

        $this->assertCount(25, $this->getJson('/api/courses')->json('data'));
        $all = $this->everyRow('/api/courses');
        $this->assertSame([26, 2], [count($all['rows']), $all['pages']]);
        $this->assertCount(26, array_unique(array_column($all['rows'], 'id')));
        $this->getJson('/api/courses?status=draft')->assertOk()->assertJsonCount(25, 'data');
        $this->getJson('/api/courses?status=nonsense')->assertUnprocessable();
    }

    public function test_the_enrollment_list_pages_too(): void
    {
        $course = $this->course(lessons: 1);
        foreach (range(1, 51) as $ignored) {
            Enrollment::create(['course_id' => $course->id, 'student_id' => Student::factory()->create()->id]);
        }
        Sanctum::actingAs($this->admin());

        $this->assertCount(50, $this->getJson("/api/courses/{$course->id}/enrollments")->json('data'));
        $all = $this->everyRow("/api/courses/{$course->id}/enrollments");
        $this->assertSame([51, 2], [count($all['rows']), $all['pages']]);
    }

    public function test_a_garbled_cursor_starts_from_the_first_page_instead_of_failing(): void
    {
        $this->course(lessons: 1);
        $this->learner();

        $this->getJson('/api/catalog?cursor=not-a-real-cursor')->assertOk()->assertJsonCount(1, 'data');
    }
}
