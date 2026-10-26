<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Requests;

use Illuminate\Validation\Validator;
use Modules\Ai\Http\Requests\Concerns\DescribesDraftQuestion;

/** A question the teacher writes into the draft by hand. */
class AddDraftQuestionRequest extends ReviewDraftRequest
{
    use DescribesDraftQuestion;

    public function rules(): array
    {
        return $this->questionRules(partial: false);
    }

    public function messages(): array
    {
        return $this->questionMessages();
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->checkQuestion($validator)];
    }
}
