<?php

namespace Modules\Exams\Policies;

use Modules\Auth\Models\User;
use Modules\Exams\Models\ExamResult;
use Modules\Groups\Models\ClassGroup;
use Modules\Students\Models\Student;

/**
 * Who may write a student's exam result (ADR 0002): admin, or a teacher
 * whose groups include the result's student, resolved the same way
 * `Student.group` is matched against `ClassGroup.name` everywhere else.
 */
class ExamResultPolicy
{
    public function create(User $user, array $data = []): bool
    {
        if ($user->isAnyRole('admin')) {
            return true;
        }
        if (! $user->isAnyRole('teacher')) {
            return false;
        }
        $student = Student::query()->find($data['student_id'] ?? null);

        return $student !== null && $this->groupOwned($user, $student->group);
    }

    public function update(User $user, ExamResult $examResult): bool
    {
        return $this->owns($user, $examResult);
    }

    public function delete(User $user, ExamResult $examResult): bool
    {
        return $this->owns($user, $examResult);
    }

    /** Publishing is the recorder's step: whoever may edit the draft. */
    public function publish(User $user, ExamResult $examResult): bool
    {
        return $this->owns($user, $examResult);
    }

    /**
     * A published grade changes only through an audited correction, and only by the admin (like refunds
     * and certificate revocation): never by the recording teacher, a student or a parent.
     */
    public function correct(User $user, ExamResult $examResult): bool
    {
        return $user->isAnyRole('admin');
    }

    public function viewCorrections(User $user, ExamResult $examResult): bool
    {
        return $user->isAnyRole('admin');
    }

    private function owns(User $user, ExamResult $examResult): bool
    {
        if ($user->isAnyRole('admin')) {
            return true;
        }

        return $user->isAnyRole('teacher') && $this->groupOwned($user, $examResult->student?->group);
    }

    /** A group name a teacher may act on: theirs, unassigned, or unknown to Groups at all. */
    private function groupOwned(User $user, ?string $groupName): bool
    {
        return $groupName !== null && ! in_array($groupName, ClassGroup::namesClaimedByOthers($user), true);
    }
}
