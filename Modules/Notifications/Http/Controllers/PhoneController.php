<?php

namespace Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Notifications\Models\ContactChannel;
use Modules\Notifications\Models\NotificationDelivery;
use Modules\Notifications\Models\PhoneVerification;
use Modules\Notifications\Services\ChannelRegistry;
use Modules\Tenancy\Exceptions\EmailUnverified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * A person proves a phone number is theirs with a code sent by SMS. Only a
 * verified number can be switched on for SMS or WhatsApp notices.
 */
class PhoneController extends Controller
{
    public function send(Request $request, ChannelRegistry $channels)
    {
        // A center whose owner never confirmed their email sends no SMS after the grace days.
        EmailUnverified::guard();
        $phone = self::normalize((string) $request->validate(['phone' => 'required|string|max:24'])['phone']);
        if ($phone === null) {
            throw ValidationException::withMessages(['phone' => 'أدخل رقم هاتف صحيحاً، مثل 01012345678.']);
        }

        $limit = config('notifications.verification');
        $key = 'phone-code:'.$request->user()->id;
        if (RateLimiter::tooManyAttempts($key, $limit['sends_per_hour'])) {
            throw ValidationException::withMessages(['phone' => 'تجاوزت عدد المحاولات. حاول بعد قليل.']);
        }
        RateLimiter::hit($key, 3600);

        $code = (string) random_int(100000, 999999);
        $result = $channels->get('sms')->send($phone, new NotificationDelivery([
            'category' => 'verification',
            'title' => 'مُدرّسى',
            'body' => "رمز التحقق: {$code}",
        ]));
        if (! $result->ok) {
            throw ValidationException::withMessages(['phone' => 'تعذر إرسال الرمز الآن. حاول لاحقاً.']);
        }

        PhoneVerification::query()->updateOrCreate(['user_id' => $request->user()->id], [
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes($limit['ttl_minutes']),
        ]);

        return ['sent' => true, 'expires_in' => $limit['ttl_minutes'] * 60];
    }

    public function verify(Request $request)
    {
        $code = $request->validate(['code' => 'required|digits:6'])['code'];
        $pending = PhoneVerification::query()->where('user_id', $request->user()->id)->first();
        $limit = config('notifications.verification');

        if ($pending === null || $pending->expires_at->isPast() || $pending->attempts >= $limit['max_attempts']) {
            throw ValidationException::withMessages(['code' => 'انتهت صلاحية الرمز. اطلب رمزاً جديداً.']);
        }
        if (! Hash::check($code, $pending->code_hash)) {
            $pending->increment('attempts');
            throw ValidationException::withMessages(['code' => 'الرمز غير صحيح.']);
        }

        foreach (ChannelRegistry::PAID as $channel) {
            $existing = ContactChannel::query()->where(['user_id' => $pending->user_id, 'channel' => $channel])->first();
            // The same number keeps its switches; a new number starts switched off.
            $same = $existing?->address === $pending->phone;
            ContactChannel::query()->updateOrCreate(
                ['user_id' => $pending->user_id, 'channel' => $channel],
                [
                    'address' => $pending->phone,
                    'verified_at' => now(),
                    'opted_in_at' => $same ? $existing->opted_in_at : null,
                    'opted_out_at' => $same ? $existing->opted_out_at : null,
                    'source' => $same ? $existing->source : null,
                ],
            );
        }
        $pending->delete();

        return ['verified' => true, 'phone' => self::mask($pending->phone)];
    }

    public function destroy(Request $request)
    {
        ContactChannel::query()->where('user_id', $request->user()->id)->delete();
        PhoneVerification::query()->where('user_id', $request->user()->id)->delete();

        return response()->noContent();
    }

    /** +20… from 01012345678, 0020…, or an international number; null when it cannot be a mobile number. */
    public static function normalize(string $input): ?string
    {
        $digits = preg_replace('/[^\d+]/', '', trim($input));
        if (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        } elseif (preg_match('/^01\d{9}$/', $digits)) {
            $digits = '+2'.$digits;
        }

        return preg_match('/^\+[1-9]\d{7,14}$/', $digits) ? $digits : null;
    }

    /** +20••••••5678 */
    public static function mask(string $phone): string
    {
        return substr($phone, 0, 3).str_repeat('•', max(0, strlen($phone) - 7)).substr($phone, -4);
    }
}
