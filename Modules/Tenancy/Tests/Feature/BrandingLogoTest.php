<?php

declare(strict_types=1);

namespace Modules\Tenancy\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Modules\Auth\Models\User;
use Modules\Tenancy\Models\School;
use Modules\Tenancy\Support\Contrast;
use Tests\TestCase;

/** S15: the center's admin uploads its logo and picks colors; the server warns when text would be hard to read. */
class BrandingLogoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('branding');
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_a_png_logo_is_stored_under_this_centers_path_and_served_with_a_version(): void
    {
        $this->admin();

        $response = $this->post('/api/branding/logo', ['logo' => UploadedFile::fake()->image('logo.png', 600, 300)->size(600)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('warnings', []);

        $url = $response->json('logo_url');
        $this->assertMatchesRegularExpression('~^http://demo\.mudarisy\.test/storage/tenants/demo/branding/logo\.png\?v=[0-9a-f]{8}$~', $url);
        Storage::disk('branding')->assertExists('tenants/demo/branding/logo.png');
        $this->assertSame($url, School::query()->findOrFail('demo')->logo_url);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/branding')->assertOk()->assertJsonPath('logo_url', $url);
    }

    public function test_jpg_and_webp_are_accepted_and_a_new_logo_replaces_the_old_file(): void
    {
        $this->admin();

        $this->post('/api/branding/logo', ['logo' => UploadedFile::fake()->image('logo.jpg', 256, 256)], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('branding')->assertExists('tenants/demo/branding/logo.jpg');

        $this->post('/api/branding/logo', ['logo' => UploadedFile::fake()->image('logo.webp', 256, 256)], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('branding')->assertExists('tenants/demo/branding/logo.webp');
        Storage::disk('branding')->assertMissing('tenants/demo/branding/logo.jpg');
    }

    public function test_a_non_image_an_svg_a_big_or_a_tiny_file_is_refused_in_arabic_and_nothing_is_stored(): void
    {
        $this->admin();
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="300"><script>alert(1)</script></svg>');

        $cases = [
            'svg' => $svg,
            'pdf' => UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf'),
            'big' => UploadedFile::fake()->image('logo.png', 600, 600)->size(1100),
            'tiny' => UploadedFile::fake()->image('logo.png', 100, 100),
        ];
        foreach ($cases as $case => $file) {
            $response = $this->post('/api/branding/logo', ['logo' => $file], ['Accept' => 'application/json']);
            $response->assertUnprocessable();
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', (string) $response->json('errors.logo.0'), $case);
        }
        $this->post('/api/branding/logo', [], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('logo');

        $this->assertSame([], Storage::disk('branding')->allFiles());
        $this->assertNull(School::query()->findOrFail('demo')->logo_url);
    }

    public function test_the_logo_can_be_removed(): void
    {
        $this->admin();
        $this->post('/api/branding/logo', ['logo' => UploadedFile::fake()->image('logo.png', 300, 300)], ['Accept' => 'application/json'])->assertOk();

        $this->deleteJson('/api/branding/logo')->assertOk()->assertJsonPath('logo_url', null);

        Storage::disk('branding')->assertMissing('tenants/demo/branding/logo.png');
        $this->assertNull(School::query()->findOrFail('demo')->logo_url);
    }

    public function test_a_low_contrast_primary_color_is_saved_with_a_warning(): void
    {
        $this->admin();

        $this->putJson('/api/branding', ['primary_color' => '#FFE600', 'secondary_color' => '#1F4E8C'])->assertOk()
            ->assertJsonPath('primary_color', '#FFE600')
            ->assertJsonPath('brand_color', '#FFE600')
            ->assertJsonPath('secondary_color', '#1F4E8C')
            ->assertJsonPath('accent_text_color', '#000000')
            ->assertJsonPath('warnings.0.code', 'low_contrast')
            ->assertJsonPath('warnings.0.ratio', fn ($ratio) => $ratio < 4.5);

        $this->putJson('/api/branding', ['brand_color' => '#1F4E8C'])->assertOk()
            ->assertJsonPath('primary_color', '#1F4E8C')
            ->assertJsonPath('accent_text_color', '#FFFFFF')
            ->assertJsonPath('warnings', []);

        $this->putJson('/api/branding', ['secondary_color' => 'navy'])->assertUnprocessable()->assertJsonValidationErrors('secondary_color');
    }

    public function test_the_contrast_ratio_follows_wcag(): void
    {
        $this->assertEqualsWithDelta(21.0, Contrast::ratio('#FFFFFF', '#000000'), 0.01);
        $this->assertEqualsWithDelta(1.0, Contrast::ratio('#FFFFFF', '#FFFFFF'), 0.01);
        $this->assertEqualsWithDelta(4.54, Contrast::ratio('#FFFFFF', '#767676'), 0.01);
        $this->assertSame('#FFFFFF', Contrast::textOn('#1F4E8C'));
        $this->assertSame('#000000', Contrast::textOn('#FFE600'));
    }

    public function test_the_public_read_is_cached_and_a_change_is_seen_at_once(): void
    {
        $this->getJson('/api/branding')->assertOk()->assertJsonPath('primary_color', null);

        // A direct write (not through the API) is not seen while the cached copy lives...
        $this->school->forceFill(['brand_color' => '#123456'])->save();
        $this->getJson('/api/branding')->assertJsonPath('primary_color', null);

        // ...but a change through the API clears it.
        $this->admin();
        $this->putJson('/api/branding', ['name' => 'مركز النور'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/branding')->assertJsonPath('primary_color', '#123456')->assertJsonPath('name', 'مركز النور');
    }

    public function test_only_the_admin_writes_and_another_centers_admin_cannot_touch_this_one(): void
    {
        $file = fn () => UploadedFile::fake()->image('logo.png', 300, 300);
        $this->post('/api/branding/logo', ['logo' => $file()], ['Accept' => 'application/json'])->assertUnauthorized();
        foreach (['teacher', 'student', 'parent'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->post('/api/branding/logo', ['logo' => $file()], ['Accept' => 'application/json'])->assertForbidden();
            $this->deleteJson('/api/branding/logo')->assertForbidden();
            $this->putJson('/api/branding', ['primary_color' => '#112233'])->assertForbidden();
        }

        // Beta's admin uploads on beta: demo's look is untouched, and beta's file lives under beta.
        $this->openSchool('beta');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->post('http://beta.mudarisy.test/api/branding/logo', ['logo' => $file()], ['Accept' => 'application/json'])->assertOk();
        $this->putJson('http://beta.mudarisy.test/api/branding', ['primary_color' => '#112233'])->assertOk();

        Storage::disk('branding')->assertExists('tenants/beta/branding/logo.png');
        Storage::disk('branding')->assertMissing('tenants/demo/branding/logo.png');
        $demo = School::query()->findOrFail('demo');
        $this->assertNull($demo->logo_url);
        $this->assertNull($demo->brand_color);

        URL::forceRootUrl('http://demo.mudarisy.test');
    }
}
