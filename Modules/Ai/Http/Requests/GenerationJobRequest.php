<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Requests;

use Modules\Ai\Models\GenerationJob;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** A request about one job (status, cancel, retry). Someone else's job is "not found". */
class GenerationJobRequest extends AiRequest
{
    protected function deniedMessage(): string
    {
        return 'لا تملك صلاحية هذا التوليد.';
    }

    public function authorize(): bool
    {
        if (! $this->user()->isAnyRole('admin', 'teacher')) {
            return false;
        }
        if (! $this->user()->can('view', $this->job())) {
            throw new NotFoundHttpException;
        }

        return $this->allowed(true);
    }

    public function rules(): array
    {
        return [];
    }

    public function job(): GenerationJob
    {
        $job = $this->route('job');
        if (! $job instanceof GenerationJob) {
            throw new \LogicException('The route names no job.');
        }

        return $job;
    }
}
