<?php

declare(strict_types=1);

namespace Modules\Exams\Http\Requests;

class PublishExamResultRequest extends ExamResultRequest
{
    protected string $ability = 'publish';
}
