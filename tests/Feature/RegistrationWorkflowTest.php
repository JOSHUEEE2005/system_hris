<?php

namespace Tests\Feature;

use App\Models\Employee;
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
