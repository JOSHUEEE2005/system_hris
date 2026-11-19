<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Services\ContractDocumentService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContractsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleAndPermissionSeeder)->run();
        Storage::fake('local');
    }

    public function test_hr_can_send_a_private_file_to_selected_employees_and_recipients_can_acknowledge_it(): void
    {
        $department = Department::where('department_name', 'Chamber Services')->firstOrFail();
        $hr = $this->createUser('HR Manager', 'hr@example.com', 'super-admin');
        $recipient = $this->createUser('Jamie Rivera', 'jamie@example.com', 'employee', $department->id);
        $otherUser = $this->createUser('Taylor Park', 'taylor@example.com', 'employee', $department->id);

        $this->actingAs($hr)->post(route('admin.contracts.store'), [
            'title' => 'Employment agreement',
            'file' => UploadedFile::fake()->create('agreement.docx', 12, 'application/octet-stream'),
            'recipient_type' => 'individual',
            'recipient_ids' => [$recipient->id],
        ])->assertRedirect()
            ->assertSessionHas('status', 'Contract uploaded and sent to 1 recipient.');

        $contract = Contract::with('recipients')->firstOrFail();
        Storage::disk('local')->assertExists($contract->storage_path);
        $this->assertSame('agreement.docx', $contract->original_filename);
        $this->assertSame('individual', $contract->recipient_type);
        $this->assertSame('Pending', $contract->recipients->firstOrFail()->status);
        $this->assertSame([$recipient->id], $contract->recipients->pluck('user_id')->all());
        $this->assertDatabaseHas('audit_logs', [
            'event_key' => 'contract.sent',
            'entity_type' => 'contract',
            'entity_id' => (string) $contract->id,
        ]);

        $this->actingAs($hr)->get(route('admin.contracts'))
            ->assertOk()
            ->assertSee('New Contract')
            ->assertSee('up to 35 MB')
            ->assertSee('Search employees by name, email, or department')
            ->assertSee('Jamie Rivera')
            ->assertSee('Edit')
            ->assertSee('Notifications')
            ->assertSee('Profile')
            ->assertSee('HR Manager')
            ->assertSee('Log out')
            ->assertSee('Employment agreement');
        $this->actingAs($recipient)->get(route('admin.contracts'))
            ->assertOk()
            ->assertSee('Employment agreement')
            ->assertSee('agreement.docx');
        $this->actingAs($recipient)->get(route('admin.contracts.download', $contract))
            ->assertDownload('agreement.docx');
        $this->actingAs($otherUser)->get(route('admin.contracts.download', $contract))->assertForbidden();
        $this->actingAs($otherUser)->get(route('admin.contracts.preview', $contract))->assertForbidden();
        $this->actingAs($hr)->get(route('admin.contracts.preview', $contract))->assertStatus(415);

        $this->actingAs($recipient)->post(route('admin.contracts.received', $contract))
            ->assertRedirect()
            ->assertSessionHas('status', 'Contract marked as received.');

        $this->assertDatabaseHas('contract_recipients', [
            'contract_id' => $contract->id,
            'user_id' => $recipient->id,
            'status' => 'Received',
        ]);
        $this->assertNotNull($contract->recipients()->firstOrFail()->fresh()->received_at);
    }

    public function test_department_contracts_are_assigned_to_active_users_in_that_department_only(): void
    {
        $department = Department::where('department_name', 'Dual Training')->firstOrFail();
        $otherDepartment = Department::where('department_name', 'Government Affairs')->firstOrFail();
        $hr = $this->createUser('HR Manager', 'hr@example.com', 'super-admin');
        $firstRecipient = $this->createUser('DT User 1', 'dt1@example.com', 'employee', $department->id);
        $secondRecipient = $this->createUser('DT User 2', 'dt2@example.com', 'employee', $department->id);
        $outsideRecipient = $this->createUser('GA User', 'ga@example.com', 'employee', $otherDepartment->id);

        $this->actingAs($hr)->post(route('admin.contracts.store'), [
            'title' => 'Department policy',
            'file' => UploadedFile::fake()->create('policy.bin', 4, 'application/octet-stream'),
            'recipient_type' => 'department',
            'department_id' => $department->id,
        ])->assertRedirect()
            ->assertSessionHas('status', 'Contract uploaded and sent to 2 recipients.');

        $contract = Contract::with('recipients')->firstOrFail();
        $this->assertSame('department', $contract->recipient_type);
        $this->assertSame($department->id, $contract->department_id);
        $this->assertEqualsCanonicalizing(
            [$firstRecipient->id, $secondRecipient->id],
            $contract->recipients->pluck('user_id')->all(),
        );
        $this->assertDatabaseMissing('contract_recipients', [
            'contract_id' => $contract->id,
            'user_id' => $outsideRecipient->id,
        ]);
    }

    public function test_employees_from_each_department_only_see_home_and_their_assigned_contracts(): void
    {
        $departmentNames = [
            'Chamber Services',
            'Dual Training',
            'Government Affairs',
            'Trade Promotions',
            'Communications',
            'Internal Org',
            'Interns',
        ];
        $hr = $this->createUser('HR Manager', 'hr@example.com', 'super-admin');
        $departmentUsers = [];

        foreach ($departmentNames as $index => $departmentName) {
            $department = Department::where('department_name', $departmentName)->firstOrFail();
            $user = $this->createUser(
                'Employee '.$index,
                'employee'.$index.'@example.com',
                'employee',
                $department->id,
            );
            $departmentUsers[] = $user;

            $this->actingAs($user)->get(route('stay-tuned'))
                ->assertOk()
                ->assertSee('Coming soon')
                ->assertSee('HOME')
                ->assertSee('Contracts')
                ->assertDontSee(route('admin.users'))
                ->assertDontSee(route('admin.calendar'))
                ->assertDontSee(route('admin.leaves'))
                ->assertDontSee(route('admin.onboarding'))
                ->assertDontSee(route('admin.history'));
        }

        foreach ([0, 1] as $index) {
            $this->actingAs($hr)->post(route('admin.contracts.store'), [
                'title' => 'Private agreement '.$index,
                'file' => UploadedFile::fake()->create('agreement-'.$index.'.pdf', 1, 'application/pdf'),
                'recipient_type' => 'individual',
                'recipient_ids' => [$departmentUsers[$index]->id],
            ])->assertRedirect();
        }

        $this->actingAs($departmentUsers[0])->get(route('admin.contracts'))
            ->assertOk()
            ->assertSee('Private agreement 0')
            ->assertDontSee('Private agreement 1');
        $this->actingAs($departmentUsers[1])->get(route('admin.contracts'))
            ->assertOk()
            ->assertSee('Private agreement 1')
            ->assertDontSee('Private agreement 0');
    }

    public function test_hr_can_create_and_send_a_sanitized_personalized_template_as_pdf_or_word(): void
    {
        $department = Department::where('department_name', 'Chamber Services')->firstOrFail();
        $hr = $this->createUser('HR Manager', 'hr@example.com', 'super-admin');
        $recipient = $this->createUser('Jamie Rivera', 'jamie@example.com', 'employee', $department->id);
        $employee = Employee::create([
            'employee_no' => 'EMP-105',
            'first_name' => 'Jamie',
            'last_name' => 'Rivera',
            'email' => 'jamie@example.com',
            'department_id' => $department->id,
            'position' => 'HR Analyst',
        ]);
        $recipient->update(['employee_id' => $employee->id]);

        $this->actingAs($hr)->post(route('admin.contracts.store'), [
            'contract_source' => 'editor',
            'title' => 'Employment agreement',
            'content_html' => '<h1>Hi [User Full Name]</h1><p>[Work Email] · [Department] · [Employee ID] · [Designation] · [Role] · [Status]</p><script>window.alert(1)</script>',
            'recipient_type' => 'individual',
            'recipient_ids' => [$recipient->id],
        ])->assertRedirect()
            ->assertSessionHas('status', 'Contract uploaded and sent to 1 recipient.');

        $contract = Contract::with('recipients')->firstOrFail();
        $this->assertNotNull($contract->content_html);
        $this->assertStringNotContainsString('<script', $contract->content_html);
        Storage::disk('local')->assertExists($contract->storage_path);
        $personalized = app(ContractDocumentService::class)->personalizeHtml($contract->content_html, $recipient);
        $this->assertStringContainsString('Hi Jamie Rivera', $personalized);
        $this->assertStringContainsString('jamie@example.com', $personalized);
        $this->assertStringContainsString('Chamber Services', $personalized);
        $this->assertStringContainsString('EMP-105', $personalized);
        $this->assertStringContainsString('HR Analyst', $personalized);
        $this->assertStringContainsString((string) Role::where('slug', 'employee')->value('name'), $personalized);
        $this->assertStringContainsString('Active', $personalized);

        $this->actingAs($recipient)
            ->get(route('admin.contracts.download', [$contract, 'format' => 'pdf']))
            ->assertHeader('content-type', 'application/pdf')
            ->assertDownload('employment-agreement.pdf');
        $this->actingAs($recipient)
            ->get(route('admin.contracts.download', [$contract, 'format' => 'docx']))
            ->assertDownload('employment-agreement.docx');
        $this->actingAs($recipient)
            ->get(route('admin.contracts.preview', $contract))
            ->assertHeader('content-type', 'application/pdf');
        $this->actingAs($recipient)
            ->post(route('admin.contracts.received', $contract))
            ->assertRedirect();
        $oldStoragePath = $contract->storage_path;
        $this->actingAs($hr)->put(route('admin.contracts.update', $contract), [
            'contract_source' => 'editor',
            'title' => 'Employment agreement',
            'content_html' => '<p>Updated terms for [User Full Name]</p>',
            'recipient_type' => 'individual',
            'recipient_ids' => [$recipient->id],
        ])->assertRedirect();
        $contract->refresh()->load('recipients');
        Storage::disk('local')->assertMissing($oldStoragePath);
        $this->assertSame('Pending', $contract->recipients->firstOrFail()->status);
        $this->assertStringContainsString('Updated terms', $contract->content_html);
        $this->actingAs($hr)
            ->get(route('admin.contracts'))
            ->assertOk()
            ->assertSee('Create contract')
            ->assertSee('User Full Name')
            ->assertSee('Employment agreement');
    }

    public function test_hr_can_edit_and_delete_contracts_and_review_the_changes_in_system_history(): void
    {
        $department = Department::where('department_name', 'Chamber Services')->firstOrFail();
        $hr = $this->createUser('HR Manager', 'hr@example.com', 'super-admin');
        $firstRecipient = $this->createUser('Jamie Rivera', 'jamie@example.com', 'employee', $department->id);
        $secondRecipient = $this->createUser('Taylor Park', 'taylor@example.com', 'employee', $department->id);

        $this->actingAs($hr)->post(route('admin.contracts.store'), [
            'title' => 'Original agreement',
            'file' => UploadedFile::fake()->create('original.pdf', 12, 'application/pdf'),
            'recipient_type' => 'individual',
            'recipient_ids' => [$firstRecipient->id],
        ])->assertRedirect();

        $contract = Contract::with('recipients')->firstOrFail();
        $oldStoragePath = $contract->storage_path;
        $this->actingAs($firstRecipient)->post(route('admin.contracts.received', $contract))->assertRedirect();
        $this->actingAs($firstRecipient)->put(route('admin.contracts.update', $contract), [])->assertForbidden();
        $this->actingAs($firstRecipient)->delete(route('admin.contracts.destroy', $contract))->assertForbidden();
        $this->actingAs($firstRecipient)->get(route('admin.history'))->assertForbidden();

        $this->actingAs($hr)->put(route('admin.contracts.update', $contract), [
            'title' => 'Updated agreement',
            'file' => UploadedFile::fake()->create('revised.pdf', 18, 'application/pdf'),
            'recipient_type' => 'individual',
            'recipient_ids' => [$firstRecipient->id, $secondRecipient->id],
        ])->assertRedirect()
            ->assertSessionHas('status', 'Contract updated successfully.');

        $contract->refresh()->load('recipients');
        Storage::disk('local')->assertMissing($oldStoragePath);
        Storage::disk('local')->assertExists($contract->storage_path);
        $this->assertSame('Updated agreement', $contract->title);
        $this->assertSame('revised.pdf', $contract->original_filename);
        $this->assertEqualsCanonicalizing(
            [$firstRecipient->id, $secondRecipient->id],
            $contract->recipients->pluck('user_id')->all(),
        );
        foreach ($contract->recipients as $recipient) {
            $this->assertSame('Pending', $recipient->status);
            $this->assertNull($recipient->received_at);
        }

        $updateLog = AuditLog::where('event_key', 'contract.updated')->firstOrFail();
        $this->assertTrue($updateLog->details['acknowledgements_reset']);
        $this->assertSame('Original agreement', $updateLog->details['title']['from']);
        $this->assertSame('Updated agreement', $updateLog->details['title']['to']);
        $this->actingAs($hr)->get(route('admin.history'))
            ->assertOk()
            ->assertSee('History')
            ->assertSee('Contract updated')
            ->assertSee('Updated agreement');

        $this->actingAs($hr)->delete(route('admin.contracts.destroy', $contract))
            ->assertRedirect()
            ->assertSessionHas('status', 'Contract deleted successfully.');
        $this->assertDatabaseMissing('contracts', ['id' => $contract->id]);
        Storage::disk('local')->assertMissing($contract->storage_path);
        $this->assertDatabaseHas('audit_logs', [
            'event_key' => 'contract.deleted',
            'entity_type' => 'contract',
            'entity_id' => (string) $contract->id,
        ]);
        $this->actingAs($hr)->get(route('admin.history'))
            ->assertOk()
            ->assertSee('Contract deleted')
            ->assertSee('revised.pdf');
    }

    private function createUser(string $name, string $email, string $roleSlug, ?int $departmentId = null): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password_hash' => Hash::make('StrongPass123!'),
            'email_verified_at' => now(),
            'department_id' => $departmentId,
            'status' => 'Active',
        ]);
        $user->roles()->attach(Role::where('slug', $roleSlug)->firstOrFail());

        return $user;
    }
}
