<?php

namespace Modules\Students\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Students\Models\Student;

class StudentController extends Controller
{
    public function index(Request $request)
    {
        $query = Student::query()
            ->when($request->string('grade')->isNotEmpty(), fn ($q) => $q->where('grade', $request->string('grade')))
            ->when($request->string('group')->isNotEmpty(), fn ($q) => $q->where('group', $request->string('group')))
            ->when($request->string('search')->isNotEmpty(), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'));

        if ($request->user()->isAnyRole('student', 'parent')) {
            $account = $request->user()->studentAccount;
            abort_unless($account, 403);
            $query->whereKey($account->student_id);
        }

        return $query->withCount(['assignments', 'attendanceRecords', 'examResults', 'payments'])->latest()->paginate(25);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->isAnyRole('admin', 'teacher'), 403);
        $data = $this->validatedData($request);

        // The school's plan says how many students it may have.
        $limit = tenant()?->studentLimit();
        $used = $limit === null ? 0 : Student::query()->count();
        if ($limit !== null && $used >= $limit) {
            return response()->json([
                'message' => "وصل مركزك إلى الحد الأقصى للطلاب في باقته ({$limit}). رقِّ الباقة لإضافة المزيد.",
                'code' => 'plan_limit',
                'cap' => 'students',
                'limit' => $limit,
                'used' => $used,
            ], 402);
        }

        // A staff phone makes the card's secret itself so the card works before
        // the student reaches the server; the server only checks its shape.
        $student = new Student($data);
        if ($token = $this->cardToken($request)) {
            $student->qr_token = $token;
        }
        $student->save();

        return response()->json($student, 201);
    }

    public function show(Request $request, Student $student)
    {
        $this->authorizeStudentAccess($request, $student);

        return $student->load(['assignments.worksheet', 'attendanceRecords', 'examResults', 'payments']);
    }

    public function update(Request $request, Student $student)
    {
        abort_unless($request->user()->isAnyRole('admin', 'teacher'), 403);
        $student->update($this->validatedData($request, true));

        return $student->fresh();
    }

    public function destroy(Request $request, Student $student)
    {
        abort_unless($request->user()->isAnyRole('admin'), 403);
        $student->delete();

        return response()->noContent();
    }

    public function qr(Request $request, Student $student)
    {
        $this->authorizeStudentAccess($request, $student);
        abort_unless($request->user()->isAnyRole('admin', 'teacher', 'parent', 'student'), 403);

        return ['student_id' => $student->id, 'payload' => $student->ensureQrToken(), 'generated_at' => now()->toISOString()];
    }

    /**
     * Who each QR card belongs to, for staff phones. A phone keeps it so a
     * desk with no connection can still greet the student by name; without
     * it an offline scan can only say it was saved. Staff can already read
     * any student's card one at a time, so this hands out nothing new, only
     * in one request.
     */
    public function qrDirectory(Request $request)
    {
        abort_unless($request->user()->isAnyRole('admin', 'teacher'), 403);

        $cards = [];
        Student::query()->orderBy('id')->each(function (Student $student) use (&$cards): void {
            $cards[] = [
                'id' => $student->id,
                'name' => $student->name,
                'grade' => $student->grade,
                'group' => $student->group,
                'payload' => $student->ensureQrToken(),
            ];
        });

        return ['generated_at' => now()->toISOString(), 'cards' => $cards];
    }

    public function regenerateQr(Request $request, Student $student)
    {
        abort_unless($request->user()->isAnyRole('admin', 'teacher'), 403);

        // A phone may bring the new secret it already printed on the card.
        $token = $this->cardToken($request, 'payload', $student->id);

        return ['student_id' => $student->id, 'payload' => $student->regenerateQrToken($token), 'generated_at' => now()->toISOString()];
    }

    private function authorizeStudentAccess(Request $request, Student $student): void
    {
        if ($request->user()->isAnyRole('student', 'parent')) {
            $account = $request->user()->studentAccount;
            abort_unless($account && $account->student_id === $student->id, 403);
        }
    }

    /**
     * The card secret a phone supplied, or null to let the server make one.
     * It must look like the server's own (64 letters and digits) and not be
     * another student's, or the request is refused.
     */
    private function cardToken(Request $request, string $field = 'qr_token', ?int $except = null): ?string
    {
        $data = $request->validate([
            $field => [
                'nullable',
                'string',
                'size:64',
                'regex:/^[A-Za-z0-9]{64}$/',
                Rule::unique('students', 'qr_token')->ignore($except),
            ],
        ]);

        return $data[$field] ?? null;
    }

    private function validatedData(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:160'],
            'group' => [$required, 'string', 'max:32'],
            'grade' => [$required, 'string', 'max:80'],
            'phone' => [$required, 'string', 'max:32'],
            'parent_phone' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', 'in:excellent,average,weak'],
        ]);
    }
}
