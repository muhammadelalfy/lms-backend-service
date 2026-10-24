<?php

declare(strict_types=1);

namespace Modules\Courses\Http\Requests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Courses\Models\AccessCode;
use Modules\Courses\Models\Course;
use Modules\Courses\Models\CourseLesson;
use Modules\Courses\Models\CourseSubject;

/** Base of the course requests: who may act, judged against the course the route points at. */
abstract class CourseRequest extends FormRequest
{
    protected function failedAuthorization(): never
    {
        throw new AuthorizationException('لا تملك صلاحية هذه الدورة.');
    }

    protected function isStaff(): bool
    {
        return $this->user()->isAnyRole('admin', 'teacher');
    }

    /** The course this request is about, whichever of course, section, lesson or code the route names. */
    protected function course(): Course
    {
        foreach (['course', 'subject', 'lesson', 'code'] as $name) {
            $model = $this->route($name);
            if ($model instanceof Course) {
                return $model;
            }
            if ($model instanceof AccessCode) {
                return $model->course;
            }
            if ($model instanceof CourseSubject) {
                return $model->course;
            }
            if ($model instanceof CourseLesson) {
                return $model->subject->course;
            }
        }

        throw new \LogicException('The route names no course.');
    }

    /** Staff who run this course (an admin, or a teacher who teaches it). */
    protected function managesCourse(): bool
    {
        return $this->isStaff() && $this->user()->can('update', $this->course());
    }
}
