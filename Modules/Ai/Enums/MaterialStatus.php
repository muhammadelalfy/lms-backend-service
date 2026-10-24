<?php

declare(strict_types=1);

namespace Modules\Ai\Enums;

/** uploaded -> extracting -> ready | failed | needs_ocr; a failed material can be retried (back to uploaded). */
enum MaterialStatus: string
{
    case Uploaded = 'uploaded';
    case Extracting = 'extracting';
    case Ready = 'ready';
    case Failed = 'failed';
    case NeedsOcr = 'needs_ocr';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Uploaded => [self::Extracting, self::Failed],
            self::Extracting => [self::Extracting, self::Uploaded, self::Ready, self::Failed, self::NeedsOcr],
            self::Failed => [self::Uploaded],
            self::Ready, self::NeedsOcr => [],
        };
    }

    public function canBecome(self $target): bool
    {
        return in_array($target, $this->next(), true);
    }
}
