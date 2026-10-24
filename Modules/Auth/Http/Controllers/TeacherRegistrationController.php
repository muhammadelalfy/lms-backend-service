<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Modules\Auth\Models\User;
use Modules\Auth\Services\AuditTrail;
use Modules\Auth\Services\DemoAccounts;

/** A teacher asks for an account; they can sign in only after the school's admin approves it. */
class TeacherRegistrationController extends Controller
{
    /** What the sign-in pages may offer on this school. */
    public function options(DemoAccounts $demo)
    {
        return [
            'teacher_registration' => true,
            'password_reset' => PasswordResetController::enabled(),
            // The roles the "try it" buttons may sign in as; empty on a school without demo logins.
            'demo_logins' => DemoLoginController::roles($demo),
        ];
    }

    public function register(Request $request, AuditTrail $audit)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        // Never mass-assigned: a request cannot choose its own role or status.
        $user = (new User(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]))
            ->forceFill(['role' => 'teacher', 'status' => User::STATUS_PENDING]);
        $user->save();
        $audit->record(null, 'teacher.registered', $user);

        return response()->json(['message' => 'تم استلام طلبك. ستتمكن من الدخول بعد موافقة إدارة المركز.'], 202);
    }
}
