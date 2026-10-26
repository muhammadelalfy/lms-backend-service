<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Requests;

use Modules\Ai\Models\DraftExam;
use Modules\Ai\Models\DraftQuestion;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A request about one draft (and, when the route names one, one of its questions). Someone else's
 * draft, or a question of another draft, is "not found". `ability` is what the action needs.
 */
class DraftRequest extends AiRequest
{
    protected string $ability = 'view';

    protected function deniedMessage(): string
    {
        return 'لا تملك صلاحية هذه المسودة.';
    }

    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user->isAnyRole('admin', 'teacher')) {
            return false;
        }
        if (! $user->can('view', $this->draft())) {
            throw new NotFoundHttpException;
        }
        $question = $this->route('question');
        if ($question instanceof DraftQuestion && $question->draft_id !== $this->draft()->id) {
            throw new NotFoundHttpException;
        }

        return $this->allowed($user->can($this->ability, $this->draft()));
    }

    public function rules(): array
    {
        return [];
    }

    public function draft(): DraftExam
    {
        $draft = $this->route('draft');
        if (! $draft instanceof DraftExam) {
            throw new \LogicException('The route names no draft.');
        }

        return $draft;
    }

    public function question(): DraftQuestion
    {
        $question = $this->route('question');
        if (! $question instanceof DraftQuestion) {
            throw new \LogicException('The route names no question.');
        }

        return $question;
    }
}
