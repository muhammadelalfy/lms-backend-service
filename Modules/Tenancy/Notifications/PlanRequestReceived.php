<?php

declare(strict_types=1);

namespace Modules\Tenancy\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Tenancy\Models\PlanRequest;

/** Tells the sales inbox (landing.contact_email) that a plan request arrived. */
class PlanRequestReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(public readonly PlanRequest $planRequest)
    {
        $this->afterCommit();
    }

    /** @return list<int> seconds between retries of a failed send */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $r = $this->planRequest;
        $kind = ['custom' => 'باقة مخصصة', 'upgrade' => 'ترقية باقة', 'lead' => 'تواصل'][$r->type] ?? $r->type;
        $lines = array_filter([
            'النوع' => $kind,
            'الاسم' => $r->name,
            'الموبايل' => $r->phone,
            'البريد' => $r->email,
            'اسم المركز' => $r->org_name,
            'كود المركز' => $r->school_id,
            'نوع النشاط' => $r->audience,
            'عدد الطلاب التقريبي' => $r->students_estimate,
            'الباقة' => $r->plan_slug,
        ], fn ($value) => $value !== null && $value !== '');

        $mail = (new MailMessage)->subject("طلب {$kind} جديد #{$r->id}")->greeting('طلب جديد');
        foreach ($lines as $label => $value) {
            $mail->line("{$label}: {$value}");
        }

        return $mail->line('الاحتياج:')->line($r->message);
    }
}
