<?php

declare(strict_types=1);

namespace Modules\Auth\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Students\Models\Student;
use Modules\Students\Models\StudentAccount;

/**
 * The accounts behind a school's "try it" buttons: one per role, with a password nobody knows, so the
 * only way in is the demo sign-in the operator switched on. The parent and the student are linked to
 * a student so their pages have something to show.
 */
final class DemoAccounts
{
    public const ROLES = ['admin', 'teacher', 'parent', 'student'];

    private const NAMES = ['admin' => 'مدير تجريبي', 'teacher' => 'معلم تجريبي', 'parent' => 'ولي أمر تجريبي', 'student' => 'طالب تجريبي'];

    /** Creates whatever is missing; running it again changes nothing. */
    public function ensure(): void
    {
        DB::transaction(function (): void {
            $student = Student::query()->orderBy('id')->first()
                ?? Student::create(['name' => 'طالب تجريبي', 'group' => 'أ', 'grade' => 'الأول', 'phone' => '01000000000', 'parent_phone' => '01100000000', 'status' => 'average']);

            foreach (self::ROLES as $role) {
                $user = $this->userFor($role) ?? $this->make($role);
                if (in_array($role, ['parent', 'student'], true)) {
                    StudentAccount::updateOrCreate(['user_id' => $user->id], ['student_id' => $student->id, 'relationship' => $role]);
                }
            }
        });
    }

    public function userFor(string $role): ?User
    {
        return User::query()->where('is_demo', true)->where('role', $role)->first();
    }

    /** @return list<string> the roles that can be tried right now, in the order the page shows them */
    public function available(): array
    {
        $usable = User::query()->where('is_demo', true)->get()->filter(fn (User $user) => $user->isUsable())->pluck('role')->all();

        return array_values(array_filter(self::ROLES, fn (string $role) => in_array($role, $usable, true)));
    }

    private function make(string $role): User
    {
        $user = new User(['name' => self::NAMES[$role], 'email' => "demo-{$role}@demo.invalid", 'password' => Str::random(48)]);
        // Demo accounts are never asked to confirm an email.
        $user->forceFill(['role' => $role, 'is_demo' => true, 'status' => User::STATUS_ACTIVE, 'email_verified_at' => now()])->save();

        return $user;
    }
}
