<?php

declare(strict_types=1);

namespace Modules\Exams\Http\Requests;

class ListCorrectionsRequest extends ExamResultRequest
{
    protected string $ability = 'viewCorrections';

    protected function deniedMessage(): string
    {
        return 'سجل التصحيحات متاح للإدارة فقط.';
    }
}
