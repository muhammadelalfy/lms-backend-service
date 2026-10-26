<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Auth\Actions\SendEmailVerification;
use Modules\Auth\Actions\VerifyEmail;
use Modules\Auth\Models\User;

/**
 * Confirming a center admin's email: the signed link from the mail (opened in a browser, or by the app
 * as JSON), and "send the link again" for the signed-in admin.
 */
class EmailVerificationController extends Controller
{
    public const INVALID = 'رابط التأكيد غير صالح أو انتهت صلاحيته. اطلب رابط جديد من داخل حسابك.';

    public function verify(Request $request, string $id, string $hash, VerifyEmail $verify): JsonResponse|RedirectResponse
    {
        $user = $request->hasValidRelativeSignature() ? User::query()->find($id) : null;
        if ($user === null || ! hash_equals(sha1((string) $user->email), $hash)) {
            return $request->expectsJson()
                ? response()->json(['message' => self::INVALID, 'code' => 'invalid_verification_link', 'can_resend' => true], 403)
                : redirect('/login?email_verification=invalid');
        }

        $first = $verify->handle(tenant(), $user);

        return $request->expectsJson()
            ? response()->json(['message' => 'تم تأكيد بريدك الإلكتروني.', 'verified' => true, 'already_verified' => ! $first])
            : redirect('/login?email_verified=1');
    }

    public function resend(Request $request, SendEmailVerification $send): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isAnyRole('admin'), 403, 'تأكيد البريد من هنا للمشرف فقط.');
        if ($user->email_verified_at !== null) {
            return response()->json(['message' => 'بريدك مؤكد بالفعل.', 'verified' => true]);
        }

        abort_unless($send->handle(tenant(), $user), 503, 'تعذّر إرسال الرابط الآن. جرّب تاني بعد شوية.');

        return response()->json(['message' => 'بعتنا لك رابط تأكيد جديد على بريدك.', 'verified' => false], 202);
    }
}
