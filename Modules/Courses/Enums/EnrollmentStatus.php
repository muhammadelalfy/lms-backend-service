<?php

declare(strict_types=1);

namespace Modules\Courses\Enums;

/** pending → active → completed | cancelled | expired; an expired enrollment can be renewed. */
enum EnrollmentStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'بانتظار السداد',
            self::Active => 'مسجّل',
            self::Completed => 'مكتمل',
            self::Cancelled => 'ملغى',
            self::Expired => 'انتهى الاشتراك',
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, match ($this) {
            self::Pending => [self::Active, self::Cancelled],
            self::Active => [self::Completed, self::Cancelled, self::Expired],
            self::Expired => [self::Active],
            self::Completed, self::Cancelled => [],
        }, true);
    }
}
