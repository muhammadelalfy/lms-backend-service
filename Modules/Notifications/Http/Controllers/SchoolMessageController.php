<?php

namespace Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Concerns\AuthorizesStaff;
use Modules\Groups\Models\ClassGroup;
use Modules\Notifications\Models\DeviceToken;
use Modules\Notifications\Services\StudentNotifier;
use Modules\Students\Models\Student;

/**
 * Staff-written notifications to chosen students, a whole group or everyone,
 * delivered to the students, their parents, or both; plus push registration
 * of each signed-in device.
 */
class SchoolMessageController extends Controller
{
    use AuthorizesStaff;

    public function send(Request $request, StudentNotifier $notifier)
    {
        $this->authorizeStaff($request);
        $data = $request->validate([
            'title' => 'required|string|max:120',
            'body' => 'required|string|max:1000',
            'audience' => 'required|in:students,parents,both',
            'student_ids' => 'nullable|array',
            'student_ids.*' => 'integer|exists:students,id',
            'group' => 'nullable|string|max:32',
            'all' => 'nullable|boolean',
        ], ['title.required' => 'اكتب عنوان الإشعار.', 'body.required' => 'اكتب نص الإشعار.']);
        $user = $request->user();
        $all = (bool) ($data['all'] ?? false);

        // ADR 0002: a whole-school broadcast is admin-only; a teacher's
        // chosen group or students must be within the groups they teach.
        if ($all) {
            abort_unless($user->isAnyRole('admin'), 403, 'إرسال رسالة لكل المركز متاح للمشرف فقط.');
        }
        if (! $user->isAnyRole('admin') && filled($data['group'] ?? null)) {
            abort_if(
                in_array($data['group'], ClassGroup::namesClaimedByOthers($user), true),
                403,
                'لا تملك صلاحية هذه المجموعة.',
            );
        }
        if (! $user->isAnyRole('admin') && ! empty($data['student_ids'] ?? [])) {
            $excluded = ClassGroup::namesClaimedByOthers($user);
            $outOfReach = Student::query()->whereIn('id', $data['student_ids'])->whereIn('group', $excluded)->exists();
            abort_if($outOfReach, 403, 'لا تملك صلاحية بعض هؤلاء الطلاب.');
        }

        $studentIds = match (true) {
            $all => Student::query()->pluck('id')->all(),
            filled($data['group'] ?? null) => Student::query()->where('group', $data['group'])->pluck('id')->all(),
            default => $data['student_ids'] ?? [],
        };
        abort_if($studentIds === [], 422, 'اختر طالباً أو مجموعة لإرسال الإشعار.');

        $relationships = match ($data['audience']) {
            'students' => ['student'],
            'parents' => ['parent'],
            'both' => ['student', 'parent'],
        };
        $recipients = $notifier->notify($studentIds, $data['title'], $data['body'], 'message', $relationships);

        return ['students' => count($studentIds), 'recipients' => $recipients];
    }

    public function registerDevice(Request $request)
    {
        $data = $request->validate([
            'token' => 'required|string|max:512',
            'platform' => 'nullable|in:android,ios,web',
        ]);
        DeviceToken::query()->updateOrCreate(
            ['token' => $data['token']],
            ['user_id' => $request->user()->id, 'platform' => $data['platform'] ?? 'android'],
        );

        return ['registered' => true];
    }

    public function unregisterDevice(Request $request)
    {
        $token = $request->validate(['token' => 'required|string|max:512'])['token'];
        DeviceToken::query()->where('token', $token)->where('user_id', $request->user()->id)->delete();

        return ['registered' => false];
    }
}
