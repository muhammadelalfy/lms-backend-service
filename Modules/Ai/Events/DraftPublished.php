<?php

declare(strict_types=1);

namespace Modules\Ai\Events;

/** A draft was published into Exams as an ExamTemplate; dispatched after the transaction committed. */
final class DraftPublished
{
    public function __construct(public readonly int $draftId, public readonly int $templateId, public readonly int $publishedBy) {}
}
