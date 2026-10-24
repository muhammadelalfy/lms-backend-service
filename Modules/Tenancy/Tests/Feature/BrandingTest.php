<?php

namespace Modules\Tenancy\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Auth\Models\User;
use Tests\TestCase;

/** The academy wears its own name, logo and color; only its admin changes them. */
class BrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_anyone_on_the_academy_address_can_read_its_look_without_signing_in(): void
    {
        $this->getJson('/api/branding')->assertOk()->assertJsonStructure(['name', 'brand_color', 'logo_url']);
    }

    public function test_the_admin_sets_the_color_and_logo_and_everyone_then_sees_them(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->putJson('/api/branding', ['name' => 'مركز النور', 'brand_color' => '#1F4E8C', 'logo_url' => 'https://cdn.example/logo.png'])
            ->assertOk()->assertJsonPath('brand_color', '#1F4E8C');

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/branding')->assertOk()
            ->assertJsonPath('name', 'مركز النور')
            ->assertJsonPath('logo_url', 'https://cdn.example/logo.png');
    }

    public function test_a_bad_color_or_a_non_https_logo_is_refused_in_arabic(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->putJson('/api/branding', ['brand_color' => 'blue'])->assertUnprocessable()->assertJsonValidationErrors('brand_color');
        $this->putJson('/api/branding', ['logo_url' => 'http://x.example/l.png'])->assertUnprocessable()->assertJsonValidationErrors('logo_url');
    }

    public function test_teachers_and_students_cannot_change_it_and_guests_are_turned_away(): void
    {
        $this->putJson('/api/branding', ['brand_color' => '#112233'])->assertUnauthorized();

        foreach (['teacher', 'student', 'parent'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->putJson('/api/branding', ['brand_color' => '#112233'])->assertForbidden();
        }
    }
}
