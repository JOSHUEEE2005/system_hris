<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\OnboardingRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RegistrationController extends Controller
{
    public function showRegistrationForm(): View
    {
        $registrationTypes = [
            'applicant' => 'Applicant',
            'employee' => 'Department User',
            'department:chamber-services' => 'Chamber Services',
            'department:dual-training' => 'Dual Training',
            'department:government-affairs' => 'Government Affairs',
            'department:trade-promotions' => 'Trade Promotions',
            'department:communications' => 'Communications',
            'department:internal-org' => 'Internal Org',
            'department:interns' => 'Interns',
        ];

        if (app()->environment(['local', 'testing'])) {
            $registrationTypes['super-admin'] = 'Super Admin (local testing only)';
        }

        return view('auth.register', [
            'registrationTypes' => $registrationTypes,
        ]);
    }

    public function register(Request $request): RedirectResponse
    {
        $departmentTypes = [
            'department:chamber-services' => 'Chamber Services',
            'department:dual-training' => 'Dual Training',
            'department:government-affairs' => 'Government Affairs',
            'department:trade-promotions' => 'Trade Promotions',
            'department:communications' => 'Communications',
            'department:internal-org' => 'Internal Org',
            'department:interns' => 'Interns',
        ];
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:150', 'unique:users,email'],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'confirmed', 'string', 'min:12', 'max:128'],
            'account_type' => ['required', 'string', Rule::in(array_merge(['applicant', 'employee', 'super-admin'], array_keys($departmentTypes)))],
        ], [
            'email.unique' => 'An account with this email already exists. Please use another email address.',
        ]);

        $email = strtolower(trim($validated['email']));
        if (User::where('email', $email)->exists()) {
            return back()->withErrors(['email' => 'An account with this email already exists.'])
                ->withInput($request->only('name', 'email', 'contact_number', 'account_type', 'department'));
        }

        $accountType = $validated['account_type'];
        $department = $departmentTypes[$accountType] ?? null;
        $roleSlug = $department ? 'employee' : $accountType;
        abort_if($accountType === 'super-admin' && ! app()->environment(['local', 'testing']), 403);
        $role = Role::where('slug', $roleSlug)->firstOrFail();

        $isAutoAccount = $accountType !== 'applicant';

        DB::transaction(function () use ($validated, $email, $role, $isAutoAccount, $department): void {
            $user = User::create([
                'name' => trim($validated['name']),
                'email' => $email,
                'password_hash' => Hash::make($validated['password']),
                'status' => $isAutoAccount ? 'Active' : 'Pending',
                'email_verified_at' => $isAutoAccount ? now() : null,
            ]);
            $user->roles()->sync([$role->id]);
            if ($isAutoAccount && $department) {
                [$firstName, $lastName] = $this->splitName($validated['name']);
                $departmentRecord = Department::firstOrCreate(['department_name' => $department]);
                $user->update(['department_id' => $departmentRecord->id]);
                $employee = Employee::create([
                    'employee_no' => 'REG-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'hire_date' => now()->toDateString(),
                    'department_id' => $departmentRecord->id,
                    'status' => 'Active',
                    'employment_type' => $department === 'Interns' ? 'Internship' : 'Employment',
                ]);
                $user->update(['employee_id' => $employee->id]);
            }
            if (! $isAutoAccount) {
                $onboardingData = [
                    'user_id' => $user->id,
                    'contact_number' => $validated['contact_number'] ?? null,
                    'status' => 'Pending',
                ];
                if ($department) {
                    $onboardingData['department_id'] = Department::firstOrCreate(['department_name' => $department])->id;
                }
                OnboardingRequest::create($onboardingData);
            }
            AuditLog::create([
                'event_key' => 'registration.submitted',
                'entity_type' => 'user',
                'entity_id' => (string) $user->id,
            ]);
        });

        return redirect()->route('login')->with(
            'status',
            $isAutoAccount
                ? 'Your account was created successfully. Stay tuned while we prepare your workspace.'
                : 'Registration submitted. HR must review and approve your account before you can sign in.',
        );
    }

    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2);

        return [$parts[0] ?? $name, $parts[1] ?? ''];
    }
}
