<?php

declare(strict_types=1);

namespace Modules\Courses\Http\Requests;

use Illuminate\Validation\Rule;

class LessonRequest extends CourseRequest
{
    public function authorize(): bool
    {
        return $this->managesCourse();
    }

    public function rules(): array
    {
        return [
            'title' => [$this->isMethod('PATCH') ? 'sometimes' : 'required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:20000'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'key_points' => ['nullable', 'array', 'max:30'],
            'key_points.*' => ['string', 'max:300'],
            'estimated_duration_minutes' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'video_url' => ['nullable', 'url', 'max:500'],
            'live_at' => ['nullable', 'date'],
            'live_provider' => ['nullable', Rule::in(['meet', 'zoom', 'other'])],
            'live_link' => ['nullable', 'url', 'max:500', 'required_with:live_at'],
        ];
    }
}
