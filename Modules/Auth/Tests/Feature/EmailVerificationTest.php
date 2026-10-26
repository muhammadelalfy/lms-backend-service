<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Feature;

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Modules\Auth\Actions\VerifyEmail;
use Modules\Auth\Models\User;
use Modules\Auth\Notifications\VerifyEmailNotification;
use Modules\Auth\Services\DemoAccounts;
use Modules\Notifications\Jobs\SendChannelDelivery;
use Modules\Notifications\Models\NotificationDelivery;
use Modules\Notifications\Services\ChannelRegistry;
use Modules\Tenancy\Models\School;
use Tests\TestCase;

/** S18: a self sign-up's admin confirms their email; after 3 unconfirmed days the center sends no SMS. */
class EmailVerificationTest extends TestCase
{
    private const SIGNUP = 'http://localhost/api/signup';

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (glob(base_path('storage/framework/testing/prov_*')) ?: [] as $file) {
            @unlink($file);
        }
    }

    /** Opens a real center through the public sign-up and enters it. */
    private function signUp(): School
    {
        config([
            'tenancy.provision_databases' => true,
            'tenancy.database.prefix' => '../storage/framework/testing/prov_',
            'tenancy.database.suffix' => '.sqlite',
        ]);
        tenancy()->end();
        URL::forceRootUrl('http://localhost');

        $this->postJson(self::SIGNUP, [
            'kind' => 'center', 'name' => 'سنتر النور', 'slug' => 'alnour',
            'admin' => ['name' => 'أحمد', 'email' => 'owner@alnour.test', 'password' => 'Strong!Pass1'],
        ])->assertCreated()->assertJsonPath('email_verified', false);

        $school = School::query()->findOrFail('alnour');
        tenancy()->initialize($school);
        URL::forceRootUrl('http://alnour.mudarisy.test');

        return $school;
    }

    private function owner(): User
    {
        return User::query()->where('email', 'owner@alnour.test')->firstOrFail();
    }

    private function sentLink(User $user): string
    {
        $url = null;
        Notification::assertSentTo($user, VerifyEmailNotification::class, function (VerifyEmailNotification $n) use (&$url) {
            $url = $n->url;

            return true;
        });

        return $url;
    }

    /** A center that asks for confirmation, without a real sign-up. */
    private function demoAwaitingVerification(): User
    {
        $this->school->forceFill(['email_verification_required' => true])->save();
        $admin = User::factory()->unverified()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_a_new_sign_up_leaves_the_admin_unconfirmed_and_mails_a_signed_48_hour_link(): void
    {
        // The link takes the scheme and port of the app's own URL; pin it so CI's APP_URL (with :8000) does not matter.
        config(['app.url' => 'http://localhost']);
        Notification::fake();
        $school = $this->signUp();

        $owner = $this->owner();
        $this->assertNull($owner->email_verified_at);
        $this->assertTrue($school->awaitsEmailVerification());
        $link = $this->sentLink($owner);
        $this->assertStringStartsWith('http://alnour.mudarisy.test/api/email/verify/'.$owner->id.'/', $link);
        $this->assertStringContainsString('signature=', $link);
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        $this->assertEqualsWithDelta(now()->addHours(48)->timestamp, (int) $query['expires'], 5);
        $this->assertTrue((new VerifyEmailNotification('x', 'y', 48))->afterCommit);
    }

    public function test_the_link_confirms_once_and_opening_it_again_is_harmless(): void
    {
        Notification::fake();
        $school = $this->signUp();
        $link = $this->sentLink($this->owner());

        $this->getJson($link)->assertOk()->assertJsonPath('verified', true)->assertJsonPath('already_verified', false);
        $this->assertNotNull($this->owner()->email_verified_at);
        $this->assertFalse($school->fresh()->awaitsEmailVerification());

        $this->getJson($link)->assertOk()->assertJsonPath('already_verified', true);

        // Opened in a browser, it lands on the center's sign-in page.
        $this->get($link)->assertRedirect('/login?email_verified=1');
    }

    public function test_an_expired_or_tampered_link_is_refused_in_arabic_with_a_way_to_resend(): void
    {
        Notification::fake();
        $this->signUp();
        $link = $this->sentLink($this->owner());

        $this->getJson(str_replace('signature=', 'signature=0', $link))->assertForbidden()
            ->assertJsonPath('code', 'invalid_verification_link')
            ->assertJsonPath('can_resend', true)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'رابط التأكيد'));
        $this->getJson(preg_replace('~/verify/(\d+)/[0-9a-f]+~', '/verify/$1/'.sha1('other@x.test'), $link))->assertForbidden();
        $this->get(str_replace('signature=', 'signature=0', $link))->assertRedirect('/login?email_verification=invalid');

        $this->travel(49)->hours();
        $this->getJson($link)->assertForbidden();
        $this->assertNull($this->owner()->email_verified_at);
    }

    public function test_the_admin_can_ask_for_the_link_again_three_times_an_hour(): void
    {
        Notification::fake();
        $admin = $this->demoAwaitingVerification();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/email/verification-notification')->assertStatus(202);
        }
        $this->postJson('/api/email/verification-notification')->assertStatus(429)
            ->assertJsonPath('message', 'طلبات كتير في وقت قصير، جرّب بعد شوية.');
        Notification::assertSentToTimes($admin, VerifyEmailNotification::class, 3);
    }

    public function test_a_confirmed_or_non_admin_account_is_not_sent_a_link(): void
    {
        Notification::fake();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/email/verification-notification')->assertOk()->assertJsonPath('verified', true);

        Sanctum::actingAs(User::factory()->unverified()->create(['role' => 'teacher']));
        $this->postJson('/api/email/verification-notification')->assertForbidden();
        Notification::assertNothingSent();
    }

    public function test_after_three_unconfirmed_days_sms_is_refused_with_402_and_queued_sms_is_skipped(): void
    {
        $this->demoAwaitingVerification();

        $this->travel(2)->days();
        $this->postJson('/api/me/phone', ['phone' => 'bad'])->assertUnprocessable();

        $this->travel(1)->days();
        $this->travel(1)->minutes();
        $this->postJson('/api/me/phone', ['phone' => '01012345678'])->assertStatus(402)
            ->assertJsonPath('code', 'email_unverified');

        $delivery = NotificationDelivery::query()->create([
            'ref' => 'r1', 'user_id' => 1, 'channel' => 'sms', 'category' => 'absence',
            'title' => 'غياب', 'body' => 'غاب اليوم', 'state' => 'queued', 'attempts' => 0, 'send_after' => now(),
        ]);
        (new SendChannelDelivery($delivery->id, 'sms'))->handle(app(ChannelRegistry::class));
        $this->assertSame('skipped', $delivery->fresh()->state);
        $this->assertSame('email_unverified', $delivery->fresh()->reason);
    }

    public function test_confirming_lifts_the_block_and_centers_opened_by_the_operator_are_never_blocked(): void
    {
        $admin = $this->demoAwaitingVerification();
        $this->travel(4)->days();
        $this->assertTrue(School::query()->findOrFail('demo')->smsBlockedForUnverifiedEmail());

        app(VerifyEmail::class)->handle($this->school, $admin);
        $this->assertFalse(School::query()->findOrFail('demo')->smsBlockedForUnverifiedEmail());

        $operatorMade = new School(['id' => 'x', 'name' => 'x', 'plan' => 't3']);
        $this->assertFalse($operatorMade->awaitsEmailVerification());
        $this->assertNull($operatorMade->smsBlockedFrom());
    }

    public function test_a_mail_failure_never_fails_the_sign_up_and_the_resend_then_works(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);
        $this->signUp();
        $owner = $this->owner();
        $this->assertNull($owner->email_verified_at);

        Notification::fake();
        Sanctum::actingAs($owner);
        $this->postJson('http://alnour.mudarisy.test/api/email/verification-notification')->assertStatus(202);
        Notification::assertSentTo($owner, VerifyEmailNotification::class);
    }

    public function test_an_unconfirmed_admin_who_fixes_a_typo_gets_a_link_at_the_new_address(): void
    {
        Notification::fake();
        $admin = $this->demoAwaitingVerification();

        $this->putJson('/api/auth/profile', ['name' => $admin->name, 'email' => 'fixed@demo.test'])->assertOk();

        Notification::assertSentTo($admin->fresh(), VerifyEmailNotification::class, fn ($n) => str_contains($n->url, '/'.sha1('fixed@demo.test').'?'));
    }

    public function test_demo_accounts_are_already_confirmed(): void
    {
        app(DemoAccounts::class)->ensure();

        $this->assertSame(0, User::query()->where('is_demo', true)->whereNull('email_verified_at')->count());
    }
}
