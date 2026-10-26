<?php

declare(strict_types=1);

namespace Modules\Exams\Http\Requests;

class DeleteExamResultRequest extends ExamResultRequest
{
    protected string $ability = 'delete';
}
