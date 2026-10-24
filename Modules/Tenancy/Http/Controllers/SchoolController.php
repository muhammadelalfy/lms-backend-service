<?php

namespace Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Auth\Services\DemoAccounts;
use Modules\Students\Models\Student;
use Modules\Tenancy\Models\School;
use Modules\Tenancy\Services\SchoolProvisioner;

/**
 * The platform operator's view of the schools: open one, change its plan,
 * suspend it, give it another address, close it. Reached only on the central
 * domain with the operator's token.
 */
class SchoolController extends Controller
{
    /** `?usage=1` adds each school's student count (it opens every school's database, so it is opt-in). */
    public function index(Request $request)
    {
        $usage = $request->boolean('usage');

        return ['data' => School::query()->with('domains')->orderBy('name')->get()
            ->map(fn (School $school) => $this->present($school) + ($usage ? ['students_count' => $this->studentsOf($school)] : []))
            ->values()];
    }

    public function show(School $school)
    {
        return $this->present($school);
    }

    public function store(Request $request, SchoolProvisioner $provisioner)
    {
        $data = $request->validate([
            'slug' => 'required|string|max:40',
            'name' => 'required|string|max:120',
            'plan' => ['sometimes', Rule::in(array_keys(config('schools.plans')))],
            'max_students' => 'sometimes|nullable|integer|min:1',
            'demo' => 'sometimes|boolean',
            'admin' => 'required|array',
            'admin.name' => 'required|string|max:120',
            'admin.email' => 'required|email|max:190',
            'admin.password' => 'required|string|min:8|max:100',
        ]);

        return response()->json($this->present($provisioner->open($data)), 201);
    }

    public function update(Request $request, School $school)
    {
        $settings = array_keys(config('schools.settings'));
        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'plan' => ['sometimes', Rule::in(array_keys(config('schools.plans')))],
            'status' => ['sometimes', Rule::in([School::ACTIVE, School::SUSPENDED])],
            'max_students' => 'sometimes|nullable|integer|min:1',
            'trial_ends_at' => 'sometimes|nullable|date',
            'demo_logins' => 'sometimes|boolean',
            'settings' => 'sometimes|array',
            'settings.*' => 'nullable|string|max:500',
        ]);
        foreach (array_keys($data['settings'] ?? []) as $key) {
            abort_unless(in_array($key, $settings, true), 422, "إعداد غير معروف: {$key}");
        }

        $school->fill(collect($data)->except('settings')->all());
        foreach ($data['settings'] ?? [] as $key => $value) {
            $school->setAttribute($key, $value);
        }
        $school->save();
        // Switching "try it" sign-ins on makes sure the demo accounts exist, in the school's own database.
        if ($data['demo_logins'] ?? false) {
            $school->run(fn () => app(DemoAccounts::class)->ensure());
        }

        return $this->present($school->fresh('domains'));
    }

    /** Another address for the school, e.g. its own website. */
    public function addDomain(Request $request, School $school)
    {
        $domain = strtolower($request->validate(['domain' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i']])['domain']);
        $school->domains()->create(['domain' => $domain]);

        return response()->json($this->present($school->fresh('domains')), 201);
    }

    /** Closes the school and deletes its database; the school's id must be repeated to confirm. */
    public function destroy(Request $request, School $school)
    {
        abort_unless($request->query('confirm') === $school->id, 422, 'أرسل كود المركز في confirm للتأكيد.');
        $school->delete();

        return response()->noContent();
    }

    /** The school's student count, or null when its database cannot be read (one broken school must not hide the rest). */
    private function studentsOf(School $school): ?int
    {
        try {
            return (int) $school->run(fn () => Student::query()->count());
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    private function present(School $school): array
    {
        return [
            'id' => $school->id,
            'name' => $school->name,
            'plan' => $school->plan,
            'status' => $school->status,
            'active' => $school->isActive(),
            'trial_ends_at' => $school->trial_ends_at?->toISOString(),
            'max_students' => $school->max_students,
            'student_limit' => $school->studentLimit(),
            'demo_logins' => (bool) $school->demo_logins,
            'domains' => $school->domains->pluck('domain')->values(),
            // Which settings are set, never their values.
            'settings' => array_keys($school->settings()),
        ];
    }
}
