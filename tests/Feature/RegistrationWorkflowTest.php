<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Intern;
use App\Models\OnboardingRequest;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RegistrationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleAndPermissionSeeder)->run();
    }

    public function test_direct_registration_creates_pending_account_without_email_verification(): void
    {
        $this->get(route('register.email'))
            ->assertOk()
            ->assertSee('name="email"', false)
            ->assertSee('Email verification is paused');

        $this->post(route('register.store'), [
            'name' => 'New Employee',
            'email' => 'New.Employee@example.com',
            'contact_number' => '09170000000',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
        ])->assertRedirect(route('login'))
            ->assertSessionHas('status');

        $user = User::where('email', 'new.employee@example.com')->firstOrFail();
        $this->assertSame('Pending', $user->status);
        $this->assertTrue(Hash::check('StrongPass123!', $user->password_hash));
        $this->assertNull($user->email_verified_at);
        $this->assertSame('employee', $user->roles()->firstOrFail()->slug);
        $this->assertSame('Pending', $user->onboardingRequest->status);
        $this->assertNull($user->employee_id);
        $this->assertSame(0, DB::table('email_otps')->count());
        $this->assertTrue(Schema::hasColumn('users', 'name'));
        $this->assertTrue(Schema::hasColumn('users', 'password_hash'));
        $this->assertFalse(Schema::hasColumn('users', 'password'));
        $this->assertFalse(Schema::hasColumn('users', 'account_status'));
    }

    public function test_duplicate_registration_email_is_rejected(): void
    {
        User::create([
            'name' => 'Existing User',
            'email' => 'person@example.com',
            'password_hash' => Hash::make('StrongPass123!'),
            'status' => 'Pending',
        ]);

        $this->from(route('register.email'))->post(route('register.store'), [
            'name' => 'Duplicate User',
            'email' => 'PERSON@example.com',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
        ])->assertRedirect(route('register.email'))
            ->assertSessionHasErrors('email');

        $this->assertSame(1, User::count());
    }

    public function test_unverified_active_account_cannot_sign_in(): void
    {
        User::create([
            'name' => 'Unverified User',
            'email' => 'unverified@example.com',
            'password_hash' => Hash::make('StrongPass123!'),
            'status' => 'Active',
        ]);

        $this->post(route('login.store'), [
            'email' => 'unverified@example.com',
            'password' => 'StrongPass123!',
        ])->assertSessionHasErrors('email');
    }

    public function test_pending_accounts_cannot_sign_in_or_use_admin_routes(): void
    {
        $pending = User::create([
            'name' => 'Pending User',
            'email' => 'pending@example.com',
            'password_hash' => Hash::make('StrongPass123!'),
            'status' => 'Pending',
        ]);
        $pending->roles()->attach(Role::where('slug', 'employee')->firstOrFail());

        $this->post(route('login.store'), [
            'email' => 'pending@example.com',
            'password' => 'StrongPass123!',
        ])->assertSessionHasErrors('email');

        $this->actingAs($pending)->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_hr_manager_can_review_onboarding_but_cannot_manage_user_accounts(): void
    {
        $hr = User::create([
            'name' => 'HR Manager',
            'email' => 'hr@example.com',
            'password_hash' => Hash::make('StrongPass123!'),
            'email_verified_at' => now(),
            'status' => 'Active',
        ]);
        $hr->roles()->attach(Role::where('slug', 'hr-manager')->firstOrFail());

        $this->actingAs($hr)->get(route('admin.dashboard'))->assertOk();
        $this->actingAs($hr)->post(route('admin.users.store'), [])->assertForbidden();
    }

    public function test_dashboard_uses_permission_scoped_counts_for_summary(): void
    {
        $admin = User::create([
            'name' => 'Super Admin',
            'email' => 'dashboard-admin@example.com',
            'password_hash' => Hash::make('StrongPass123!'),
            'email_verified_at' => now(),
            'status' => 'Active',
        ]);
        $admin->roles()->attach(Role::where('slug', 'super-admin')->firstOrFail());

        User::create([
            'name' => 'Active User',
            'email' => 'active-dashboard@example.com',
            'password_hash' => Hash::make('StrongPass123!'),
            'status' => 'Active',
        ]);
        $pendingUser = User::create([
            'name' => 'Pending User',
            'email' => 'pending-dashboard@example.com',
            'password_hash' => Hash::make('StrongPass123!'),
            'status' => 'Pending',
        ]);
        OnboardingRequest::create(['user_id' => $pendingUser->id, 'status' => 'Pending']);

        Employee::create([
            'employee_no' => 'DASH-001',
            'first_name' => 'Active',
            'last_name' => 'Employee',
            'status' => 'Active',
        ]);
        Employee::create([
            'employee_no' => 'DASH-002',
            'first_name' => 'Inactive',
            'last_name' => 'Employee',
            'status' => 'Inactive',
        ]);
        Intern::create(['intern_no' => 'DASH-INT-001', 'intern_name' => 'Current Intern']);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewHas('pendingCount', 1)
            ->assertViewHas('userCount', 3)
            ->assertViewHas('activeUserCount', 2)
            ->assertViewHas('employeeCount', 2)
            ->assertViewHas('activeEmployeeCount', 1)
            ->assertViewHas('inactiveEmployeeCount', 1)
            ->assertViewHas('internCount', 1)
            ->assertViewHas('departmentCount', Department::count());
    }

    public function test_calendar_can_navigate_months_and_show_the_year_view(): void
    {
        $admin = User::create([
            'name' => 'Super Admin',
            'email' => 'calendar-admin@example.com',
            'password_hash' => Hash::make('StrongPass123!'),
            'email_verified_at' => now(),
            'status' => 'Active',
        ]);
        $admin->roles()->attach(Role::where('slug', 'super-admin')->firstOrFail());

        $this->actingAs($admin)
            ->get(route('admin.calendar', ['view' => 'month', 'year' => 2026, 'month' => 11]))
            ->assertOk()
            ->assertSee('November, 2026')
            ->assertSee('month=10', false)
            ->assertSee('month=12', false);

        $this->actingAs($admin)
            ->get(route('admin.calendar', ['view' => 'year', 'year' => 2026]))
            ->assertOk()
            ->assertSee('Calendar of Events:')
            ->assertSee('January')
            ->assertSee('December')
            ->assertSee('year=2025', false)
            ->assertSee('year=2027', false);
    }

    public function test_super_admin_can_create_a_linked_employee_profile_with_hr_fields(): void
    {
        $department = Department::where('department_name', 'Chamber Services')->firstOrFail();
        $admin = User::create([
            'name' => 'Super Admin',
            'email' => 'admin@example.com',
            'password_hash' => Hash::make('StrongPass123!'),
            'email_verified_at' => now(),
            'status' => 'Active',
        ]);
        $admin->roles()->attach(Role::where('slug', 'super-admin')->firstOrFail());

        $this->actingAs($admin)->get(route('admin.users'))
            ->assertOk()
            ->assertSee('Account access')
            ->assertSee('Starting date')
            ->assertSee('name="period"', false)
            ->assertSee('Create user');

        $this->actingAs($admin)->post(route('admin.users.with-employee.store'), [
            'profile_type' => 'employee',
            'employee_no' => 'EMP-100',
            'first_name' => 'Jamie',
            'middle_name' => 'Ann',
            'last_name' => 'Rivera',
            'nationality' => 'Filipino',
            'email' => 'Jamie.Rivera@example.com',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'role_id' => Role::where('slug', 'employee')->value('id'),
            'department_id' => $department->id,
            'position' => 'HR Analyst',
            'hire_date' => '2026-11-18',
            'period' => '6 months',
            'regularized_date' => '2027-05-18',
            'employment_status' => 'Active project based',
            'hmo_status' => 'Upon regularization',
            'mobile_subscription' => 'Yes 1499 subs',
            'sss_number' => '123-45-6789',
            'business_card' => '1',
            'immediate_supervisor' => 'HR Manager',
            'last_date' => '2027-05-18',
        ])->assertRedirect()
            ->assertSessionHas('status', 'Employee profile and user account created.');

        $employee = Employee::where('employee_no', 'EMP-100')->firstOrFail();
        $user = User::where('email', 'jamie.rivera@example.com')->firstOrFail();

        $this->assertSame('Jamie', $employee->first_name);
        $this->assertSame('Rivera', $employee->last_name);
        $this->assertSame('jamie.rivera@example.com', $employee->email);
        $this->assertSame($department->id, $employee->department_id);
        $this->assertSame('Jamie Ann Rivera', $employee->full_name);
        $this->assertSame('Ann', $employee->middle_name);
        $this->assertSame('Filipino', $employee->nationality);
        $this->assertSame('HR Analyst', $employee->position);
        $this->assertSame('2026-11-18', $employee->hire_date->format('Y-m-d'));
        $this->assertSame('6 months', $employee->period);
        $this->assertSame('2027-05-18', $employee->regularized_date->format('Y-m-d'));
        $this->assertSame('Active project based', $employee->employment_status);
        $this->assertSame('Upon regularization', $employee->hmo_status);
        $this->assertSame('Yes 1499 subs', $employee->mobile_subscription);
        $this->assertSame('123-45-6789', $employee->sss_number);
        $this->assertTrue($employee->business_card);
        $this->assertSame('HR Manager', $employee->immediate_supervisor);
        $this->assertSame($employee->id, $user->employee_id);
        $this->assertSame($department->id, $user->department_id);
        $this->assertTrue(Hash::check('StrongPass123!', $user->password_hash));
        $this->assertSame('employee', $user->roles()->firstOrFail()->slug);
    }

    public function test_super_admin_keeps_user_management_access_when_permission_links_are_stale(): void
    {
        $department = Department::where('department_name', 'Chamber Services')->firstOrFail();
        $admin = User::create([
            'name' => 'Super Admin',
            'email' => 'stale-permissions-admin@example.com',
            'password_hash' => Hash::make('StrongPass123!'),
            'email_verified_at' => now(),
            'status' => 'Active',
        ]);
        $superAdminRole = Role::where('slug', 'super-admin')->firstOrFail();
        $admin->roles()->attach($superAdminRole);
        $superAdminRole->permissions()->detach(
            \App\Models\Permission::whereIn('permission_key', ['users.manage', 'employees.manage'])->pluck('id'),
        );
        $employee = Employee::create([
            'employee_no' => 'STALE-001',
            'first_name' => 'Directory',
            'last_name' => 'User',
            'email' => 'directory@example.com',
            'hire_date' => '2025-01-01',
            'department_id' => $department->id,
            'position' => 'Analyst',
            'employment_status' => 'Active',
        ]);
        $directoryUser = User::create([
            'name' => 'Directory User',
            'email' => 'directory@example.com',
            'password_hash' => Hash::make('StrongPass123!'),
            'employee_id' => $employee->id,
            'department_id' => $department->id,
            'status' => 'Active',
        ]);
        $directoryUser->roles()->attach(Role::where('slug', 'employee')->firstOrFail());

        $this->assertTrue($admin->hasPermission('users.manage'));
        $this->assertTrue($admin->hasPermission('employees.manage'));
        $this->actingAs($admin)->get(route('admin.users'))
            ->assertOk()
            ->assertSee('id="open-user-modal"', false)
            ->assertSee('data-edit-user', false);
    }

    public function test_intern_creation_requires_an_internship_status_and_does_not_leave_partial_records(): void
    {
        $department = Department::where('department_name', 'Dual Training')->firstOrFail();
        $admin = User::create([
            'name' => 'Super Admin',
            'email' => 'admin@example.com',
            'password_hash' => Hash::make('StrongPass123!'),
            'email_verified_at' => now(),
            'status' => 'Active',
        ]);
        $admin->roles()->attach(Role::where('slug', 'super-admin')->firstOrFail());

        $this->actingAs($admin)->from(route('admin.users'))->post(route('admin.users.with-employee.store'), [
            'profile_type' => 'intern',
            'intern_no' => 'INT-100',
            'intern_name' => 'Taylor Park',
            'designation' => 'Marketing Intern',
            'email' => 'taylor@example.com',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'role_id' => Role::where('slug', 'employee')->value('id'),
            'department_id' => $department->id,
            'internship_start_date' => '2026-11-18',
        ])->assertRedirect(route('admin.users'))
            ->assertSessionHasErrors('internship_status');

        $this->get(route('admin.users'))->assertOk()->assertSee('sa-modal-error', false);
        $this->assertDatabaseMissing('users', ['email' => 'taylor@example.com']);
        $this->assertDatabaseMissing('interns', ['intern_no' => 'INT-100']);
    }

    public function test_super_admin_can_create_a_linked_intern_profile(): void
    {
        $department = Department::where('department_name', 'Communications')->firstOrFail();
        $admin = User::create([
            'name' => 'Super Admin',
            'email' => 'intern-admin@example.com',
            'password_hash' => Hash::make('StrongPass123!'),
            'email_verified_at' => now(),
            'status' => 'Active',
        ]);
        $admin->roles()->attach(Role::where('slug', 'super-admin')->firstOrFail());

        $this->actingAs($admin)->post(route('admin.users.with-employee.store'), [
            'profile_type' => 'intern',
            'intern_no' => 'INT-200',
            'intern_name' => 'Jordan Lee',
            'designation' => 'Communications Intern',
            'email' => 'Jordan.Lee@example.com',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'role_id' => Role::where('slug', 'employee')->value('id'),
            'department_id' => $department->id,
            'position' => 'Content Intern',
            'internship_start_date' => '2026-11-18',
            'period' => '12 weeks',
            'birth_date' => '2004-01-15',
            'gender' => 'Female',
            'internship_status' => 'Active',
            'university' => 'Example University',
            'course' => 'Communications',
            'degree_expected' => 'BA',
        ])->assertRedirect()
            ->assertSessionHas('status', 'Intern profile and user account created.');

        $intern = Intern::where('intern_no', 'INT-200')->firstOrFail();
        $user = User::where('email', 'jordan.lee@example.com')->firstOrFail();

        $this->assertSame('Jordan Lee', $intern->intern_name);
        $this->assertSame('Communications Intern', $intern->designation);
        $this->assertSame('Content Intern', $intern->position);
        $this->assertSame('12 weeks', $intern->period);
        $this->assertSame('Female', $intern->gender);
        $this->assertSame($department->id, $intern->department_id);
        $this->assertSame($user->id, $intern->user_id);
        $this->assertNull($user->employee_id);
        $this->assertSame($department->id, $user->department_id);
        $this->assertSame('employee', $user->roles()->firstOrFail()->slug);
    }

    public function test_super_admin_can_edit_an_employee_profile_and_account(): void
    {
        $department = Department::where('department_name', 'Government Affairs')->firstOrFail();
        $admin = User::create([
            'name' => 'Super Admin',
            'email' => 'edit-admin@example.com',
            'password_hash' => Hash::make('StrongPass123!'),
            'email_verified_at' => now(),
            'status' => 'Active',
        ]);
        $admin->roles()->attach(Role::where('slug', 'super-admin')->firstOrFail());
        $employee = Employee::create([
            'employee_no' => 'EDIT-001',
            'first_name' => 'Before',
            'last_name' => 'Edit',
            'email' => 'before.edit@example.com',
            'hire_date' => '2025-01-01',
            'regularized_date' => '2024-12-31',
            'last_date' => '2024-12-30',
            'department_id' => $department->id,
            'position' => 'Assistant',
            'employment_status' => 'Active',
        ]);
        $user = User::create([
            'name' => 'Before Edit',
            'email' => 'before.edit@example.com',
            'password_hash' => Hash::make('StrongPass123!'),
            'employee_id' => $employee->id,
            'department_id' => $department->id,
            'status' => 'Active',
        ]);
        $user->roles()->attach(Role::where('slug', 'employee')->firstOrFail());

        $this->actingAs($admin)->get(route('admin.users'))
            ->assertOk()
            ->assertSee('data-edit-user', false)
            ->assertSee('Edit');

        $this->actingAs($admin)->put(route('admin.users.profile.update', $user), [
            'email' => 'after.edit@example.com',
            'role_id' => Role::where('slug', 'employee')->value('id'),
            'status' => 'Disabled',
            'department_id' => Department::where('department_name', 'Communications')->value('id'),
            'position' => 'Policy Officer',
            'employee_no' => 'EDIT-002',
            'first_name' => 'After',
            'last_name' => 'Updated',
            'hire_date' => '2025-01-01',
            'period' => '12 months',
            'regularized_date' => '2024-12-31',
            'last_date' => '2024-12-30',
            'employment_status' => 'Active OIO',
        ])
            ->assertRedirect()
            ->assertSessionHas('status', 'User profile updated.');

        $this->assertSame('after.edit@example.com', $user->fresh()->email);

        $this->actingAs($admin)
            ->from(route('admin.users'))
            ->put(route('admin.users.profile.update', $user), [
                'email' => 'invalid.edit@example.com',
                'role_id' => Role::where('slug', 'employee')->value('id'),
                'status' => 'Disabled',
                'department_id' => Department::where('department_name', 'Communications')->value('id'),
                'position' => 'Policy Officer',
                'employee_no' => 'EDIT-002',
                'first_name' => 'After',
                'last_name' => 'Updated',
                'hire_date' => '2025-01-01',
                'regularized_date' => '2024-12-30',
                'last_date' => '2024-12-29',
                'employment_status' => 'Active OIO',
            ])
            ->assertRedirect(route('admin.users'))
            ->assertSessionHasErrors(['regularized_date', 'last_date'])
            ->assertSessionHas('errors', fn ($errors) => $errors->first('regularized_date') === 'The date regularized must be on or after the date hired.'
                && $errors->first('last_date') === 'The end-of-employment date must be on or after the date hired.');

        $this->assertSame('after.edit@example.com', $user->fresh()->email);

        $this->actingAs($admin)->put(route('admin.users.profile.update', $user), [
            'email' => 'after.edit@example.com',
            'role_id' => Role::where('slug', 'employee')->value('id'),
            'status' => 'Disabled',
            'department_id' => Department::where('department_name', 'Communications')->value('id'),
            'gender' => 'Female',
            'position' => 'Policy Officer',
            'employee_no' => 'EDIT-002',
            'first_name' => 'After',
            'middle_name' => 'Marie',
            'last_name' => 'Updated',
            'hire_date' => '2025-01-01',
            'period' => 'Permanent',
            'regularized_date' => '2025-06-01',
            'last_date' => '2025-07-01',
            'employment_status' => 'Active OIO',
        ])->assertRedirect()
            ->assertSessionHas('status', 'User profile updated.');

        $employee->refresh();
        $user->refresh();
        $this->assertSame('EDIT-002', $employee->employee_no);
        $this->assertSame('After Marie Updated', $employee->full_name);
        $this->assertSame('Policy Officer', $employee->position);
        $this->assertSame('after.edit@example.com', $employee->email);
        $this->assertSame('after.edit@example.com', $user->email);
        $this->assertSame('Permanent', $employee->period);
        $this->assertSame('2025-06-01', $employee->regularized_date->format('Y-m-d'));
        $this->assertSame('2025-07-01', $employee->last_date->format('Y-m-d'));
        $this->assertSame('Disabled', $user->status);
        $this->assertSame('Active', $employee->status);
        $this->assertSame('employee', $user->roles()->firstOrFail()->slug);
    }

    public function test_super_admin_can_edit_an_intern_profile(): void
    {
        $department = Department::where('department_name', 'Trade Promotions')->firstOrFail();
        $admin = User::create([
            'name' => 'Super Admin',
            'email' => 'edit-intern-admin@example.com',
            'password_hash' => Hash::make('StrongPass123!'),
            'email_verified_at' => now(),
            'status' => 'Active',
        ]);
        $admin->roles()->attach(Role::where('slug', 'super-admin')->firstOrFail());
        $user = User::create([
            'name' => 'Casey Intern',
            'email' => 'casey@example.com',
            'password_hash' => Hash::make('StrongPass123!'),
            'department_id' => $department->id,
            'status' => 'Active',
        ]);
        $user->roles()->attach(Role::where('slug', 'employee')->firstOrFail());
        $intern = Intern::create([
            'intern_no' => 'EDIT-INT-001',
            'intern_name' => 'Casey Intern',
            'email' => 'casey@example.com',
            'department_id' => $department->id,
            'user_id' => $user->id,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.users'))
            ->put(route('admin.users.profile.update', $user), [
                'email' => 'casey@example.com',
                'role_id' => Role::where('slug', 'employee')->value('id'),
                'status' => 'Active',
                'department_id' => $department->id,
                'position' => 'Trade Research Intern',
                'intern_no' => 'EDIT-INT-002',
                'intern_name' => 'Casey Updated',
                'designation' => 'Research Intern',
                'internship_start_date' => '2026-01-01',
                'period' => '6 months',
                'internship_end_date' => '2025-12-31',
                'internship_status' => 'Finished',
            ])
            ->assertRedirect(route('admin.users'))
            ->assertSessionHasErrors(['internship_end_date'])
            ->assertSessionHas('errors', fn ($errors) => $errors->first('internship_end_date') === 'The internship end date must be on or after the internship start date.');

        $this->assertSame('casey@example.com', $user->fresh()->email);

        $this->actingAs($admin)->put(route('admin.users.profile.update', $user), [
            'email' => 'casey.updated@example.com',
            'role_id' => Role::where('slug', 'employee')->value('id'),
            'status' => 'Active',
            'department_id' => $department->id,
            'position' => 'Trade Research Intern',
            'intern_no' => 'EDIT-INT-002',
            'intern_name' => 'Casey Updated',
            'designation' => 'Research Intern',
            'internship_start_date' => '2026-01-01',
            'period' => '8 months',
            'internship_status' => 'Finished',
        ])->assertRedirect()
            ->assertSessionHas('status', 'User profile updated.');

        $intern->refresh();
        $user->refresh();
        $this->assertSame('EDIT-INT-002', $intern->intern_no);
        $this->assertSame('Casey Updated', $intern->intern_name);
        $this->assertSame('Research Intern', $intern->designation);
        $this->assertSame('8 months', $intern->period);
        $this->assertSame('casey.updated@example.com', $intern->email);
        $this->assertSame('casey.updated@example.com', $user->email);
    }

    public function test_hr_approval_creates_employee_and_activates_registered_user(): void
    {
        $user = User::create([
            'name' => 'Avery Garcia',
            'email' => 'avery@example.com',
            'password_hash' => Hash::make('StrongPass123!'),
            'email_verified_at' => now(),
            'status' => 'Pending',
        ]);
        $user->roles()->attach(Role::where('slug', 'employee')->firstOrFail());
        $onboarding = OnboardingRequest::create([
            'user_id' => $user->id,
            'contact_number' => '09170000000',
            'status' => 'Pending',
        ]);
        $admin = User::create([
            'name' => 'HR Manager',
            'email' => 'hr@example.com',
            'password_hash' => Hash::make('StrongPass123!'),
            'email_verified_at' => now(),
            'status' => 'Active',
        ]);
        $admin->roles()->attach(Role::where('slug', 'hr-manager')->firstOrFail());

        $this->actingAs($admin)->post(route('admin.onboarding.approve', $onboarding), [
            'confirmed_identity' => '1',
            'employee_no' => 'EMP-001',
            'position' => 'Analyst',
            'hire_date' => '2026-10-21',
        ])->assertRedirect();

        $this->assertDatabaseHas('employees', [
            'employee_no' => 'EMP-001',
            'email' => 'avery@example.com',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'status' => 'Active',
        ]);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertDatabaseHas('onboarding_requests', [
            'id' => $onboarding->id,
            'status' => 'Approved',
        ]);
    }

    public function test_hr_approval_links_an_existing_employee_by_verified_email(): void
    {
        $employee = Employee::create([
            'employee_no' => 'EMP-EXISTING',
            'first_name' => 'Jordan',
            'last_name' => 'Lee',
            'email' => 'jordan@example.com',
            'status' => 'Active',
        ]);
        $user = User::create([
            'name' => 'Jordan Lee',
            'email' => 'jordan@example.com',
            'password_hash' => Hash::make('StrongPass123!'),
            'email_verified_at' => now(),
            'status' => 'Pending',
        ]);
        $user->roles()->attach(Role::where('slug', 'employee')->firstOrFail());
        $onboarding = OnboardingRequest::create(['user_id' => $user->id]);
        $hr = User::create([
            'name' => 'HR Manager',
            'email' => 'hr@example.com',
            'password_hash' => Hash::make('StrongPass123!'),
            'email_verified_at' => now(),
            'status' => 'Active',
        ]);
        $hr->roles()->attach(Role::where('slug', 'hr-manager')->firstOrFail());

        $this->actingAs($hr)->post(route('admin.onboarding.approve', $onboarding), [])
            ->assertSessionHasErrors('confirmed_identity');

        $this->actingAs($hr)->post(route('admin.onboarding.approve', $onboarding), [
            'confirmed_identity' => '1',
        ])->assertRedirect();

        $this->assertSame(1, Employee::where('email', 'jordan@example.com')->count());
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'employee_id' => $employee->id,
            'status' => 'Active',
        ]);
    }

    public function test_active_employee_can_log_in_and_returns_to_home(): void
    {
        $employee = User::create([
            'name' => 'Active Employee',
            'email' => 'active@example.com',
            'password_hash' => Hash::make('StrongPass123!'),
            'email_verified_at' => now(),
            'status' => 'Active',
        ]);
        $employee->roles()->attach(Role::where('slug', 'employee')->firstOrFail());

        $this->post(route('login.store'), [
            'email' => 'ACTIVE@example.com',
            'password' => 'StrongPass123!',
        ])->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($employee);
    }
}
