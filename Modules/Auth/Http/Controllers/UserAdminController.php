<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Modules\Auth\Models\User;
use Modules\Auth\Services\AuditTrail;

/**
 * The school admin's view of its accounts: approve teachers who registered, add staff, switch accounts
 * off and on, change a staff role, and reset a password. An admin never locks themselves out, and the
 * school always keeps one active admin.
 */
class UserAdminController extends Controller
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function index(Request $request)
    {
        $this->authorizeAdmin($request);
        $filters = $request->validate([
            'role' => ['nullable', Rule::in(['admin', 'teacher', 'parent', 'student'])],
            'state' => ['nullable', Rule::in(['active', 'pending', 'disabled'])],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $page = User::query()->with('studentAccount.student:id,name')
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->where('role', $role))
            ->when($filters['state'] ?? null, fn ($q, $state) => match ($state) {
                'pending' => $q->where('status', User::STATUS_PENDING),
                'disabled' => $q->where('status', User::STATUS_ACTIVE)->whereNotNull('disabled_at'),
                default => $q->where('status', User::STATUS_ACTIVE)->whereNull('disabled_at'),
            })
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('email', 'like', '%'.addcslashes($term, '%_\\').'%')))
            // Who is waiting for a decision comes first.
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")->latest('id')
            ->paginate(25);
        $page->getCollection()->transform(fn (User $user) => $this->present($user));

        return $page->toArray() + ['summary' => [
            'total' => User::count(),
            'pending' => User::where('status', User::STATUS_PENDING)->count(),
            'disabled' => User::where('status', User::STATUS_ACTIVE)->whereNotNull('disabled_at')->count(),
        ]];
    }

    /** Staff accounts made by the admin are active at once. */
    public function store(Request $request)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', Password::defaults()],
            'role' => ['required', Rule::in(['teacher', 'admin'])],
        ]);

        $user = (new User(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]))
            ->forceFill(['role' => $data['role'], 'status' => User::STATUS_ACTIVE]);
        $user->save();
        $this->audit->record($request->user(), 'user.created', $user, ['role' => $data['role']]);

        return response()->json($this->present($user), 201);
    }

    public function approve(Request $request, User $user)
    {
        $this->authorizeAdmin($request);
        abort_unless($user->isPending(), 422, 'هذا الحساب ليس بانتظار الموافقة.');

        $user->forceFill(['status' => User::STATUS_ACTIVE])->save();
        $this->audit->record($request->user(), 'account.approved', $user);

        return $this->present($user);
    }

    /** Turning a request down removes the account, since a pending one holds no data. */
    public function reject(Request $request, User $user)
    {
        $this->authorizeAdmin($request);
        abort_unless($user->isPending(), 422, 'يمكن رفض الحسابات التي بانتظار الموافقة فقط.');

        $this->audit->record($request->user(), 'account.rejected', $user);
        $user->delete();

        return response()->noContent();
    }

    public function disable(Request $request, User $user)
    {
        $this->authorizeAdmin($request);
        abort_if($user->is($request->user()), 422, 'لا يمكنك إيقاف حسابك بنفسك.');
        abort_if($user->isPending(), 422, 'وافق على الحساب أو ارفضه أولاً.');
        $this->keepOneAdmin($user);

        $user->forceFill(['disabled_at' => now()])->save();
        $user->tokens()->delete();
        $this->audit->record($request->user(), 'account.disabled', $user);

        return $this->present($user);
    }

    public function enable(Request $request, User $user)
    {
        $this->authorizeAdmin($request);
        $user->forceFill(['disabled_at' => null])->save();
        $this->audit->record($request->user(), 'account.enabled', $user);

        return $this->present($user);
    }

    /** Staff only: a parent's or student's role comes from the student they are linked to. */
    public function role(Request $request, User $user)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['role' => ['required', Rule::in(['teacher', 'admin'])]]);
        abort_unless($user->isAnyRole('admin', 'teacher'), 422, 'يمكن تغيير دور المعلمين والمشرفين فقط.');
        abort_if($user->is($request->user()), 422, 'لا يمكنك تغيير دور حسابك بنفسك.');
        abort_if($user->isPending(), 422, 'وافق على الحساب أولاً.');
        if ($data['role'] !== 'admin') {
            $this->keepOneAdmin($user);
        }

        $from = $user->role;
        $user->forceFill(['role' => $data['role']])->save();
        $user->tokens()->delete();
        $this->audit->record($request->user(), 'role.changed', $user, ['from' => $from, 'to' => $data['role']]);

        return $this->present($user);
    }

    /** Sets a new password (the admin's, or a generated one shown once) and signs the account out everywhere. */
    public function resetPassword(Request $request, User $user)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['password' => ['nullable', Password::defaults()]]);
        abort_if($user->isPending(), 422, 'وافق على الحساب أولاً.');

        $generated = empty($data['password']);
        $password = $data['password'] ?? Str::password(14, symbols: false);
        $user->forceFill(['password' => $password])->save();
        $user->tokens()->delete();
        $this->audit->record($request->user(), 'password.reset_by_admin', $user);

        return $generated ? ['password' => $password] : ['success' => true];
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()->isAnyRole('admin'), 403, 'هذه الصفحة للمشرف فقط.');
    }

    /** The school must always have an admin who can sign in. */
    private function keepOneAdmin(User $target): void
    {
        if (! $target->isAnyRole('admin') || ! $target->isUsable()) {
            return;
        }
        $another = User::where('role', 'admin')->where('status', User::STATUS_ACTIVE)->whereNull('disabled_at')->whereKeyNot($target->id)->exists();
        abort_unless($another, 422, 'لا يمكن إيقاف آخر مشرف نشط في المركز.');
    }

    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'state' => $user->accountState(),
            'created_at' => $user->created_at?->toIso8601String(),
            'student' => $user->studentAccount?->student?->name,
        ];
    }
}
