<?php

declare(strict_types=1);

namespace Modules\Ai\Enums;

/** A draft is reviewed while `draft`; once `published` into Exams it is frozen. */
enum DraftStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
