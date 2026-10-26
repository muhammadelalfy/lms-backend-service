<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Requests;

use Illuminate\Validation\Validator;
use Modules\Ai\Http\Requests\Concerns\DescribesDraftQuestion;

/** Edit any field of a draft question; only the sent fields change. */
class UpdateDraftQuestionRequest extends ReviewDraftRequest
{
    use DescribesDraftQuestion;

    public function rules(): array
    {
        return $this->questionRules(partial: true);
    }

    public function messages(): array
    {
        return $this->questionMessages();
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->checkQuestion($validator, $this->question())];
    }
}
