<?php

declare(strict_types=1);

namespace Modules\Ai\Enums;

/** queued -> retrieving -> generating -> verifying -> ready | needs_attention (under-filled) | failed | cancelled */
enum JobStatus: string
{
    case Queued = 'queued';
    case Retrieving = 'retrieving';
    case Generating = 'generating';
    case Verifying = 'verifying';
    case Ready = 'ready';
    case NeedsAttention = 'needs_attention';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /** @return list<string> the states of a job that still holds a reservation and may run */
    public static function activeValues(): array
    {
        return [self::Queued->value, self::Retrieving->value, self::Generating->value, self::Verifying->value];
    }

    public function isActive(): bool
    {
        return in_array($this->value, self::activeValues(), true);
    }
}
