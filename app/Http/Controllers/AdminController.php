<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ContractRecipient;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Intern;
use App\Models\OnboardingRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Illuminate\Http\JsonResponse;

class AdminController extends Controller
{
    private const HR_DEPARTMENTS = [
        'Chamber Services',
        'Dual Training',
        'Government Affairs',
        'Trade Promotions',
        'Communications',
        'Internal Org',
        'Internal Organization',
    ];

    public function dashboard(): View
    {
        $actor = auth()->user();
        $can = fn (string $permission): bool => $actor->hasPermission($permission);
        $canManageUsers = $can('users.manage');
        $canManageEmployees = $can('employees.manage');
        $canManageDepartments = $can('departments.manage') || $canManageEmployees;
        $canReviewOnboarding = $can('onboarding.review');
        $canViewAudit = $can('audit.view');

        return view('admin.dashboard', [
            'pendingCount' => $canReviewOnboarding
                ? OnboardingRequest::where('status', 'Pending')->count()
                : 0,
            'userCount' => $canManageUsers ? User::count() : 0,
            'activeUserCount' => $canManageUsers ? User::where('status', 'Active')->count() : 0,
            'employeeCount' => $canManageEmployees
                ? Employee::where('employment_type', '!=', 'Internship')->count()
                : 0,
            'activeEmployeeCount' => $canManageEmployees ? Employee::where('employment_type', '!=', 'Internship')
                ->where('status', 'Active')->count() : 0,
            'inactiveEmployeeCount' => $canManageEmployees
                ? Employee::where('employment_type', '!=', 'Internship')->where('status', 'Inactive')->count()
                : 0,
            'internCount' => $canManageEmployees
                ? Intern::count()
                : 0,
            'departmentCount' => $canManageDepartments ? Department::count() : 0,
            'auditLogs' => $canViewAudit
                ? AuditLog::with('actor:id,name')
                    ->latest()
                    ->limit(4)
                    ->get(['id', 'actor_user_id', 'event_key', 'created_at'])
                : collect(),
            'canViewAudit' => $canViewAudit,
        ]);
    }

    public function usersPage(): View
    {
        return view('admin.users', [
            'users' => User::with(['roles', 'employee.department', 'intern.department', 'department'])->latest()->get(),
            'roles' => Role::with('permissions')->withCount('users')->orderBy('name')->get(),
            'employees' => Employee::with('department')->latest()->get(),
            'departments' => Department::whereIn('department_name', self::HR_DEPARTMENTS)
                ->withCount(['employees', 'interns'])
                ->orderBy('department_name')
                ->get(),
            'canManageUsers' => auth()->user()->hasPermission('users.manage'),
            'canManageEmployees' => auth()->user()->hasPermission('employees.manage'),
        ]);
    }

    public function calendarPage(Request $request): View
    {
        $validator = Validator::make($request->query(), [
            'view' => ['sometimes', Rule::in(['month', 'year'])],
            'year' => ['sometimes', 'integer', 'between:1900,2100'],
            'month' => ['sometimes', 'integer', 'between:1,12'],
        ]);
        abort_if($validator->fails(), 404);
        $data = $validator->validated();
        $year = (int) ($data['year'] ?? now()->year);
        $month = (int) ($data['month'] ?? now()->month);
        $selectedMonth = now()->startOfYear()->setYear($year)->setMonth($month);

        return view('admin.calendar', [
            'viewMode' => $data['view'] ?? 'month',
            'selectedMonth' => $selectedMonth,
            'selectedYear' => $year,
            'minimumCalendarYear' => 1900,
            'maximumCalendarYear' => 2100,
        ]);
    }

    public function leavesPage(): View
    {
        return view('admin.placeholder', ['pageTitle' => 'Leaves', 'pageDescription' => 'Review leave requests and balances.']);
    }

    public function onboardingPage(): View
    {
        return view('admin.onboarding', [
            'pending' => OnboardingRequest::with('user')->where('status', 'Pending')->latest()->get(),
            'departments' => Department::orderBy('department_name')->get(),
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
                    'department_id' => $data['department_id'] ?? $onboarding->department_id,
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
            $user->ensurePersonalContractFolder();
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
            'email' => [
                'required',
                'email',
                'max:150',
                'unique:users,email',
                $isEmployee ? Rule::unique('employees', 'email') : Rule::unique('interns', 'email'),
            ],
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
            $user->ensurePersonalContractFolder();

            return $user;
        });
        $this->audit($request, 'user.created', 'user', $user->id);

        return back()->with('status', 'User account created.');
    }

    public function storeUserWithEmployee(Request $request): RedirectResponse
    {
        $profileType = $request->input('profile_type', 'employee');
        $isEmployee = $profileType === 'employee';
        $isIntern = $profileType === 'intern';
        $departmentRule = Rule::exists('departments', 'id')->whereIn('department_name', self::HR_DEPARTMENTS);
        $data = $request->validate([
            'profile_type' => ['required', Rule::in(['employee', 'intern'])],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'department_id' => ['required', 'integer', $departmentRule],
            'employee_no' => [$isEmployee ? 'required' : 'nullable', 'string', 'max:20', 'unique:employees,employee_no'],
            'first_name' => [$isEmployee ? 'required' : 'nullable', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => [$isEmployee ? 'required' : 'nullable', 'string', 'max:100'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'gender' => ['nullable', Rule::in(['Male', 'Female'])],
            'position' => ['required', 'string', 'max:100'],
            'immediate_supervisor' => ['nullable', 'string', 'max:200'],
            'hire_date' => [$isEmployee ? 'required' : 'nullable', 'date'],
            'period' => ['nullable', 'string', 'max:100'],
            'regularized_date' => ['nullable', 'date', 'after_or_equal:hire_date'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'employment_status' => [
                $isEmployee ? 'required' : 'nullable',
                Rule::in(['Active', 'Resigned', 'Active OIO', 'Active project based', 'Active agency']),
            ],
            'last_date' => ['nullable', 'date', 'after_or_equal:hire_date'],
            'sss_number' => ['nullable', 'string', 'max:50'],
            'tin_number' => ['nullable', 'string', 'max:50'],
            'hdmf_number' => ['nullable', 'string', 'max:50'],
            'philhealth_number' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:10000'],
            'contact_number' => ['nullable', 'string', 'max:30'],
            'place_of_birth' => ['nullable', 'string', 'max:255'],
            'emergency_contact_person' => ['nullable', 'string', 'max:200'],
            'emergency_contact_number' => ['nullable', 'string', 'max:30'],
            'personal_email' => ['nullable', 'email', 'max:150'],
            'hmo_status' => ['nullable', Rule::in(['Yes', 'No', 'N/A', 'Upon regularization'])],
            'ecu' => ['nullable', 'string', 'max:100'],
            'mobile_subscription' => ['nullable', Rule::in(['Yes 1499 subs', 'Yes 1799 subs', 'Yes 799 subs'])],
            'gpcci_mobile_number' => ['nullable', 'string', 'max:30'],
            'sick_leave_days' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'birthday_leave_days' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'vacation_leave_days' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'business_card' => ['nullable', 'boolean'],
            'intern_no' => [$isIntern ? 'required' : 'nullable', 'string', 'max:30', 'unique:interns,intern_no'],
            'intern_name' => [$isIntern ? 'required' : 'nullable', 'string', 'max:255'],
            'designation' => [$isIntern ? 'required' : 'nullable', 'string', 'max:150'],
            'internship_start_date' => [$isIntern ? 'required' : 'nullable', 'date'],
            'internship_end_date' => ['nullable', 'date', 'after_or_equal:internship_start_date'],
            'internship_status' => [
                $isIntern ? 'required' : 'nullable',
                Rule::in(['Finished', 'Pre terminated', 'Active', 'Cancelled']),
            ],
            'intern_address' => ['nullable', 'string', 'max:10000'],
            'intern_contact_number' => ['nullable', 'string', 'max:30'],
            'university' => ['nullable', 'string', 'max:200'],
            'course' => ['nullable', 'string', 'max:200'],
            'degree_expected' => ['nullable', 'string', 'max:200'],
        ]);

        DB::transaction(function () use ($data, $request, $isEmployee): void {
            $profile = $isEmployee
                ? Employee::create([
                    'employee_no' => $data['employee_no'],
                    'first_name' => trim($data['first_name']),
                    'middle_name' => $data['middle_name'] ?? null,
                    'last_name' => trim($data['last_name']),
                    'full_name' => trim(implode(' ', array_filter([
                        trim($data['first_name']),
                        isset($data['middle_name']) ? trim($data['middle_name']) : null,
                        trim($data['last_name']),
                    ]))),
                    'nationality' => $data['nationality'] ?? null,
                    'email' => strtolower(trim($data['email'])),
                    'department_id' => $data['department_id'],
                    'position' => $data['position'] ?? null,
                    'hire_date' => $data['hire_date'],
                    'period' => $data['period'] ?? null,
                    'regularized_date' => $data['regularized_date'] ?? null,
                    'birth_date' => $data['birth_date'] ?? null,
                    'gender' => $data['gender'] ?? null,
                    'immediate_supervisor' => $data['immediate_supervisor'] ?? null,
                    'employment_status' => $data['employment_status'],
                    'last_date' => $data['last_date'] ?? null,
                    'sss_number' => $data['sss_number'] ?? null,
                    'tin_number' => $data['tin_number'] ?? null,
                    'hdmf_number' => $data['hdmf_number'] ?? null,
                    'philhealth_number' => $data['philhealth_number'] ?? null,
                    'address' => $data['address'] ?? null,
                    'contact_number' => $data['contact_number'] ?? null,
                    'place_of_birth' => $data['place_of_birth'] ?? null,
                    'emergency_contact_person' => $data['emergency_contact_person'] ?? null,
                    'emergency_contact_number' => $data['emergency_contact_number'] ?? null,
                    'personal_email' => $data['personal_email'] ?? null,
                    'hmo_status' => $data['hmo_status'] ?? null,
                    'ecu' => $data['ecu'] ?? null,
                    'mobile_subscription' => $data['mobile_subscription'] ?? null,
                    'gpcci_mobile_number' => $data['gpcci_mobile_number'] ?? null,
                    'sick_leave_days' => $data['sick_leave_days'] ?? null,
                    'birthday_leave_days' => $data['birthday_leave_days'] ?? null,
                    'vacation_leave_days' => $data['vacation_leave_days'] ?? null,
                    'business_card' => $request->boolean('business_card'),
                    'employment_type' => 'Employment',
                    'status' => $data['employment_status'] === 'Resigned' ? 'Inactive' : 'Active',
                ])
                : Intern::create([
                    'intern_no' => $data['intern_no'],
                    'intern_name' => trim($data['intern_name']),
                    'designation' => $data['designation'],
                    'email' => strtolower(trim($data['email'])),
                    'birth_date' => $data['birth_date'] ?? null,
                    'internship_start_date' => $data['internship_start_date'],
                    'period' => $data['period'] ?? null,
                    'internship_end_date' => $data['internship_end_date'] ?? null,
                    'gender' => $data['gender'] ?? null,
                    'department_id' => $data['department_id'],
                    'position' => $data['position'] ?? null,
                    'immediate_supervisor' => $data['immediate_supervisor'] ?? null,
                    'address' => $data['intern_address'] ?? null,
                    'contact_number' => $data['intern_contact_number'] ?? null,
                    'personal_email' => $data['personal_email'] ?? null,
                    'university' => $data['university'] ?? null,
                    'course' => $data['course'] ?? null,
                    'degree_expected' => $data['degree_expected'] ?? null,
                    'internship_status' => $data['internship_status'],
                ]);
            $user = User::create([
                'name' => $isEmployee ? $profile->full_name : $profile->intern_name,
                'email' => strtolower(trim($data['email'])),
                'password_hash' => Hash::make($data['password']),
                'employee_id' => $isEmployee ? $profile->id : null,
                'department_id' => $profile->department_id,
                'status' => 'Active',
                'email_verified_at' => now(),
            ]);
            if (! $isEmployee) {
                $profile->update(['user_id' => $user->id]);
            }
            $user->roles()->sync([$data['role_id']]);
            $user->ensurePersonalContractFolder();

            $this->audit($request, $isEmployee ? 'employee.created' : 'intern.created', $isEmployee ? 'employee' : 'intern', $profile->id);
            $this->audit($request, 'user.created', 'user', $user->id);
        });

        return back()->with('status', $isEmployee
            ? 'Employee profile and user account created.'
            : 'Intern profile and user account created.');
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

    public function updateUserProfile(Request $request, User $user): RedirectResponse
    {
        $intern = $user->intern;
        $employee = $intern ? null : $user->employee;
        abort_unless($intern || $employee, 404);

        $isIntern = $intern !== null;
        $employeeNoRule = Rule::unique('employees', 'employee_no');
        $internNoRule = Rule::unique('interns', 'intern_no');
        if ($employee) {
            $employeeNoRule->ignore($employee->id);
        }
        if ($intern) {
            $internNoRule->ignore($intern->id);
        }

        $regularizedDateRules = ['nullable', 'date'];
        if ($employee && (
            $request->input('hire_date') !== $employee->hire_date?->format('Y-m-d')
            || $request->input('regularized_date') !== $employee->regularized_date?->format('Y-m-d')
        )) {
            $regularizedDateRules[] = 'after_or_equal:hire_date';
        }

        $lastDateRules = ['nullable', 'date'];
        if ($employee && (
            $request->input('hire_date') !== $employee->hire_date?->format('Y-m-d')
            || $request->input('last_date') !== $employee->last_date?->format('Y-m-d')
        )) {
            $lastDateRules[] = 'after_or_equal:hire_date';
        }

        $internshipEndDateRules = ['nullable', 'date'];
        if ($intern && (
            $request->input('internship_start_date') !== $intern->internship_start_date?->format('Y-m-d')
            || $request->input('internship_end_date') !== $intern->internship_end_date?->format('Y-m-d')
        )) {
            $internshipEndDateRules[] = 'after_or_equal:internship_start_date';
        }

        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'status' => ['required', Rule::in(['Pending', 'Active', 'Disabled'])],
            'department_id' => [
                'required',
                'integer',
                Rule::exists('departments', 'id')->whereIn('department_name', self::HR_DEPARTMENTS),
            ],
            'gender' => ['nullable', Rule::in(['Male', 'Female'])],
            'position' => ['required', 'string', 'max:'.($isIntern ? 150 : 100)],
            'immediate_supervisor' => ['nullable', 'string', 'max:200'],
            'personal_email' => ['nullable', 'email', 'max:150'],
            'period' => ['nullable', 'string', 'max:100'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'employee_no' => [$isIntern ? 'nullable' : 'required', 'string', 'max:20', $employeeNoRule],
            'first_name' => [$isIntern ? 'nullable' : 'required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => [$isIntern ? 'nullable' : 'required', 'string', 'max:100'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'hire_date' => [$isIntern ? 'nullable' : 'required', 'date'],
            'regularized_date' => $regularizedDateRules,
            'employment_status' => [
                $isIntern ? 'nullable' : 'required',
                Rule::in(['Active', 'Resigned', 'Active OIO', 'Active project based', 'Active agency']),
            ],
            'last_date' => $lastDateRules,
            'sss_number' => ['nullable', 'string', 'max:50'],
            'tin_number' => ['nullable', 'string', 'max:50'],
            'hdmf_number' => ['nullable', 'string', 'max:50'],
            'philhealth_number' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:10000'],
            'contact_number' => ['nullable', 'string', 'max:30'],
            'place_of_birth' => ['nullable', 'string', 'max:255'],
            'emergency_contact_person' => ['nullable', 'string', 'max:200'],
            'emergency_contact_number' => ['nullable', 'string', 'max:30'],
            'hmo_status' => ['nullable', Rule::in(['Yes', 'No', 'N/A', 'Upon regularization'])],
            'ecu' => ['nullable', 'string', 'max:100'],
            'mobile_subscription' => ['nullable', Rule::in(['Yes 1499 subs', 'Yes 1799 subs', 'Yes 799 subs'])],
            'gpcci_mobile_number' => ['nullable', 'string', 'max:30'],
            'sick_leave_days' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'birthday_leave_days' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'vacation_leave_days' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'business_card' => ['nullable', 'boolean'],
            'intern_no' => [$isIntern ? 'required' : 'nullable', 'string', 'max:30', $internNoRule],
            'intern_name' => [$isIntern ? 'required' : 'nullable', 'string', 'max:255'],
            'designation' => [$isIntern ? 'required' : 'nullable', 'string', 'max:150'],
            'internship_start_date' => [$isIntern ? 'required' : 'nullable', 'date'],
            'internship_end_date' => $internshipEndDateRules,
            'internship_status' => [
                $isIntern ? 'required' : 'nullable',
                Rule::in(['Finished', 'Pre terminated', 'Active', 'Cancelled']),
            ],
            'intern_address' => ['nullable', 'string', 'max:10000'],
            'intern_contact_number' => ['nullable', 'string', 'max:30'],
            'university' => ['nullable', 'string', 'max:200'],
            'course' => ['nullable', 'string', 'max:200'],
            'degree_expected' => ['nullable', 'string', 'max:200'],
        ], [
            'regularized_date.after_or_equal' => 'The date regularized must be on or after the date hired.',
            'last_date.after_or_equal' => 'The end-of-employment date must be on or after the date hired.',
            'internship_end_date.after_or_equal' => 'The internship end date must be on or after the internship start date.',
        ]);

        if ($validator->fails()) {
            return back()
                ->withErrors($validator)
                ->withInput()
                ->with('edit_user_id', $user->id);
        }

        $data = $validator->validated();
        $newRole = Role::findOrFail($data['role_id']);
        if ($user->status === 'Active' && $user->roles()->where('slug', 'super-admin')->exists()
            && ($data['status'] !== 'Active' || $newRole->slug !== 'super-admin')
            && $this->activeSuperAdminCount() < 2) {
            return back()
                ->withErrors(['user' => 'You cannot disable or demote the last active Super Admin.'])
                ->withInput()
                ->with('edit_user_id', $user->id);
        }

        DB::transaction(function () use ($data, $employee, $intern, $newRole, $request, $user): void {
            if ($intern) {
                $intern->update([
                    'intern_no' => $data['intern_no'],
                    'intern_name' => trim($data['intern_name']),
                    'designation' => $data['designation'],
                    'email' => strtolower(trim($data['email'])),
                    'birth_date' => $data['birth_date'] ?? null,
                    'internship_start_date' => $data['internship_start_date'],
                    'period' => array_key_exists('period', $data) ? $data['period'] : $intern->period,
                    'internship_end_date' => $data['internship_end_date'] ?? null,
                    'gender' => $data['gender'] ?? null,
                    'department_id' => $data['department_id'],
                    'position' => $data['position'],
                    'immediate_supervisor' => $data['immediate_supervisor'] ?? null,
                    'address' => $data['intern_address'] ?? null,
                    'contact_number' => $data['intern_contact_number'] ?? null,
                    'personal_email' => $data['personal_email'] ?? null,
                    'university' => $data['university'] ?? null,
                    'course' => $data['course'] ?? null,
                    'degree_expected' => $data['degree_expected'] ?? null,
                    'internship_status' => $data['internship_status'],
                ]);
                $name = trim($data['intern_name']);
            } else {
                $name = trim(implode(' ', array_filter([
                    trim($data['first_name']),
                    isset($data['middle_name']) ? trim($data['middle_name']) : null,
                    trim($data['last_name']),
                ])));
                $employee->update([
                    'employee_no' => $data['employee_no'],
                    'first_name' => trim($data['first_name']),
                    'middle_name' => $data['middle_name'] ?? null,
                    'last_name' => trim($data['last_name']),
                    'full_name' => $name,
                    'nationality' => $data['nationality'] ?? null,
                    'email' => strtolower(trim($data['email'])),
                    'contact_number' => $data['contact_number'] ?? null,
                    'address' => $data['address'] ?? null,
                    'gender' => $data['gender'] ?? null,
                    'birth_date' => $data['birth_date'] ?? null,
                    'hire_date' => $data['hire_date'],
                    'period' => array_key_exists('period', $data) ? $data['period'] : $employee->period,
                    'regularized_date' => $data['regularized_date'] ?? null,
                    'immediate_supervisor' => $data['immediate_supervisor'] ?? null,
                    'last_date' => $data['last_date'] ?? null,
                    'employment_status' => $data['employment_status'],
                    'department_id' => $data['department_id'],
                    'position' => $data['position'],
                    'place_of_birth' => $data['place_of_birth'] ?? null,
                    'sss_number' => $data['sss_number'] ?? null,
                    'tin_number' => $data['tin_number'] ?? null,
                    'hdmf_number' => $data['hdmf_number'] ?? null,
                    'philhealth_number' => $data['philhealth_number'] ?? null,
                    'personal_email' => $data['personal_email'] ?? null,
                    'emergency_contact_person' => $data['emergency_contact_person'] ?? null,
                    'emergency_contact_number' => $data['emergency_contact_number'] ?? null,
                    'hmo_status' => $data['hmo_status'] ?? null,
                    'ecu' => $data['ecu'] ?? null,
                    'mobile_subscription' => $data['mobile_subscription'] ?? null,
                    'gpcci_mobile_number' => $data['gpcci_mobile_number'] ?? null,
                    'sick_leave_days' => $data['sick_leave_days'] ?? null,
                    'birthday_leave_days' => $data['birthday_leave_days'] ?? null,
                    'vacation_leave_days' => $data['vacation_leave_days'] ?? null,
                    'business_card' => $request->boolean('business_card'),
                    'status' => $data['employment_status'] === 'Resigned' ? 'Inactive' : 'Active',
                ]);
            }

            $user->update([
                'name' => $name,
                'email' => strtolower(trim($data['email'])),
                'department_id' => $data['department_id'],
                'status' => $data['status'],
            ]);
            $user->roles()->sync([$newRole->id]);
            $this->audit($request, 'user.profile_updated', 'user', $user->id);
        });

        return back()->with('status', 'User profile updated.');
    }

    public function destroyUser(Request $request, User $user): RedirectResponse
    {
        if (ContractRecipient::where('user_id', $user->id)->exists()) {
            return back()->withErrors(['user' => 'A user with contract records cannot be deleted. Disable the account to preserve its receipt history.']);
        }

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
        $data = $request->validate($this->organizationEmployeeRules());
        $employee = DB::transaction(function () use ($data): Employee {
            [$firstName, $lastName] = $this->splitName($data['employee_name']);
            $department = Department::firstOrCreate(['department_name' => $data['department']]);

            return Employee::create([
                'employee_no' => $data['id_number'],
                'first_name' => $firstName,
                'last_name' => $lastName,
                'position' => $data['designation'],
                'department_id' => $department->id,
                'hire_date' => $data['starting_date'],
                'period' => $data['period'] ?? null,
                'preparer' => $data['preparer'],
                'assigned_it' => $data['assigned_it'],
                'last_date' => $data['last_date'] ?? null,
                'employment_status' => $data['employment_status'],
                'employment_type' => $data['employment_type'],
                'status' => 'Active',
            ]);
        });
        $this->audit($request, 'employee.created', 'employee', $employee->id);

        return back()->with('status', 'Employee created.');
    }

    public function employeeList(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $employees = Employee::with('department')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('employee_no', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('position', 'like', "%{$search}%")
                        ->orWhere('period', 'like', "%{$search}%")
                        ->orWhere('preparer', 'like', "%{$search}%")
                        ->orWhere('assigned_it', 'like', "%{$search}%")
                        ->orWhere('employment_type', 'like', "%{$search}%");
                });
            })
            ->orWhereHas('department', fn ($query) => $query->where('department_name', 'like', "%{$search}%"))
            ->when($search === '', fn ($query) => $query)
            ->latest()
            ->get()
            ->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'name' => trim($employee->first_name.' '.$employee->last_name),
                'designation' => $employee->position,
                'department' => $employee->department?->department_name,
                'starting_date' => $employee->hire_date?->format('Y-m-d'),
                'period' => $employee->period,
                'id_number' => $employee->employee_no,
                'preparer' => $employee->preparer,
                'assigned_it' => $employee->assigned_it,
                'employment_type' => $employee->employment_type,
            ]);

        return response()->json(['data' => $employees]);
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

    private function organizationEmployeeRules(): array
    {
        return [
            'employee_name' => ['required', 'string', 'max:200'],
            'designation' => ['required', 'string', 'max:100'],
            'department' => ['required', 'string', 'max:100'],
            'starting_date' => ['required', 'date'],
            'period' => ['nullable', 'string', 'max:100', 'required_if:employment_type,Internship'],
            'id_number' => ['required', 'string', 'max:20', 'unique:employees,employee_no'],
            'preparer' => ['required', 'string', 'max:150'],
            'assigned_it' => ['required', 'string', 'max:150'],
            'last_date' => ['nullable', 'date', 'after_or_equal:starting_date'],
            'employment_status' => ['required', Rule::in(['Employee', 'Intern'])],
            'employment_type' => ['required', Rule::in(['Employment', 'Internship'])],
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
