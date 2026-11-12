<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\OnboardingRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function dashboard(): View
    {
        $actor = auth()->user();
        $can = fn (string $permission): bool => $actor->hasPermission($permission);

        return view('admin.dashboard', [
            'pending' => $can('onboarding.review')
                ? OnboardingRequest::with('user')->where('status', 'Pending')->latest()->get()
                : collect(),
            'users' => $can('users.manage') ? User::with(['roles', 'employee'])->latest()->get() : collect(),
            'roles' => $can('roles.manage') ? Role::with('permissions')->withCount('users')->orderBy('name')->get() : collect(),
            'permissions' => $can('roles.manage') ? Permission::orderBy('permission_key')->get() : collect(),
            'departments' => $can('departments.manage') || $can('employees.manage')
                ? Department::withCount('employees')->orderBy('department_name')->get()
                : collect(),
            'employees' => $can('employees.manage') ? Employee::with('department')->latest()->get() : collect(),
            'canManageUsers' => $can('users.manage'),
            'canManageRoles' => $can('roles.manage'),
            'canManageDepartments' => $can('departments.manage'),
            'canManageEmployees' => $can('employees.manage'),
            'canReviewOnboarding' => $can('onboarding.review'),
            'auditLogs' => $can('audit.view') ? AuditLog::with('actor')->latest()->limit(50)->get() : collect(),
            'canViewAudit' => $can('audit.view'),
        ]);
    }

    public function approveOnboarding(Request $request, OnboardingRequest $onboarding): RedirectResponse
    {
        $email = strtolower((string) $onboarding->user()->value('email'));
        $hasExistingEmployee = Employee::whereRaw('LOWER(email) = ?', [$email])->exists();
        $data = $request->validate([
            'confirmed_identity' => ['required', 'accepted'],
            'employee_no' => [
                Rule::requiredIf(! $hasExistingEmployee),
                'nullable',
                'string',
                'max:20',
                'unique:employees,employee_no',
            ],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'position' => ['nullable', 'string', 'max:100'],
            'hire_date' => ['nullable', 'date'],
            'salary' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
        ]);

        DB::transaction(function () use ($data, $onboarding, $request): void {
            $onboarding = OnboardingRequest::whereKey($onboarding->id)->lockForUpdate()->firstOrFail();
            abort_unless($onboarding->status === 'Pending', 409, 'This request has already been reviewed.');

            $user = User::whereKey($onboarding->user_id)->lockForUpdate()->firstOrFail();
            $employee = Employee::whereRaw('LOWER(email) = ?', [strtolower($user->email)])
                ->lockForUpdate()->first();
            abort_unless(
                ! $employee || $employee->status === 'Active',
                422,
                'The employee record is inactive. Reactivate it before approving this account.',
            );
            if (! $employee) {
                [$firstName, $lastName] = $this->splitName($user->name);
                $employee = Employee::create([
                    'employee_no' => $data['employee_no'],
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $user->email,
                    'contact_number' => $onboarding->contact_number,
                    'hire_date' => $data['hire_date'] ?? now()->toDateString(),
                    'department_id' => $data['department_id'] ?? null,
                    'position' => $data['position'] ?? null,
                    'salary' => $data['salary'] ?? null,
                    'status' => 'Active',
                ]);
            }
            $user->update([
                'employee_id' => $employee->id,
                'status' => 'Active',
                'email_verified_at' => now(),
            ]);
            $onboarding->update([
                'status' => 'Approved',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);
            AuditLog::create([
                'actor_user_id' => $request->user()->id,
                'event_key' => 'onboarding.approved',
                'entity_type' => 'user',
                'entity_id' => (string) $user->id,
            ]);
        });

        return back()->with('status', 'Registration approved and employee record created.');
    }

    public function rejectOnboarding(Request $request, OnboardingRequest $onboarding): RedirectResponse
    {
        DB::transaction(function () use ($onboarding, $request): void {
            $onboarding = OnboardingRequest::whereKey($onboarding->id)->lockForUpdate()->firstOrFail();
            abort_unless($onboarding->status === 'Pending', 409, 'This request has already been reviewed.');
            $onboarding->update([
                'status' => 'Rejected',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);
            $onboarding->user()->update(['status' => 'Disabled']);
            AuditLog::create([
                'actor_user_id' => $request->user()->id,
                'event_key' => 'onboarding.rejected',
                'entity_type' => 'user',
                'entity_id' => (string) $onboarding->user_id,
            ]);
        });

        return back()->with('status', 'Registration rejected.');
    }

    public function storeUser(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id', 'unique:users,employee_id'],
            'status' => ['required', Rule::in(['Pending', 'Active', 'Disabled'])],
        ]);
        if (! empty($data['employee_id'])) {
            $employee = Employee::findOrFail($data['employee_id']);
            if (strtolower((string) $employee->email) !== strtolower(trim($data['email']))) {
                return back()->withErrors(['employee_id' => 'The selected employee email must match the user email.']);
            }
        }

        $user = DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => strtolower(trim($data['email'])),
                'employee_id' => $data['employee_id'] ?? null,
                'status' => $data['status'],
                'password_hash' => Hash::make($data['password']),
                'email_verified_at' => now(),
            ]);
            $user->roles()->sync([$data['role_id']]);

            return $user;
        });
        $this->audit($request, 'user.created', 'user', $user->id);

        return back()->with('status', 'User account created.');
    }

    public function updateUser(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'status' => ['required', Rule::in(['Pending', 'Active', 'Disabled'])],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id', Rule::unique('users', 'employee_id')->ignore($user->id)],
        ]);
        $newRole = Role::findOrFail($data['role_id']);
        if (! empty($data['employee_id'])) {
            $employee = Employee::findOrFail($data['employee_id']);
            if (strtolower((string) $employee->email) !== strtolower($user->email)) {
                return back()->withErrors(['employee_id' => 'The selected employee email must match the user email.']);
            }
        }

        if ($user->status === 'Active' && $user->roles()->where('slug', 'super-admin')->exists()
            && ($data['status'] !== 'Active' || $newRole->slug !== 'super-admin')
            && $this->activeSuperAdminCount() < 2) {
            return back()->withErrors(['user' => 'You cannot disable or demote the last active Super Admin.']);
        }

        $user->update([
            'status' => $data['status'],
            'employee_id' => $data['employee_id'] ?? null,
        ]);
        $user->roles()->sync([$newRole->id]);
        $this->audit($request, 'user.updated', 'user', $user->id);

        return back()->with('status', 'User account updated.');
    }

    public function destroyUser(Request $request, User $user): RedirectResponse
    {
        if ($user->status === 'Active' && $user->roles()->where('slug', 'super-admin')->exists()
            && $this->activeSuperAdminCount() < 2) {
            return back()->withErrors(['user' => 'You cannot delete the last active Super Admin.']);
        }

        $this->audit($request, 'user.deleted', 'user', $user->id);
        $user->update(['employee_id' => null]);
        $user->delete();

        return back()->with('status', 'User account deleted.');
    }

    public function storeDepartment(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'department_name' => ['required', 'string', 'max:100', 'unique:departments,department_name'],
        ]);
        $department = Department::create($data);
        $this->audit($request, 'department.created', 'department', $department->id);

        return back()->with('status', 'Department created.');
    }

    public function updateDepartment(Request $request, Department $department): RedirectResponse
    {
        $data = $request->validate([
            'department_name' => ['required', 'string', 'max:100', Rule::unique('departments')->ignore($department->id)],
        ]);
        $department->update($data);
        $this->audit($request, 'department.updated', 'department', $department->id);

        return back()->with('status', 'Department updated.');
    }

    public function destroyDepartment(Request $request, Department $department): RedirectResponse
    {
        $this->audit($request, 'department.deleted', 'department', $department->id);
        $department->delete();

        return back()->with('status', 'Department deleted; employees remain with no department.');
    }

    public function storeEmployee(Request $request): RedirectResponse
    {
        $data = $request->validate($this->employeeRules());
        $employee = Employee::create($data);
        $this->audit($request, 'employee.created', 'employee', $employee->id);

        return back()->with('status', 'Employee created.');
    }

    public function updateEmployee(Request $request, Employee $employee): RedirectResponse
    {
        $data = $request->validate($this->employeeRules($employee));
        $employee->update($data);
        $this->audit($request, 'employee.updated', 'employee', $employee->id);

        return back()->with('status', 'Employee updated.');
    }

    public function destroyEmployee(Request $request, Employee $employee): RedirectResponse
    {
        if (User::where('employee_id', $employee->id)->exists()) {
            return back()->withErrors(['employee' => 'Unlink or delete the associated user account before deleting this employee.']);
        }
        if (DB::table('attendance')->where('employee_id', $employee->id)->exists()
            || DB::table('leave_requests')->where('employee_id', $employee->id)->exists()) {
            return back()->withErrors(['employee' => 'This employee has attendance or leave history and cannot be deleted. Mark the employee inactive instead.']);
        }

        $this->audit($request, 'employee.deleted', 'employee', $employee->id);
        $employee->delete();

        return back()->with('status', 'Employee deleted.');
    }

    public function storeRole(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:roles,name'],
            'slug' => ['required', 'alpha_dash', 'max:100', 'unique:roles,slug'],
            'description' => ['nullable', 'string', 'max:255'],
            'permission_ids' => ['array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);
        $role = Role::create($data);
        $role->permissions()->sync($data['permission_ids'] ?? []);
        $this->audit($request, 'role.created', 'role', $role->id);

        return back()->with('status', 'Role created.');
    }

    public function updateRole(Request $request, Role $role): RedirectResponse
    {
        abort_if($role->slug === 'super-admin', 422, 'The built-in Super Admin role cannot be renamed or changed here.');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('roles')->ignore($role->id)],
            'slug' => ['required', 'alpha_dash', 'max:100', Rule::unique('roles')->ignore($role->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'permission_ids' => ['array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);
        $role->update($data);
        $role->permissions()->sync($data['permission_ids'] ?? []);
        $this->audit($request, 'role.updated', 'role', $role->id);

        return back()->with('status', 'Role updated.');
    }

    public function destroyRole(Request $request, Role $role): RedirectResponse
    {
        if (in_array($role->slug, ['super-admin', 'employee', 'hr-manager'], true) || $role->users()->exists()) {
            return back()->withErrors(['role' => 'Built-in roles and roles assigned to users cannot be deleted.']);
        }

        $this->audit($request, 'role.deleted', 'role', $role->id);
        $role->delete();

        return back()->with('status', 'Role deleted.');
    }

    private function employeeRules(?Employee $employee = null): array
    {
        return [
            'employee_no' => ['required', 'string', 'max:20', Rule::unique('employees')->ignore($employee?->id)],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:150', Rule::unique('employees')->ignore($employee?->id)],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:10000'],
            'gender' => ['nullable', Rule::in(['Male', 'Female'])],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'position' => ['nullable', 'string', 'max:100'],
            'hire_date' => ['nullable', 'date'],
            'salary' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'status' => ['required', Rule::in(['Active', 'Inactive'])],
        ];
    }

    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2) ?: [$name];

        return [$parts[0], $parts[1] ?? ''];
    }

    private function activeSuperAdminCount(): int
    {
        return User::where('status', 'Active')
            ->whereHas('roles', fn ($query) => $query->where('slug', 'super-admin'))
            ->count();
    }

    private function audit(Request $request, string $event, string $type, int $id): void
    {
        AuditLog::create([
            'actor_user_id' => $request->user()->id,
            'event_key' => $event,
            'entity_type' => $type,
            'entity_id' => (string) $id,
        ]);
    }
}
