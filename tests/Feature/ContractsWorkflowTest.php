<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\ContractFolder;
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

    public function test_pdf_conversion_sanitizer_preserves_large_table_spans(): void
    {
        $html = app(ContractDocumentService::class)->sanitizeHtml(
            '<table><tbody><tr><th colspan="11" rowspan="13">Merged section</th></tr></tbody></table>',
        );

        $this->assertStringContainsString('colspan="11"', $html);
        $this->assertStringContainsString('rowspan="13"', $html);
    }

    public function test_pdf_export_converts_legacy_png_images_when_gd_is_unavailable(): void
    {
        if (extension_loaded('gd')) {
            $this->markTestSkipped('This regression applies only when PHP GD is unavailable.');
        }

        $html = '<img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l3sAAAAASUVORK5CYII=" alt="Header">';
        $pdf = app(ContractDocumentService::class)->renderPdf($html);

        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    public function test_word_export_preserves_colspan_and_rowspan(): void
    {
        $html = '<table><tbody>'
            .'<tr><td rowspan="2" colspan="2">Merged</td><td>Right</td></tr>'
            .'<tr><td>Below</td></tr>'
            .'</tbody></table>';

        $docx = app(ContractDocumentService::class)->renderDocx($html);
        $temporaryPath = tempnam(sys_get_temp_dir(), 'contract-docx-test-');
        file_put_contents($temporaryPath, $docx);

        $archive = new \ZipArchive();
        $this->assertTrue($archive->open($temporaryPath) === true);
        $documentXml = $archive->getFromName('word/document.xml');
        $archive->close();
        @unlink($temporaryPath);

        $this->assertIsString($documentXml);
        $this->assertStringContainsString('<w:gridSpan w:val="2"/>', $documentXml);
        $this->assertStringContainsString('<w:vMerge w:val="restart"/>', $documentXml);
        $this->assertStringContainsString('w:type="dxa"', $documentXml);
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($documentXml, '<w:vMerge'),
        );
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

    public function test_hr_folders_are_shared_and_contracts_can_be_filed_or_unfiled(): void
    {
        $department = Department::where('department_name', 'Chamber Services')->firstOrFail();
        $hr = $this->createUser('HR Manager', 'hr@example.com', 'super-admin');
        $anotherHrManager = $this->createUser('Another HR Manager', 'hr2@example.com', 'super-admin');
        $recipient = $this->createUser('Jamie Rivera', 'jamie@example.com', 'employee', $department->id);

        $this->actingAs($hr)->post(route('admin.contracts.folders.store'), [
            'name' => '2026 Agreements',
        ])->assertRedirect()
            ->assertSessionHas('status', 'Folder created successfully.');

        $folder = ContractFolder::where('name', '2026 Agreements')->firstOrFail();
        $this->assertSame($hr->id, $folder->created_by);

        $this->actingAs($hr)->post(route('admin.contracts.store'), [
            'title' => 'Employment agreement',
            'file' => UploadedFile::fake()->create('agreement.docx', 12, 'application/octet-stream'),
            'recipient_type' => 'individual',
            'recipient_ids' => [$recipient->id],
            'folder_id' => $folder->id,
        ])->assertRedirect();

        $contract = Contract::with('folder')->firstOrFail();
        $this->assertSame($folder->id, $contract->folder_id);
        $this->assertSame('2026 Agreements', $contract->folder->name);

        $this->actingAs($anotherHrManager)->get(route('admin.contracts'))
            ->assertOk()
            ->assertSee('2026 Agreements')
            ->assertSee('data-contract-folder-filter="'.$folder->id.'"', false);
        $this->actingAs($recipient)->get(route('admin.contracts'))
            ->assertOk()
            ->assertSee('Employment agreement')
            ->assertDontSee('2026 Agreements');
        $this->actingAs($recipient)->post(route('admin.contracts.folders.store'), [
            'name' => 'Private folder',
        ])->assertForbidden();

        $this->actingAs($hr)->put(route('admin.contracts.update', $contract), [
            'title' => 'Employment agreement',
            'recipient_type' => 'individual',
            'recipient_ids' => [$recipient->id],
            'folder_id' => '',
        ])->assertRedirect();

        $this->assertNull($contract->fresh()->folder_id);
    }

    public function test_hr_can_convert_an_uploaded_docx_into_the_contract_editor(): void
    {
        $department = Department::where('department_name', 'Chamber Services')->firstOrFail();
        $hr = $this->createUser('HR Manager', 'hr@example.com', 'super-admin');
        $recipient = $this->createUser('Jamie Rivera', 'jamie@example.com', 'employee', $department->id);

        $temporaryPath = tempnam(sys_get_temp_dir(), 'contract-test-docx-');
        $archive = new \ZipArchive();
        $archive->open($temporaryPath, \ZipArchive::OVERWRITE);
        $archive->addFromString('word/document.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body>'
            .'<w:p><w:r><w:t>Editable uploaded agreement</w:t></w:r></w:p>'
            .'<w:tbl><w:tr><w:tc><w:p><w:r><w:t>Status</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Description</w:t></w:r></w:p></w:tc></w:tr>'
            .'<w:tr><w:tc><w:p><w:r><w:t>Pending</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Complete onboarding</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'
            .'</w:body>'
            .'</w:document>'
        );
        $archive->close();
        $contents = file_get_contents($temporaryPath);
        @unlink($temporaryPath);

        Storage::disk('local')->put('contracts/uploaded-agreement.docx', $contents);
        $contract = Contract::create([
            'title' => 'Uploaded agreement',
            'original_filename' => 'uploaded-agreement.docx',
            'storage_path' => 'contracts/uploaded-agreement.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size' => strlen($contents),
            'recipient_type' => 'individual',
            'department_id' => null,
            'created_by' => $hr->id,
            'sent_at' => now(),
        ]);
        $contract->recipients()->create([
            'user_id' => $recipient->id,
            'status' => 'Received',
            'received_at' => now(),
        ]);

        $this->actingAs($hr)->post(route('admin.contracts.convert-editor', $contract))
            ->assertRedirect()
            ->assertSessionHas('status');

        $contract->refresh();
        $this->assertNotNull($contract->content_html);
        $this->assertStringContainsString('Editable uploaded agreement', $contract->content_html);
        $this->assertStringContainsString('<table>', $contract->content_html);
        $this->assertStringContainsString('Complete onboarding', $contract->content_html);
        $this->assertSame('text/html', $contract->mime_type);
        $this->assertSame('Pending', $contract->recipients()->firstOrFail()->status);
    }

    public function test_hr_can_convert_an_uploaded_pdf_into_layout_tables(): void
    {
        $department = Department::where('department_name', 'Chamber Services')->firstOrFail();
        $hr = $this->createUser('HR Manager', 'hr@example.com', 'super-admin');
        $recipient = $this->createUser('Jamie Rivera', 'jamie@example.com', 'employee', $department->id);
        $pdfPath = base_path('storage/app/private/contracts/mj00q3DsIoQGAXXjNO1cNwaXorqB5VTurXVArFRR.pdf');

        if (! is_file($pdfPath)) {
            $this->markTestSkipped('The sample PDF is not available in this test environment.');
        }

        $contents = file_get_contents($pdfPath);
        Storage::disk('local')->put('contracts/layout-sample.pdf', $contents);
        $contract = Contract::create([
            'title' => 'Layout sample',
            'original_filename' => 'layout-sample.pdf',
            'storage_path' => 'contracts/layout-sample.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => strlen($contents),
            'recipient_type' => 'individual',
            'department_id' => null,
            'created_by' => $hr->id,
            'sent_at' => now(),
        ]);
        $contract->recipients()->create([
            'user_id' => $recipient->id,
            'status' => 'Pending',
        ]);

        $this->actingAs($hr)->post(route('admin.contracts.convert-editor', $contract))
            ->assertRedirect();

        $contract->refresh();
        $this->assertNotNull($contract->content_html);
        $this->assertStringContainsString('<table class="converted-pdf-table"', $contract->content_html);
        $this->assertStringContainsString('converted-pdf-header', $contract->content_html);
        $this->assertStringContainsString('src="data:image/jpeg;base64,', $contract->content_html);
        $this->assertStringContainsString('rowspan=', $contract->content_html);
        $this->assertStringContainsString('colspan="6"', $contract->content_html);
        $this->assertStringContainsString(
            '<tr class="converted-pdf-section-row"><th class="converted-pdf-section-cell" colspan="6">',
            $contract->content_html,
        );
        $this->assertMatchesRegularExpression(
            '/class="converted-pdf-page" style="width:[0-9.]+pt;min-height:[0-9.]+pt"/',
            $contract->content_html,
        );
        $this->assertStringContainsString('STATUS', $contract->content_html);
        $this->assertStringContainsString('DESCRIPTION', $contract->content_html);

        $this->actingAs($hr)->get(route('admin.contracts.preview', $contract))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('STATUS')
            ->assertSee('converted-pdf-header');

        $this->actingAs($hr)->get(route('admin.contracts.download', $contract))
            ->assertOk()
            ->assertDownload();
    }

    public function test_hr_can_rebuild_a_converted_pdf_from_its_retained_original(): void
    {
        $department = Department::where('department_name', 'Chamber Services')->firstOrFail();
        $hr = $this->createUser('HR Manager', 'hr@example.com', 'super-admin');
        $recipient = $this->createUser('Jamie Rivera', 'jamie@example.com', 'employee', $department->id);
        $originalPath = 'contracts/original-pdfs/original.pdf';
        $editablePath = 'contracts/editable/current.html';
        $originalContents = 'retained original PDF';
        Storage::disk('local')->put($originalPath, $originalContents);
        Storage::disk('local')->put($editablePath, '<p>Old conversion</p>');
        $contract = Contract::create([
            'title' => 'Layout sample',
            'original_filename' => 'layout-sample.html',
            'storage_path' => $editablePath,
            'original_pdf_path' => $originalPath,
            'mime_type' => 'text/html',
            'file_size' => 20,
            'recipient_type' => 'individual',
            'department_id' => null,
            'created_by' => $hr->id,
            'sent_at' => now(),
            'content_html' => '<p>Old conversion</p>',
        ]);
        $contract->recipients()->create([
            'user_id' => $recipient->id,
            'status' => 'Received',
            'received_at' => now(),
        ]);

        $this->mock(ContractDocumentService::class)
            ->shouldReceive('extractUploadedHtml')
            ->once()
            ->with($originalContents, 'application/pdf', 'original.pdf')
            ->andReturn('<p>Rebuilt layout</p>');

        $this->actingAs($hr)->get(route('admin.contracts'))
            ->assertOk()
            ->assertSee('Rebuild from Original');

        $this->actingAs($hr)->post(route('admin.contracts.convert-editor', $contract))
            ->assertRedirect()
            ->assertSessionHas('status', 'The editable contract was rebuilt from its original PDF. Review it and resend it to recipients.');

        $contract->refresh();
        $this->assertSame($originalPath, $contract->original_pdf_path);
        $this->assertSame('Rebuilt layout', strip_tags($contract->content_html));
        $this->assertNotSame($editablePath, $contract->storage_path);
        $this->assertSame('Pending', $contract->recipients()->firstOrFail()->status);
        $this->assertNull($contract->recipients()->firstOrFail()->received_at);
        Storage::disk('local')->assertExists($originalPath);
        Storage::disk('local')->assertMissing($editablePath);
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

        $this->actingAs($hr)->get(route('admin.contracts'))
            ->assertOk()
            ->assertSee('Insert table')
            ->assertSee('Add header')
            ->assertSee('Add footer');

        $this->actingAs($hr)->post(route('admin.contracts.store'), [
            'contract_source' => 'editor',
            'title' => 'Employment agreement',
            'content_html' => '<header><p>Confidential: [Department]</p></header><h1>Hi [User Full Name]</h1><table><thead><tr><th>Employee</th><th>ID</th></tr></thead><tbody><tr><td>[User Full Name]</td><td>[Employee ID]</td></tr></tbody></table><p>[Work Email] · [Department] · [Designation] · [Role] · [Status]</p><footer><p>Contact: [Work Email]</p></footer><script>window.alert(1)</script>',
            'recipient_type' => 'individual',
            'recipient_ids' => [$recipient->id],
        ])->assertRedirect()
            ->assertSessionHas('status', 'Contract uploaded and sent to 1 recipient.');

        $contract = Contract::with('recipients')->firstOrFail();
        $this->assertNotNull($contract->content_html);
        $this->assertStringNotContainsString('<script', $contract->content_html);
        $this->assertStringContainsString('<header>', $contract->content_html);
        $this->assertStringContainsString('<table>', $contract->content_html);
        $this->assertStringContainsString('<footer>', $contract->content_html);
        Storage::disk('local')->assertExists($contract->storage_path);
        $personalized = app(ContractDocumentService::class)->personalizeHtml($contract->content_html, $recipient);
        $this->assertStringContainsString('Confidential: Chamber Services', $personalized);
        $this->assertStringContainsString('Hi Jamie Rivera', $personalized);
        $this->assertStringContainsString('jamie@example.com', $personalized);
        $this->assertStringContainsString('Chamber Services', $personalized);
        $this->assertStringContainsString('EMP-105', $personalized);
        $this->assertStringContainsString('HR Analyst', $personalized);
        $this->assertStringContainsString((string) Role::where('slug', 'employee')->value('name'), $personalized);
        $this->assertStringContainsString('Active', $personalized);
        $docxContents = app(ContractDocumentService::class)->renderDocx($personalized);
        $docxPath = tempnam(sys_get_temp_dir(), 'contract-export-test-');
        if ($docxPath === false) {
            $this->fail('Unable to allocate a temporary Word document.');
        }
        try {
            $this->assertNotFalse(file_put_contents($docxPath, $docxContents));
            $archive = new \ZipArchive;
            $this->assertTrue($archive->open($docxPath) === true);
            $headerXml = (string) $archive->getFromName('word/header1.xml');
            $footerXml = (string) $archive->getFromName('word/footer1.xml');
            $documentXml = (string) $archive->getFromName('word/document.xml');
            $relationshipsXml = (string) $archive->getFromName('word/_rels/document.xml.rels');
            $archive->close();
            $this->assertStringContainsString('Confidential: Chamber Services', $headerXml);
            $this->assertStringContainsString('Contact: jamie@example.com', $footerXml);
            $this->assertStringContainsString('<w:tbl>', $documentXml);
            $this->assertStringContainsString('/header', $relationshipsXml);
            $this->assertStringContainsString('/footer', $relationshipsXml);
            foreach ([$headerXml, $footerXml, $documentXml, $relationshipsXml] as $xmlContents) {
                $xml = new \DOMDocument;
                $this->assertTrue($xml->loadXML($xmlContents));
            }
        } finally {
            unlink($docxPath);
        }

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
