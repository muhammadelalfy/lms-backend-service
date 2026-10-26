<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Exams\Models\ExamSession;
use Modules\Exams\Models\ExamTemplate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A session's result: its own student or parent, the admin, or the teacher who owns the exam.
 * Anyone else gets "not found". Not plan-gated (it is the student's result, not an AI feature).
 */
class SessionResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $session = $this->session();
        $allowed = match (true) {
            $user->isAnyRole('student', 'parent') => $user->studentAccount?->student_id === $session->student_id,
            $user->isAnyRole('admin') => true,
            $user->isAnyRole('teacher') => (int) ExamTemplate::query()->whereKey($session->template_id)->value('created_by') === $user->id,
            default => false,
        };
        if (! $allowed) {
            throw new NotFoundHttpException('الامتحان غير موجود.');
        }

        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function session(): ExamSession
    {
        $session = $this->route('session');
        if (! $session instanceof ExamSession) {
            throw new \LogicException('The route names no exam session.');
        }

        return $session;
    }
}
