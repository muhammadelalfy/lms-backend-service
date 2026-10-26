<?php

declare(strict_types=1);

namespace Modules\Ai\Enums;

enum ExcelMode: string
{
    case Auto = 'auto';
    case Content = 'content';
    case QuestionBank = 'question_bank';
}
