<?php

declare(strict_types=1);

namespace Modules\Auth\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The signed link that confirms a center admin's email. Built before queueing, so the worker needs no request. */
class VerifyEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(public readonly string $url, public readonly string $centerName, public readonly int $hours)
    {
        $this->afterCommit();
    }

    /** @return list<int> */
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
        return (new MailMessage)
            ->subject('أكّد بريدك الإلكتروني — '.$this->centerName)
            ->greeting('أهلاً '.($notifiable->name ?? ''))
            ->line("دوس على الزرار علشان تأكد بريدك وتفضل كل مميزات مركز {$this->centerName} شغالة.")
            ->action('تأكيد البريد الإلكتروني', $this->url)
            ->line("الرابط صالح لمدة {$this->hours} ساعة. لو مش انت اللي سجلت، تجاهل الرسالة دي.");
    }
}
