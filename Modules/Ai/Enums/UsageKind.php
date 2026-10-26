<?php

declare(strict_types=1);

namespace Modules\Ai\Enums;

/** What AI units were spent on (the usage screen's breakdown). */
enum UsageKind: string
{
    case Generate = 'generate';
    case Regenerate = 'regenerate';
    case Verify = 'verify';
    case Grade = 'grade';
}
