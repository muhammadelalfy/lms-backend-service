<?php

declare(strict_types=1);

namespace Modules\Tenancy\Services;

use Modules\Auth\Models\User;
use Modules\Courses\Models\Course;
use Modules\Courses\Models\Enrollment;
use Modules\Payments\Models\Payment;
use Modules\Students\Models\Student;
use Modules\Tenancy\Models\School;

/**
 * The welcome checklist of a new center. Every step is derived from the center's own data (no table of
 * its own), so it ticks itself the moment the thing is done. One `exists` query per data step.
 */
class OnboardingChecklist
{
    private const LABELS = [
        'verify_email' => 'أكّد بريدك الإلكتروني',
        'add_logo' => 'ارفع شعار مركزك',
        'create_course' => 'أنشئ أول كورس',
        'add_teacher' => 'أضف مدرس',
        'enroll_first_student' => 'سجّل أول طالب (بكود أو يدوياً)',
        'set_payment' => 'اضبط طريقة الدفع',
    ];

    public function for(School $school, User $admin): array
    {
        $done = [
            'verify_email' => ! $school->awaitsEmailVerification(),
            'add_logo' => filled($school->logo_url),
            'create_course' => Course::query()->exists(),
            'add_teacher' => User::query()->where('role', 'teacher')->where('status', User::STATUS_ACTIVE)->exists(),
            'enroll_first_student' => Enrollment::query()->exists() || Student::query()->exists(),
            'set_payment' => filled($school->fawry_merchant_code) || Payment::query()->exists(),
        ];
        $links = (array) config('schools.onboarding.links');

        $steps = [];
        foreach (self::LABELS as $key => $label) {
            $steps[] = ['key' => $key, 'label' => $label, 'done' => $done[$key], 'href' => $links[$key] ?? null];
        }
        $count = count(array_filter($done));
        $completed = $count === count($steps);
        $dismissedAt = $school->onboarding_dismissed_at;
        $blockedFrom = $school->smsBlockedFrom();

        return [
            'steps' => $steps,
            'done_count' => $count,
            'total' => count($steps),
            'completed' => $completed,
            'dismissed_at' => $dismissedAt,
            // The card shows until dismissed or 6/6.
            'visible' => ! $completed && $dismissedAt === null,
            // The banner: shown while the center waits for a confirmed email.
            'verification' => [
                'verified' => $done['verify_email'],
                'email' => $admin->email,
                'sms_blocked_from' => $blockedFrom?->toIso8601String(),
                'sms_blocked' => $school->smsBlockedForUnverifiedEmail(),
            ],
        ];
    }
}
