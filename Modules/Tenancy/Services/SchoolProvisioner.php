<?php

namespace Modules\Tenancy\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Models\User;
use Modules\Tenancy\Models\School;
use Stancl\Tenancy\Database\Models\Domain;
use Throwable;

/**
 * Opens a new school: its record, its database (created and migrated by the
 * tenancy events), its subdomain and its first administrator.
 */
class SchoolProvisioner
{
    /**
     * @param  array{slug: string, name: string, plan?: string, admin: array{name: string, email: string, password: string}, demo?: bool, trial?: bool, max_students?: int|null}  $data
     */
    public function open(array $data): School
    {
        $slug = strtolower(trim($data['slug']));
        $plan = $data['plan'] ?? config('schools.default_plan');
        $this->validate($slug, $plan);

        // A trial is asked for, or is the legacy `trial` plan; its length is one global setting.
        $trial = $data['trial'] ?? $plan === 'trial';
        $school = School::create([
            'id' => $slug,
            'name' => $data['name'],
            'plan' => $plan,
            'status' => School::ACTIVE,
            'trial_ends_at' => $trial ? now()->addDays((int) config('schools.trial_days')) : null,
            'max_students' => $data['max_students'] ?? null,
        ]);

        try {
            $school->domains()->create(['domain' => $slug.'.'.config('tenancy.base_domain')]);
            $school->run(function () use ($data) {
                User::query()->create([
                    'name' => $data['admin']['name'],
                    'email' => $data['admin']['email'],
                    'password' => $data['admin']['password'],
                    'role' => 'admin',
                ]);
                if (! empty($data['demo'])) {
                    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\ArabicDemoSeeder', '--force' => true]);
                }
            });
        } catch (Throwable $error) {
            // A school that could not be set up completely must not be left half-made.
            $school->delete();
            throw $error;
        }

        return $school->fresh();
    }

    /**
     * Registers an existing database as a school without creating or migrating
     * anything, so a server that ran for one school keeps all its data.
     *
     * @param  array{slug: string, name: string, plan?: string, database: string}  $data
     */
    public function adopt(array $data): School
    {
        $slug = strtolower(trim($data['slug']));
        $plan = $data['plan'] ?? 'enterprise';
        $this->validate($slug, $plan);

        $database = (string) $data['database'];
        if ($database === '' || ! is_file(database_path($database))) {
            throw ValidationException::withMessages(['database' => 'ملف قاعدة البيانات غير موجود داخل مجلد database.']);
        }

        // The database already exists, so the tenancy events must not create or migrate one.
        $provision = config('tenancy.provision_databases', true);
        config(['tenancy.provision_databases' => false]);
        try {
            $school = new School(['id' => $slug, 'name' => $data['name'], 'plan' => $plan, 'status' => School::ACTIVE]);
            $school->setInternal('db_name', $database);
            $school->save();
            $school->domains()->create(['domain' => $slug.'.'.config('tenancy.base_domain')]);
        } finally {
            config(['tenancy.provision_databases' => $provision]);
        }

        return $school->fresh();
    }

    /** Whether [$slug] may be taken, with the reason in words when not. */
    private function validate(string $slug, string $plan): void
    {
        $errors = [];
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/', $slug)) {
            $errors['slug'] = 'اكتب كوداً بحروف إنجليزية صغيرة وأرقام وشرطات فقط (حتى 40 حرفاً).';
        } elseif (in_array($slug, config('schools.reserved_slugs'), true)) {
            $errors['slug'] = 'هذا الكود محجوز.';
        } elseif (School::query()->whereKey($slug)->exists() || Domain::query()->where('domain', $slug.'.'.config('tenancy.base_domain'))->exists()) {
            $errors['slug'] = 'هذا الكود مستخدم لمدرسة أخرى.';
        }
        if (! array_key_exists($plan, config('schools.plans'))) {
            $errors['plan'] = 'باقة غير معروفة.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
