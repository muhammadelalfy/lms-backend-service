<?php

declare(strict_types=1);

namespace Modules\Ai\Support;

/** The difficulty levels and Bloom levels a blueprint and a question use (the AI service's vocabulary). */
final class Taxonomy
{
    public const DIFFICULTIES = ['easy', 'medium', 'hard'];

    // تذكر، فهم، تطبيق، تحليل، تقييم، إبداع
    public const BLOOM = ['remember', 'understand', 'apply', 'analyze', 'evaluate', 'create'];
}
