<?php

namespace Modules\Notifications\Jobs;

use Modules\Notifications\Models\ContactChannel;
use Modules\Notifications\Models\NotificationDelivery;
use Modules\Notifications\Models\NotificationPreference;
use Modules\Notifications\Models\NotificationSetting;
use Modules\Auth\Models\User;
use Modules\Notifications\Services\ChannelRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Sends one queued delivery on a paid channel, if it is still wanted: the
 * person has not read the notice, has the channel switched on and a
 * verified number, is not in quiet hours (unless the notice is critical)
 * and the channel's daily cap is not spent. Safe to run twice: only the run
 * that claims the row sends.
 */
class SendChannelDelivery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly int $deliveryId, public readonly string $channel)
    {
        $this->onQueue($channel);
    }

    /** Workers of one channel together stay under the provider's rate. */
    public function middleware(): array
    {
        return [new RateLimited('notify-'.$this->channel)];
    }

    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(ChannelRegistry $channels): void
    {
        $delivery = NotificationDelivery::query()->find($this->deliveryId);
        if ($delivery === null || $delivery->state !== 'queued') {
            return;
        }
        // A center whose owner never confirmed their email sends no SMS/WhatsApp after the grace days.
        if (tenant()?->smsBlockedForUnverifiedEmail()) {
            $this->skip($delivery, 'email_unverified');

            return;
        }

        $contact = ContactChannel::query()
            ->where(['user_id' => $delivery->user_id, 'channel' => $delivery->channel])
            ->first();
        if ($contact === null || ! $contact->canReceive()) {
            $this->skip($delivery, 'no_contact');

            return;
        }
        if (! NotificationPreference::enabled($delivery->user_id, $delivery->category, $delivery->channel)) {
            $this->skip($delivery, 'disabled');

            return;
        }
        if ($this->read($delivery)) {
            $this->skip($delivery, 'already_read');

            return;
        }

        $critical = (bool) config("notifications.categories.{$delivery->category}.critical", false);
        if (! $critical && ($end = NotificationSetting::quietEnd($delivery->user_id, now())) !== null) {
            $delivery->update(['send_after' => $end]);
            $this->release((int) max(60, now()->diffInSeconds($end, true)));

            return;
        }

        if ($this->capSpent($delivery->channel)) {
            $this->skip($delivery, 'cap');

            return;
        }

        // Only one worker gets past this line for a given delivery.
        $claimed = NotificationDelivery::query()
            ->whereKey($delivery->id)->where('state', 'queued')->update(['state' => 'sending']);
        if ($claimed === 0) {
            return;
        }
        $delivery->refresh();

        $result = $channels->get($delivery->channel)->send($contact->address, $delivery);
        $attempts = $delivery->attempts + 1;

        if ($result->ok) {
            $delivery->update(['state' => 'sent', 'provider_id' => $result->providerId, 'attempts' => $attempts, 'sent_at' => now()]);
            Cache::increment($this->capKey($delivery->channel));

            return;
        }
        if ($result->retryable && $attempts < $this->tries) {
            $delivery->update(['state' => 'queued', 'attempts' => $attempts, 'reason' => $result->reason]);
            $this->release($this->backoff()[min($attempts - 1, 3)]);

            return;
        }
        $delivery->update(['state' => 'failed', 'attempts' => $attempts, 'reason' => $result->reason]);
    }

    private function skip(NotificationDelivery $delivery, string $reason): void
    {
        $delivery->update(['state' => 'skipped', 'reason' => $reason]);
    }

    /** Whether the person already opened this notice in the app. */
    private function read(NotificationDelivery $delivery): bool
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $delivery->user_id)
            ->where('data->ref', $delivery->ref)
            ->whereNotNull('read_at')
            ->exists();
    }

    private function capKey(string $channel): string
    {
        return 'notify.sent.'.$channel.'.'.now()->toDateString();
    }

    private function capSpent(string $channel): bool
    {
        $cap = config("notifications.daily_caps.{$channel}");

        return $cap !== null && (int) Cache::get($this->capKey($channel), 0) >= $cap;
    }
}
