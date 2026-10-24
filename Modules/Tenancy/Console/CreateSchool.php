<?php

namespace Modules\Tenancy\Console;

use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Modules\Tenancy\Services\SchoolProvisioner;

class CreateSchool extends Command
{
    protected $signature = 'school:create {slug : subdomain, e.g. alnour}
        {--name= : the school name}
        {--plan= : trial, basic, pro or enterprise}
        {--admin-name=مدير المركز}
        {--admin-email= : the first administrator email}
        {--admin-password= : at least 8 characters}
        {--demo : load the Arabic demo school}';

    protected $description = 'Open a new school with its own database and subdomain';

    public function handle(SchoolProvisioner $provisioner): int
    {
        $email = $this->option('admin-email') ?: $this->ask('Administrator email');
        $password = $this->option('admin-password') ?: $this->secret('Administrator password (8+ characters)');
        try {
            $school = $provisioner->open([
                'slug' => $this->argument('slug'),
                'name' => $this->option('name') ?: $this->argument('slug'),
                'plan' => $this->option('plan') ?: config('schools.default_plan'),
                'admin' => ['name' => $this->option('admin-name'), 'email' => $email, 'password' => $password],
                'demo' => (bool) $this->option('demo'),
            ]);
        } catch (ValidationException $error) {
            foreach ($error->errors() as $field => $messages) {
                $this->error("{$field}: ".implode(' ', $messages));
            }

            return self::FAILURE;
        }
        $this->info("School {$school->id} is open at https://".$school->domains()->first()->domain);

        return self::SUCCESS;
    }
}
