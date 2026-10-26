<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Requests;

use Modules\Ai\Models\ExamBlueprint;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A request about one blueprint (show, duplicate). Someone else's private blueprint is "not found",
 * not "forbidden"; `ability` is what the action needs beyond seeing it.
 */
class BlueprintRequest extends AiRequest
{
    protected string $ability = 'view';

    protected function deniedMessage(): string
    {
        return 'لا تملك صلاحية معايير هذا الامتحان.';
    }

    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user->isAnyRole('admin', 'teacher')) {
            return false;
        }
        if (! $user->can('view', $this->blueprint())) {
            throw new NotFoundHttpException;
        }

        return $this->allowed($user->can($this->ability, $this->blueprint()));
    }

    public function rules(): array
    {
        return [];
    }

    public function blueprint(): ExamBlueprint
    {
        $blueprint = $this->route('blueprint');
        if (! $blueprint instanceof ExamBlueprint) {
            throw new \LogicException('The route names no blueprint.');
        }

        return $blueprint;
    }
}
