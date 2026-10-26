<?php

declare(strict_types=1);

namespace Modules\Ai\Enums;

/** A whole draft, or one replacement question for a draft. */
enum JobKind: string
{
    case Generate = 'generate';
    case Regenerate = 'regenerate';
}
