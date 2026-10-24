<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Feature;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Modules\Auth\Models\AuditLog;
use Modules\Auth\Models\User;
use Tests\TestCase;

/** Teachers who register, the admin who approves them, and the safeguards around accounts. */
class AccountsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Teacher#2026pw';

    private function admin(array $overrides = []): User
    {
        return User::factory()->create($overrides + ['role' => 'admin']);
    }

    private function registration(array $overrides = []): array
    {
        return $overrides + ['name' => 'أحمد المعلم', 'email' => 'ahmed@school.test', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD];
    }

    private function pendingTeacher(): User
    {
        $this->postJson('/api/auth/register-teacher', $this->registration())->assertAccepted();

        return User::where('email', 'ahmed@school.test')->firstOrFail();
    }

    public function test_the_sign_in_pages_learn_what_this_school_offers(): void
    {
        $this->getJson('/api/auth/options')->assertOk()->assertJsonPath('teacher_registration', true)->assertJsonPath('password_reset', false);

        config(['mail.default' => 'smtp']);
        $this->getJson('/api/auth/options')->assertJsonPath('password_reset', true);
    }

    public function test_a_teacher_registers_as_pending_and_cannot_sign_in_until_approved(): void
    {
        $teacher = $this->pendingTeacher();

        $this->assertSame(['teacher', 'pending'], [$teacher->role, $teacher->status]);
        $this->assertSame('pending', $teacher->accountState());
        $this->postJson('/api/auth/teacher/login', ['email' => 'ahmed@school.test', 'password' => self::PASSWORD])
            ->assertForbidden()->assertJsonPath('message', 'حسابك قيد المراجعة. ستتمكن من الدخول بعد موافقة إدارة المركز.');
        $this->postJson('/api/auth/login', ['email' => 'ahmed@school.test', 'password' => self::PASSWORD])->assertForbidden();
    }

    public function test_a_registration_cannot_pick_its_own_role_or_status(): void
    {
        $this->postJson('/api/auth/register-teacher', $this->registration(['role' => 'admin', 'status' => 'active']))->assertAccepted();

        $user = User::where('email', 'ahmed@school.test')->firstOrFail();
        $this->assertSame(['teacher', 'pending'], [$user->role, $user->status]);
    }

    public function test_registration_needs_a_strong_password_a_confirmation_and_a_free_email(): void
    {
        $this->postJson('/api/auth/register-teacher', $this->registration(['password' => '123', 'password_confirmation' => '123']))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/auth/register-teacher', $this->registration(['password_confirmation' => 'different']))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/auth/register-teacher', $this->registration(['email' => 'not-an-email']))->assertUnprocessable();

        User::factory()->create(['email' => 'taken@school.test']);
        $this->postJson('/api/auth/register-teacher', $this->registration(['email' => 'taken@school.test']))->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame(1, User::count());
    }

    public function test_registration_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/register-teacher', $this->registration(['email' => "t{$i}@school.test"]))->assertAccepted();
        }
        $this->postJson('/api/auth/register-teacher', $this->registration(['email' => 'late@school.test']))->assertStatus(429);
    }

    public function test_the_admin_approves_a_teacher_who_can_then_sign_in(): void
    {
        $teacher = $this->pendingTeacher();
        Sanctum::actingAs($admin = $this->admin());

        $this->postJson("/api/users/{$teacher->id}/approve")->assertOk()->assertJsonPath('state', 'active');
        $this->postJson("/api/users/{$teacher->id}/approve")->assertUnprocessable(); // already active

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/teacher/login', ['email' => 'ahmed@school.test', 'password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('user.role', 'teacher')->assertJsonStructure(['token']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.approved', 'actor_id' => $admin->id, 'subject_id' => $teacher->id]);
    }

    public function test_rejecting_removes_a_pending_account_and_only_a_pending_one(): void
    {
        $teacher = $this->pendingTeacher();
        $active = User::factory()->create(['role' => 'teacher']);
        Sanctum::actingAs($this->admin());

        $this->deleteJson("/api/users/{$active->id}/reject")->assertUnprocessable();
        $this->deleteJson("/api/users/{$teacher->id}/reject")->assertNoContent();

        $this->assertNull(User::find($teacher->id));
        $this->assertNotNull(User::find($active->id));
        // The trail keeps who was turned away, even though the account is gone.
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.rejected', 'subject_label' => 'أحمد المعلم <ahmed@school.test>']);
    }

    public function test_only_an_admin_reaches_the_account_tools(): void
    {
        $target = User::factory()->create(['role' => 'teacher']);

        foreach (['teacher', 'parent', 'student'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/api/users')->assertForbidden();
            $this->postJson('/api/users', ['name' => 'x', 'email' => 'x@x.test', 'password' => self::PASSWORD, 'role' => 'admin'])->assertForbidden();
            $this->postJson("/api/users/{$target->id}/approve")->assertForbidden();
            $this->postJson("/api/users/{$target->id}/disable")->assertForbidden();
            $this->patchJson("/api/users/{$target->id}/role", ['role' => 'admin'])->assertForbidden();
            $this->postJson("/api/users/{$target->id}/reset-password")->assertForbidden();
            $this->getJson('/api/audit-logs')->assertForbidden();
        }
        $this->assertSame('teacher', $target->fresh()->role);
    }

    public function test_the_user_list_puts_who_is_waiting_first_and_filters(): void
    {
        $this->pendingTeacher();
        User::factory()->create(['role' => 'teacher', 'name' => 'نادية']);
        User::factory()->create(['role' => 'parent', 'name' => 'ولي أمر']);
        $off = User::factory()->create(['role' => 'teacher', 'name' => 'موقوف']);
        $off->forceFill(['disabled_at' => now()])->save();
        Sanctum::actingAs($this->admin());

        $all = $this->getJson('/api/users')->assertOk();
        $this->assertSame('pending', $all->json('data.0.state'));
        $this->assertSame(['total' => 5, 'pending' => 1, 'disabled' => 1], $all->json('summary'));

        $this->assertSame(['ahmed@school.test'], collect($this->getJson('/api/users?state=pending')->json('data'))->pluck('email')->all());
        $this->assertSame(['موقوف'], collect($this->getJson('/api/users?state=disabled')->json('data'))->pluck('name')->all());
        $this->assertSame(['ولي أمر'], collect($this->getJson('/api/users?role=parent')->json('data'))->pluck('name')->all());
        $this->assertSame(['نادية'], collect($this->getJson('/api/users?q=نادية')->json('data'))->pluck('name')->all());
        $this->getJson('/api/users?role=owner')->assertUnprocessable();
        // A search term's wildcards are plain characters, not patterns.
        $this->assertCount(0, $this->getJson('/api/users?q=%25')->json('data'));
    }

    public function test_the_admin_adds_staff_who_are_active_at_once(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/users', ['name' => 'معلمة جديدة', 'email' => 'new@school.test', 'password' => self::PASSWORD, 'role' => 'teacher'])
            ->assertCreated()->assertJsonPath('state', 'active')->assertJsonPath('role', 'teacher');
        $this->postJson('/api/users', ['name' => 'x', 'email' => 'p@school.test', 'password' => self::PASSWORD, 'role' => 'parent'])->assertUnprocessable();
        $this->postJson('/api/users', ['name' => 'x', 'email' => 'new@school.test', 'password' => self::PASSWORD, 'role' => 'teacher'])->assertUnprocessable();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/teacher/login', ['email' => 'new@school.test', 'password' => self::PASSWORD])->assertOk();
    }

    public function test_disabling_signs_the_account_out_and_enabling_brings_it_back(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'email' => 'on@school.test']);
        $token = $teacher->createToken('app')->plainTextToken;
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/users/{$teacher->id}/disable")->assertOk()->assertJsonPath('state', 'disabled');
        $this->assertSame(0, $teacher->tokens()->count());
        $this->postJson('/api/auth/teacher/login', ['email' => 'on@school.test', 'password' => 'password'])->assertForbidden();

        $this->postJson("/api/users/{$teacher->id}/enable")->assertOk()->assertJsonPath('state', 'active');
        $this->postJson('/api/auth/teacher/login', ['email' => 'on@school.test', 'password' => 'password'])->assertOk();
        $this->assertNotEmpty($token);
    }

    public function test_an_admin_cannot_lock_themselves_out_or_remove_the_last_admin(): void
    {
        $only = $this->admin();
        Sanctum::actingAs($only);
        $this->postJson("/api/users/{$only->id}/disable")->assertUnprocessable();
        $this->patchJson("/api/users/{$only->id}/role", ['role' => 'teacher'])->assertUnprocessable();

        // A second admin may be switched off, until they are the last one standing.
        $second = $this->admin();
        $this->postJson("/api/users/{$second->id}/disable")->assertOk();
        $third = $this->admin();
        Sanctum::actingAs($third);
        $this->postJson("/api/users/{$only->id}/disable")->assertOk();
        // `third` is now the only usable admin; another admin cannot demote them while it is the last.
        $fourth = $this->admin(['disabled_at' => now()]);
        Sanctum::actingAs($fourth);
        $this->patchJson("/api/users/{$third->id}/role", ['role' => 'teacher'])->assertUnprocessable()->assertJsonPath('message', 'لا يمكن إيقاف آخر مشرف نشط في المركز.');
        $this->assertSame('admin', $third->fresh()->role);
    }

    public function test_the_role_of_staff_can_change_but_not_of_parents_or_students(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $parent = User::factory()->create(['role' => 'parent']);
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/users/{$teacher->id}/role", ['role' => 'admin'])->assertOk()->assertJsonPath('role', 'admin');
        $this->patchJson("/api/users/{$parent->id}/role", ['role' => 'admin'])->assertUnprocessable();
        $this->patchJson("/api/users/{$teacher->id}/role", ['role' => 'student'])->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['action' => 'role.changed', 'subject_id' => $teacher->id]);
    }

    public function test_an_admin_resets_a_password_and_the_old_one_stops_working(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'email' => 'reset@school.test']);
        $teacher->createToken('app');
        Sanctum::actingAs($this->admin());

        $generated = $this->postJson("/api/users/{$teacher->id}/reset-password")->assertOk()->json('password');
        $this->assertGreaterThanOrEqual(14, strlen($generated));
        $this->assertSame(0, $teacher->tokens()->count());
        $this->postJson('/api/auth/teacher/login', ['email' => 'reset@school.test', 'password' => 'password'])->assertUnprocessable();
        $this->postJson('/api/auth/teacher/login', ['email' => 'reset@school.test', 'password' => $generated])->assertOk();

        $this->postJson("/api/users/{$teacher->id}/reset-password", ['password' => 'Chosen#2026pw'])->assertOk()->assertExactJson(['success' => true]);
        $this->postJson("/api/users/{$teacher->id}/reset-password", ['password' => 'short'])->assertUnprocessable();
        // Neither password is ever written to the trail.
        $this->assertStringNotContainsString($generated, json_encode(AuditLog::all()->toArray()));
        $this->assertStringNotContainsString('Chosen#2026pw', json_encode(AuditLog::all()->toArray()));
    }

    public function test_forgot_password_sends_a_link_and_the_link_sets_a_new_password(): void
    {
        config(['mail.default' => 'smtp']);
        Notification::fake();
        $user = User::factory()->create(['role' => 'teacher', 'email' => 'forgot@school.test']);
        $user->createToken('app');

        $same = $this->postJson('/api/auth/forgot-password', ['email' => 'forgot@school.test'])->assertOk()->json('message');
        $this->assertSame($same, $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@school.test'])->assertOk()->json('message'));

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token, $user) {
            $token = $n->token;
            $mail = $n->toMail($user);

            // The message carries a working link to this school's own page, in Arabic.
            $this->assertSame('http://demo.mudarisy.test/reset-password?token='.$token.'&email='.urlencode('forgot@school.test'), $mail->actionUrl);
            $this->assertSame('إعادة تعيين كلمة المرور', $mail->subject);
            $this->assertStringNotContainsString('Laravel', $mail->salutation);

            return true;
        });
        Notification::assertCount(1); // the unknown address got nothing

        $body = ['token' => $token, 'email' => 'forgot@school.test', 'password' => 'Brand#New2026', 'password_confirmation' => 'Brand#New2026'];
        $this->postJson('/api/auth/reset-password', ['token' => 'wrong'] + $body)->assertUnprocessable();
        $this->postJson('/api/auth/reset-password', $body)->assertOk();
        $this->postJson('/api/auth/reset-password', $body)->assertUnprocessable(); // a link works once

        $this->assertSame(0, $user->tokens()->count());
        $this->postJson('/api/auth/teacher/login', ['email' => 'forgot@school.test', 'password' => 'Brand#New2026'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'password.reset', 'subject_id' => $user->id]);
    }

    public function test_password_reset_does_not_exist_until_mail_is_configured_and_never_serves_other_accounts(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => 'a@b.test'])->assertNotFound();
        $this->postJson('/api/auth/reset-password', ['token' => 'x', 'email' => 'a@b.test', 'password' => 'Brand#New2026', 'password_confirmation' => 'Brand#New2026'])->assertNotFound();

        config(['mail.default' => 'smtp']);
        Notification::fake();
        $pending = $this->pendingTeacher();
        $disabled = User::factory()->create(['email' => 'off@school.test']);
        $disabled->forceFill(['disabled_at' => now()])->save();
        $this->postJson('/api/auth/forgot-password', ['email' => $pending->email])->assertOk();
        $this->postJson('/api/auth/forgot-password', ['email' => 'off@school.test'])->assertOk();
        Notification::assertNothingSent();
    }

    public function test_the_audit_trail_is_listed_for_the_admin_newest_first(): void
    {
        $teacher = $this->pendingTeacher();
        Sanctum::actingAs($admin = $this->admin());
        $this->postJson("/api/users/{$teacher->id}/approve")->assertOk();

        $log = $this->getJson('/api/audit-logs')->assertOk();
        $this->assertSame(['account.approved', 'teacher.registered'], collect($log->json('data'))->pluck('action')->all());
        $this->assertSame($admin->name, $log->json('data.0.actor'));
        $this->assertNull($log->json('data.1.actor')); // the registration was made by no one signed in
    }
}
