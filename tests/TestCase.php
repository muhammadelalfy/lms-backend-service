<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Modules\Tenancy\Models\School;

/**
 * Every feature test runs inside a school, as the app does: the central
 * database (schools, domains, cache, jobs) is refreshed as usual, and the
 * school gets its own throwaway database with all the tenant migrations, and
 * an address that requests are made to.
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** The school the test runs in; its address is demo.mudarisy.test. */
    protected School $school;

    /** @var list<string> */
    private array $schoolDatabaseFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->openSchool('demo');
    }

    protected function tearDown(): void
    {
        if (function_exists('tenancy') && tenancy()->initialized) {
            tenancy()->end();
        }
        // The files cannot be deleted while a connection still has them open.
        DB::purge('tenant');
        parent::tearDown();

        foreach ($this->schoolDatabaseFiles as $file) {
            @unlink($file);
        }
        $this->schoolDatabaseFiles = [];
    }

    /**
     * Opens a school with an empty throwaway database file and makes it the
     * one requests are sent to. (Real provisioning is tested separately.)
     * The file lives under storage/framework/testing, named relative to
     * database/ because that is where the tenant connection resolves names.
     */
    protected function openSchool(string $slug, string $plan = 'enterprise'): School
    {
        config(['tenancy.provision_databases' => false]);

        $file = $this->makeSchoolDatabaseFile($slug);
        $this->schoolDatabaseFiles[] = $file;

        $school = new School(['id' => $slug, 'name' => "مركز {$slug}", 'plan' => $plan, 'status' => School::ACTIVE]);
        $school->setInternal('db_name', '../storage/framework/testing/'.basename($file));
        $school->save();
        $school->domains()->create(['domain' => "{$slug}.mudarisy.test"]);

        tenancy()->initialize($school);
        Artisan::call('migrate', config('tenancy.migration_parameters'));
        URL::forceRootUrl("http://{$slug}.mudarisy.test");

        return $school;
    }

    private function makeSchoolDatabaseFile(string $slug): string
    {
        $directory = base_path('storage/framework/testing');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        $file = $directory.DIRECTORY_SEPARATOR.$slug.'-'.bin2hex(random_bytes(6)).'.sqlite';
        touch($file);

        // Windows keeps a database file locked until the process ends, so what
        // could not be deleted after its test is removed when the run finishes.
        static $leftovers = [];
        if ($leftovers === []) {
            register_shutdown_function(function () use (&$leftovers) {
                foreach ($leftovers as $leftover) {
                    @unlink($leftover);
                }
            });
        }
        $leftovers[] = $file;

        return $file;
    }
}
