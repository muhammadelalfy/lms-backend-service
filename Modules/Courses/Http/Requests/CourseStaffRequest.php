<?php

declare(strict_types=1);

namespace Modules\Courses\Http\Requests;

/** A request with no body that only staff who run the course may send (delete, cancel an enrollment, list its versions). */
class CourseStaffRequest extends CourseRequest
{
    public function authorize(): bool
    {
        return $this->managesCourse();
    }

    public function rules(): array
    {
        return [];
    }
}
