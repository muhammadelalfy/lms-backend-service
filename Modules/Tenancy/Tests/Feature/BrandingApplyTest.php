<?php

declare(strict_types=1);

namespace Modules\Tenancy\Tests\Feature;

use Laravel\Sanctum\Sanctum;
use Modules\Auth\Models\User;
use Tests\TestCase;

/** S17 (backend part): the sign-in page and the app shell get the center's look with the call they already make. */
class BrandingApplyTest extends TestCase
{
    public function test_the_sign_in_options_carry_the_centers_look(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->putJson('/api/branding', ['name' => 'سنتر النور', 'primary_color' => '#1F4E8C', 'secondary_color' => '#D4AF55', 'logo_url' => 'https://cdn.example/l.png'])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/auth/options')->assertOk()
            ->assertJsonPath('teacher_registration', true)
            ->assertJsonPath('branding.name', 'سنتر النور')
            ->assertJsonPath('branding.primary_color', '#1F4E8C')
            ->assertJsonPath('branding.secondary_color', '#D4AF55')
            ->assertJsonPath('branding.accent_text_color', '#FFFFFF')
            ->assertJsonPath('branding.logo_url', 'https://cdn.example/l.png');
    }

    public function test_a_center_without_branding_gets_nulls_so_the_app_falls_back_to_its_defaults(): void
    {
        $this->getJson('/api/auth/options')->assertOk()
            ->assertJsonPath('branding.name', 'مركز demo')
            ->assertJsonPath('branding.primary_color', null)
            ->assertJsonPath('branding.accent_text_color', null)
            ->assertJsonPath('branding.logo_url', null);
    }
}
