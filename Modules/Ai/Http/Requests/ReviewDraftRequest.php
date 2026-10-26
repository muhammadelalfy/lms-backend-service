<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Requests;

/** A review action on a draft (approve, remove, approve all unflagged...). */
class ReviewDraftRequest extends DraftRequest
{
    protected string $ability = 'review';
}
