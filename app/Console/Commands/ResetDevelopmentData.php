<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Modules\Tenancy\Models\School;
use Modules\Tenancy\Services\SchoolProvisioner;

#[Signature('lms:reset-development-data {--school=demo : the school to reset}')]
#[Description('Reset a local school database and reseed Arabic development data')]
class ResetDevelopmentData extends Command
{
    public function handle(SchoolProvisioner $provisioner): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Blocked: this command is available only in local or testing environments.');
            return self::FAILURE;
        }

        $slug = (string) $this->option('school');
        $school = School::query()->find($slug);

        if ($school === null) {
            // First run: open the school with the demo data, and the local administrator.
            $provisioner->open([
                'slug' => $slug,
                'name' => 'مركز تجريبي',
                'plan' => 'enterprise',
                'demo' => true,
                'admin' => ['name' => 'مدير التطوير', 'email' => 'admin@local.test', 'password' => 'AdminLocal!2026'],
            ]);
            $this->info("School '{$slug}' opened with Arabic demo data.");
            return self::SUCCESS;
        }

        Artisan::call('tenants:migrate-fresh', ['--tenants' => [$school->id]], $this->output);
        $school->run(fn () => Artisan::call('db:seed', config('tenancy.seeder_parameters'), $this->output));

        $this->info("School '{$slug}' reset and Arabic demo data restored.");
        return self::SUCCESS;
    }
}
