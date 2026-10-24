<?php

namespace Modules\Tenancy\Tests\Feature;

use Modules\Auth\Models\User;
use Modules\Tenancy\Models\School;
use Tests\TestCase;

/** A server that ran for one school keeps its data when it becomes the first school of a platform. */
class AdoptSchoolTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        tenancy()->end();
        $this->file = base_path('storage/framework/testing/legacy-'.bin2hex(random_bytes(4)).'.sqlite');
        touch($this->file);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        \Illuminate\Support\Facades\DB::purge('tenant');
        parent::tearDown();
        @unlink($this->file);
    }

    public function test_an_existing_database_becomes_a_school_without_being_touched(): void
    {
        $before = filesize($this->file);

        $this->artisan('school:adopt', [
            'slug' => 'legacy', '--name' => 'مركزي', '--database' => '../storage/framework/testing/'.basename($this->file),
        ])->assertSuccessful();

        $school = School::query()->find('legacy');
        $this->assertSame('enterprise', $school->plan);
        $this->assertSame('legacy.mudarisy.test', $school->domains()->value('domain'));
        $this->assertSame($before, filesize($this->file), 'nothing was created or migrated on adoption');

        // Bringing it up to date is a separate, explicit step; afterwards it works as a school.
        $this->artisan('tenants:migrate', ['--tenants' => ['legacy']])->assertSuccessful();
        $school->run(fn () => User::factory()->create(['email' => 'owner@legacy.test']));
        $this->assertSame(1, $school->run(fn () => User::query()->count()));
    }

    public function test_a_missing_database_or_a_taken_name_is_refused(): void
    {
        $this->artisan('school:adopt', ['slug' => 'legacy', '--database' => 'no-such-file.sqlite'])->assertFailed();
        $this->assertNull(School::query()->find('legacy'));

        $this->artisan('school:adopt', ['slug' => 'demo', '--database' => '../storage/framework/testing/'.basename($this->file)])->assertFailed();
    }
}
