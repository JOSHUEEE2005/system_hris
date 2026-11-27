<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\ContractFolder;
use App\Models\ContractRecipient;
use App\Models\Department;
use App\Models\User;
use App\Services\ContractDocumentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ContractController extends Controller
{
    private const PREVIEWABLE_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    public function index(Request $request, ContractDocumentService $documents): View
    {
        $viewer = $request->user();
        $viewer->ensurePersonalContractFolder();
        $canManageContracts = $viewer->hasPermission('contracts.manage');
        $contracts = Contract::with(['department', 'createdBy'])->orderByDesc('sent_at')->orderByDesc('id');

        if ($canManageContracts) {
            $contracts->withCount([
                'recipients',
                'recipients as opened_recipients_count' => fn (Builder $query) => $query->whereNotNull('opened_at'),
                'recipients as pending_recipients_count' => fn (Builder $query) => $query->whereNull('opened_at'),
            ])->with(['folder', 'recipients.user.department', 'recipients.user.employee.department', 'recipients.user.intern.department']);
        } else {
            $contracts->whereHas('recipients', fn (Builder $query) => $query->where('user_id', $viewer->id))
                ->with(['recipients' => fn ($query) => $query->where('user_id', $viewer->id)]);
        }

        return view('admin.contracts', [
            'contracts' => $contracts->get(),
            'departments' => $canManageContracts
                ? Department::orderBy('department_name')->get()
                : collect(),
            'folders' => $canManageContracts
                ? ContractFolder::whereNull('user_id')->orderBy('name')->get()
                : collect(),
            'personalFolders' => $canManageContracts
                ? ContractFolder::with([
                    'user' => fn ($query) => $query
                        ->with(['department', 'employee.department', 'intern.department'])
                        ->withCount([
                            'contractRecipients',
                            'contractRecipients as opened_contract_recipients_count' => fn (Builder $recipients) => $recipients->whereNotNull('opened_at'),
                            'contractRecipients as completed_contract_recipients_count' => fn (Builder $recipients) => $recipients
                                ->where(function (Builder $query) {
                                    $query->where('status', 'Approved')
                                        ->orWhere(function (Builder $query) {
                                            $query->whereNotNull('opened_at')
                                                ->whereHas('contract', fn (Builder $contracts) => $contracts->where('response_mode', 'as_is'));
                                        });
                                }),
                            'contractRecipients as employee_action_contract_recipients_count' => fn (Builder $recipients) => $recipients
                                ->whereIn('status', ['Pending', 'Opened'])
                                ->whereHas('contract', fn (Builder $contracts) => $contracts->where('response_mode', 'requires_return')),
                            'contractRecipients as hr_review_contract_recipients_count' => fn (Builder $recipients) => $recipients
                                ->whereIn('status', ['Submitted', 'Changes Requested'])
                                ->whereHas('contract', fn (Builder $contracts) => $contracts->where('response_mode', 'requires_return')),
                        ]),
                ])->whereNotNull('user_id')->orderBy('name')->get()
                : $viewer->personalContractFolder()->get(),
            'recipients' => $canManageContracts
                ? $this->recipientCandidateQuery()->with(['department', 'employee.department', 'intern.department'])->orderBy('name')->get()
                : collect(),
            'canManageContracts' => $canManageContracts,
            'oldContractContentHtml' => $request->session()->hasOldInput('content_html')
                ? $documents->sanitizeHtml((string) $request->old('content_html'))
                : '',
        ]);
    }

    public function storeFolder(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('folder', [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('contract_folders', 'name')->whereNull('user_id'),
            ],
        ]);

        ContractFolder::create([
            'name' => trim($data['name']),
            'created_by' => $request->user()->id,
        ]);

        return back()->with('status', 'Folder created successfully.');
    }

    public function store(Request $request, ContractDocumentService $documents): RedirectResponse
    {
        $sourceType = $request->input('contract_source', 'upload');
        if ($request->input('folder_id') === '') {
            $request->merge(['folder_id' => null]);
        }

        $data = $request->validate([
            'contract_source' => ['sometimes', Rule::in(['upload', 'editor'])],
            'title' => ['required', 'string', 'max:200'],
            'file' => [$sourceType === 'upload' ? 'required' : 'nullable', 'file', 'max:35840'],
            'content_html' => [$sourceType === 'editor' ? 'required' : 'nullable', 'string', 'max:100000'],
            'recipient_type' => ['required', Rule::in(['department', 'individual', 'all'])],
            'response_mode' => ['sometimes', Rule::in(['as_is', 'requires_return'])],
            'department_id' => ['nullable', 'required_if:recipient_type,department', 'integer', 'exists:departments,id'],
            'folder_id' => ['nullable', 'integer', Rule::exists('contract_folders', 'id')->whereNull('user_id')],
            'recipient_ids' => ['required_if:recipient_type,individual', 'array', 'min:1'],
            'recipient_ids.*' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('status', 'Active'),
            ],
        ]);
        $data['response_mode'] = $data['response_mode'] ?? 'as_is';
        $recipientIds = $this->resolveRecipientIds($data);

        $contentHtml = $sourceType === 'editor' ? $documents->sanitizeHtml($data['content_html']) : null;
        if ($sourceType === 'editor' && trim(strip_tags($contentHtml)) === '') {
            throw ValidationException::withMessages([
                'content_html' => 'Add contract text before sending.',
            ]);
        }
        $file = $data['file'] ?? null;
        $storagePath = $sourceType === 'editor'
            ? $this->storeEditorDocument($contentHtml)
            : $file->store('contracts', 'local');
        if ($storagePath === false) {
            return back()->withErrors(['file' => 'The contract file could not be stored.'])->withInput();
        }
        $mimeType = $sourceType === 'editor' ? 'text/html' : ($file->getMimeType() ?: 'application/octet-stream');
        $originalFilename = $sourceType === 'editor'
            ? $this->templateFilename($data['title'])
            : $file->getClientOriginalName();
        $fileSize = $sourceType === 'editor' ? strlen($contentHtml) : $file->getSize();

        try {
            DB::transaction(function () use (
                $data,
                $contentHtml,
                $fileSize,
                $mimeType,
                $originalFilename,
                $storagePath,
                $recipientIds,
                $request,
            ): void {
                $contract = Contract::create([
                    'title' => $data['title'],
                    'original_filename' => $originalFilename,
                    'storage_path' => $storagePath,
                    'mime_type' => $mimeType,
                    'file_size' => $fileSize,
                    'recipient_type' => $data['recipient_type'],
                    'department_id' => $data['recipient_type'] === 'department' ? $data['department_id'] : null,
                    'response_mode' => $data['response_mode'],
                    'folder_id' => $data['folder_id'] ?? null,
                    'created_by' => $request->user()->id,
                    'sent_at' => now(),
                    'content_html' => $contentHtml,
                ]);
                $contract->recipients()->createMany(array_map(
                    fn (int $userId): array => ['user_id' => $userId, 'status' => 'Pending'],
                    $recipientIds,
                ));

                $this->audit($request, 'contract.sent', $contract->id, [
                    'title' => $contract->title,
                    'filename' => $contract->original_filename,
                    'recipient_type' => $contract->recipient_type,
                    'response_mode' => $contract->response_mode,
                    'department_id' => $contract->department_id,
                    'recipient_ids' => $recipientIds,
                ]);
            });
        } catch (Throwable $exception) {
            if (! Storage::disk('local')->delete($storagePath)) {
                Log::warning('Unable to remove a contract upload after the database transaction failed.', [
                    'storage_path' => $storagePath,
                ]);
            }

            throw $exception;
        }

        return back()->with('status', 'Contract uploaded and sent to '.$this->recipientCountLabel(count($recipientIds)).'.');
    }

    public function convertToEditor(Request $request, Contract $contract, ContractDocumentService $documents): RedirectResponse
    {
        @set_time_limit(300);

        $isReconversion = $contract->content_html !== null && $contract->original_pdf_path !== null;
        abort_unless($contract->content_html === null || $isReconversion, 422, 'This contract cannot be converted again.');

        $extension = strtolower(pathinfo($contract->original_filename, PATHINFO_EXTENSION));
        $isPdf = $contract->mime_type === 'application/pdf' || $extension === 'pdf';
        $isSupported = $isReconversion || in_array($contract->mime_type, [
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ], true) || in_array($extension, ['pdf', 'docx'], true);
        abort_unless($isSupported, 422, 'Only PDF and Word (.docx) uploads can be converted for editing.');

        $sourceStoragePath = $isReconversion ? $contract->original_pdf_path : $contract->storage_path;
        $path = Storage::disk('local')->path($sourceStoragePath);
        abort_unless(is_file($path), 404);

        try {
            $contentHtml = $documents->extractUploadedHtml(
                file_get_contents($path) ?: '',
                $isReconversion ? 'application/pdf' : $contract->mime_type,
                $isReconversion ? 'original.pdf' : $contract->original_filename,
            );
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['contract' => $exception->getMessage()]);
        }

        $storagePath = $this->storeEditorDocument($contentHtml);
        $oldStoragePath = $contract->storage_path;
        $originalPdfPath = $isReconversion ? $contract->original_pdf_path : null;
        if (! $isReconversion && $isPdf) {
            $originalPdfPath = 'contracts/original-pdfs/'.Str::uuid().'.pdf';
            if (! Storage::disk('local')->copy($oldStoragePath, $originalPdfPath)) {
                throw new \RuntimeException('The original PDF could not be preserved.');
            }
        }

        $supersededResponsePaths = [];
        try {
            DB::transaction(function () use ($request, $contract, $contentHtml, $storagePath, $originalPdfPath, $isReconversion, &$supersededResponsePaths): void {
                $lockedContract = Contract::with('recipients')
                    ->whereKey($contract->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $supersededResponsePaths = $lockedContract->recipients
                    ->pluck('returned_storage_path')
                    ->filter()
                    ->all();

                $lockedContract->update([
                    'original_filename' => $this->templateFilename($lockedContract->title, 'html'),
                    'storage_path' => $storagePath,
                    'original_pdf_path' => $originalPdfPath,
                    'mime_type' => 'text/html',
                    'file_size' => strlen($contentHtml),
                    'content_html' => $contentHtml,
                ]);

                $lockedContract->recipients()->update([
                    'status' => 'Pending',
                    'received_at' => null,
                    'opened_at' => null,
                    'returned_storage_path' => null,
                    'returned_filename' => null,
                    'returned_mime_type' => null,
                    'returned_file_size' => null,
                    'review_feedback' => null,
                    'submitted_at' => null,
                    'reviewed_at' => null,
                ]);

                $this->audit($request, 'contract.converted', $lockedContract->id, [
                    'title' => $lockedContract->title,
                    'result' => $isReconversion
                        ? 'Editable contract rebuilt from the retained original PDF.'
                        : 'Uploaded document converted to editable contract.',
                ]);
            });
        } catch (Throwable $exception) {
            if ($originalPdfPath && ! $isReconversion) {
                Storage::disk('local')->delete($originalPdfPath);
            }
            if (! Storage::disk('local')->delete($storagePath)) {
                Log::warning('Unable to remove converted contract content after the database transaction failed.', [
                    'storage_path' => $storagePath,
                ]);
            }

            throw $exception;
        }

        foreach (array_unique($supersededResponsePaths) as $path) {
            if (! Storage::disk('local')->delete($path)) {
                Log::warning('Unable to remove a recipient response after rebuilding a contract.', [
                    'storage_path' => $path,
                    'contract_id' => $contract->id,
                ]);
            }
        }

        if (! Storage::disk('local')->delete($oldStoragePath)) {
            Log::warning('The uploaded contract was converted but the original private file could not be removed.', [
                'storage_path' => $oldStoragePath,
                'contract_id' => $contract->id,
            ]);
        }

        return back()->with(
            'status',
            $isReconversion
                ? 'The editable contract was rebuilt from its original PDF. Review it and resend it to recipients.'
                : 'Contract converted to editable text. Review and edit it before sending again.',
        );
    }

    public function update(Request $request, Contract $contract, ContractDocumentService $documents): RedirectResponse
    {
        $sourceType = $request->input('contract_source', $contract->content_html !== null ? 'editor' : 'upload');
        $requiresUpload = $sourceType === 'upload' && $contract->content_html !== null;
        if ($request->input('folder_id') === '') {
            $request->merge(['folder_id' => null]);
        }

        $data = $request->validate([
            'contract_source' => ['sometimes', Rule::in(['upload', 'editor'])],
            'title' => ['required', 'string', 'max:200'],
            'file' => [$requiresUpload ? 'required' : 'nullable', 'file', 'max:35840'],
            'content_html' => [$sourceType === 'editor' ? 'required' : 'nullable', 'string', 'max:100000'],
            'recipient_type' => ['required', Rule::in(['department', 'individual', 'all'])],
            'response_mode' => ['sometimes', Rule::in(['as_is', 'requires_return'])],
            'department_id' => ['nullable', 'required_if:recipient_type,department', 'integer', 'exists:departments,id'],
            'folder_id' => ['nullable', 'integer', Rule::exists('contract_folders', 'id')->whereNull('user_id')],
            'recipient_ids' => ['required_if:recipient_type,individual', 'array', 'min:1'],
            'recipient_ids.*' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('status', 'Active'),
            ],
        ]);
        $data['response_mode'] = $data['response_mode'] ?? $contract->response_mode ?? 'as_is';
        $recipientIds = $this->resolveRecipientIds($data);
        if ($data['recipient_type'] === 'individual') {
            $candidateIds = $this->recipientCandidateQuery()->pluck('id')->all();
            $unavailableExistingIds = $contract->recipients()
                ->whereNotIn('user_id', $candidateIds)
                ->pluck('user_id')
                ->all();
            $recipientIds = array_values(array_unique(array_merge($recipientIds, $unavailableExistingIds)));
        }

        $contentHtml = $sourceType === 'editor' ? $documents->sanitizeHtml($data['content_html']) : null;
        if ($sourceType === 'editor' && trim(strip_tags($contentHtml)) === '') {
            throw ValidationException::withMessages([
                'content_html' => 'Add contract text before saving.',
            ]);
        }

        $file = $data['file'] ?? null;
        $documentChanged = (bool) $file || $contentHtml !== $contract->content_html;
        $newStoragePath = null;
        if ($sourceType === 'editor' && $contentHtml !== $contract->content_html) {
            $newStoragePath = $this->storeEditorDocument($contentHtml);
        } elseif ($file) {
            $newStoragePath = $file->store('contracts', 'local');
        }
        if ($file && $newStoragePath === false) {
            return back()->withErrors(['file' => 'The replacement file could not be stored.'])->withInput();
        }

        $mimeType = $sourceType === 'editor'
            ? 'text/html'
            : ($file ? ($file->getMimeType() ?: 'application/octet-stream') : $contract->mime_type);
        $originalFilename = $sourceType === 'editor'
            ? $this->templateFilename($data['title'])
            : ($file?->getClientOriginalName() ?? $contract->original_filename);
        $fileSize = $sourceType === 'editor'
            ? strlen($contentHtml)
            : ($file?->getSize() ?? $contract->file_size);
        $workflowChanged = $documentChanged || $data['response_mode'] !== $contract->response_mode;

        $oldStoragePath = null;
        $supersededResponsePaths = [];
        try {
            DB::transaction(function () use (
                $data,
                $contentHtml,
                $documentChanged,
                $fileSize,
                $mimeType,
                $originalFilename,
                $newStoragePath,
                $recipientIds,
                $request,
                $contract,
                &$oldStoragePath,
                &$supersededResponsePaths,
                $workflowChanged,
            ): void {
                $lockedContract = Contract::with('recipients')
                    ->whereKey($contract->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $oldStoragePath = $lockedContract->storage_path;
                $oldRecipientIds = $lockedContract->recipients->pluck('user_id')->all();
                $oldRecipientsById = $lockedContract->recipients->keyBy('user_id');
                $oldTitle = $lockedContract->title;
                $oldFilename = $lockedContract->original_filename;
                $oldRecipientType = $lockedContract->recipient_type;
                $oldDepartmentId = $lockedContract->department_id;
                $oldFolderId = $lockedContract->folder_id;
                $departmentId = $data['recipient_type'] === 'department' ? $data['department_id'] : null;

                $lockedContract->update([
                    'title' => $data['title'],
                    'original_filename' => $originalFilename,
                    'storage_path' => $newStoragePath ?? $lockedContract->storage_path,
                    'mime_type' => $mimeType,
                    'file_size' => $fileSize,
                    'recipient_type' => $data['recipient_type'],
                    'department_id' => $departmentId,
                    'folder_id' => $data['folder_id'] ?? null,
                    'content_html' => $contentHtml,
                    'response_mode' => $data['response_mode'],
                ]);

                $removedRecipients = $lockedContract->recipients()->whereNotIn('user_id', $recipientIds)->get();
                $supersededResponsePaths = array_merge(
                    $supersededResponsePaths,
                    $removedRecipients->pluck('returned_storage_path')->filter()->all(),
                );
                $lockedContract->recipients()->whereNotIn('user_id', $recipientIds)->delete();
                foreach ($recipientIds as $userId) {
                    $existingRecipient = $oldRecipientsById->get($userId);
                    if ($existingRecipient) {
                        if ($workflowChanged) {
                            if ($existingRecipient->returned_storage_path) {
                                $supersededResponsePaths[] = $existingRecipient->returned_storage_path;
                            }
                            $existingRecipient->update([
                                'status' => 'Pending',
                                'received_at' => null,
                                'opened_at' => null,
                                'returned_storage_path' => null,
                                'returned_filename' => null,
                                'returned_mime_type' => null,
                                'returned_file_size' => null,
                                'review_feedback' => null,
                                'submitted_at' => null,
                                'reviewed_at' => null,
                            ]);
                        }

                        continue;
                    }

                    $lockedContract->recipients()->create([
                        'user_id' => $userId,
                        'status' => 'Pending',
                    ]);
                }

                $this->audit($request, 'contract.updated', $lockedContract->id, [
                    'title' => ['from' => $oldTitle, 'to' => $data['title']],
                    'file' => [
                        'replaced' => $documentChanged,
                        'previous_filename' => $documentChanged ? $oldFilename : null,
                        'filename' => $lockedContract->original_filename,
                    ],
                    'recipient_type' => [
                        'from' => $oldRecipientType,
                        'to' => $data['recipient_type'],
                    ],
                    'department_id' => [
                        'from' => $oldDepartmentId,
                        'to' => $departmentId,
                    ],
                    'folder_id' => [
                        'from' => $oldFolderId,
                        'to' => $data['folder_id'] ?? null,
                    ],
                    'recipient_ids' => [
                        'added' => array_values(array_diff($recipientIds, $oldRecipientIds)),
                        'removed' => array_values(array_diff($oldRecipientIds, $recipientIds)),
                        'current' => $recipientIds,
                    ],
                    'opened_status_reset' => $documentChanged,
                    'response_mode' => [
                        'from' => $contract->response_mode,
                        'to' => $data['response_mode'],
                    ],
                ]);
            });
        } catch (Throwable $exception) {
            if ($newStoragePath && ! Storage::disk('local')->delete($newStoragePath)) {
                Log::warning('Unable to remove a replacement contract upload after the database transaction failed.', [
                    'storage_path' => $newStoragePath,
                ]);
            }

            throw $exception;
        }

        foreach (array_unique($supersededResponsePaths) as $path) {
            if (! Storage::disk('local')->delete($path)) {
                Log::warning('Unable to remove a superseded recipient contract response.', [
                    'storage_path' => $path,
                    'contract_id' => $contract->id,
                ]);
            }
        }

        if ($documentChanged && $oldStoragePath && ! Storage::disk('local')->delete($oldStoragePath)) {
            Log::warning('Unable to remove the previous contract file after replacement.', [
                'storage_path' => $oldStoragePath,
                'contract_id' => $contract->id,
            ]);

            return back()->with('warning', 'The contract was updated, but its previous file could not be removed from private storage.');
        }

        return back()->with('status', 'Contract updated successfully.');
    }

    public function destroy(Request $request, Contract $contract): RedirectResponse
    {
        $storagePaths = [];
        DB::transaction(function () use ($request, $contract, &$storagePaths): void {
            $lockedContract = Contract::with('recipients')
                ->whereKey($contract->id)
                ->lockForUpdate()
                ->firstOrFail();
            $storagePaths = array_merge(
                [$lockedContract->storage_path, $lockedContract->original_pdf_path],
                $lockedContract->recipients->pluck('returned_storage_path')->all(),
            );
            $this->audit($request, 'contract.deleted', $lockedContract->id, [
                'title' => $lockedContract->title,
                'filename' => $lockedContract->original_filename,
                'recipients' => $lockedContract->recipients->map(fn (ContractRecipient $recipient): array => [
                    'user_id' => $recipient->user_id,
                    'status' => $recipient->status,
                    'received_at' => $recipient->received_at?->toISOString(),
                    'opened_at' => $recipient->opened_at?->toISOString(),
                ])->values()->all(),
            ]);
            $lockedContract->delete();
        });

        $cleanupFailed = false;
        foreach (array_unique(array_filter($storagePaths)) as $path) {
            if (! Storage::disk('local')->delete($path)) {
                $cleanupFailed = true;
                Log::error('A contract record was deleted but an associated private file could not be removed.', [
                    'storage_path' => $path,
                    'contract_id' => $contract->id,
                ]);
            }
        }
        if ($cleanupFailed) {
            return back()->with('warning', 'The contract was deleted, but one or more stored files could not be removed. Contact an administrator.');
        }

        return back()->with('status', 'Contract deleted successfully.');
    }

    public function preview(Request $request, Contract $contract, ContractDocumentService $documents): BinaryFileResponse|Response
    {
        $this->authorizeAccess($request, $contract);
        $approvedResponse = $this->approvedResponseForViewer($request, $contract);
        if ($approvedResponse) {
            abort_unless(in_array($approvedResponse->returned_mime_type, self::PREVIEWABLE_MIME_TYPES, true), 415);
            $path = Storage::disk('local')->path($approvedResponse->returned_storage_path);
            abort_unless(is_file($path), 404);

            $response = response()->file($path, [
                'Content-Type' => $approvedResponse->returned_mime_type,
                'X-Content-Type-Options' => 'nosniff',
            ]);
            if ($request->boolean('track_open')) {
                $this->markOpenedIfRecipient($request, $contract, 'preview');
            }

            return $response;
        }

        if ($request->boolean('original') && $contract->original_pdf_path) {
            $originalPdfPath = Storage::disk('local')->path($contract->original_pdf_path);
            if (is_file($originalPdfPath)) {
                $response = response()->file($originalPdfPath, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
                if ($request->boolean('track_open')) {
                    $this->markOpenedIfRecipient($request, $contract, 'preview');
                }

                return $response;
            }
        }
        if ($contract->content_html !== null) {
            $recipient = $contract->recipients()->where('user_id', $request->user()->id)->exists()
                ? $request->user()
                : null;
            $html = $documents->personalizeHtml($contract->content_html, $recipient);

            $response = response($documents->renderHtmlPreview($html), 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'X-Content-Type-Options' => 'nosniff',
            ]);
            if ($request->boolean('track_open')) {
                $this->markOpenedIfRecipient($request, $contract, 'preview');
            }

            return $response;
        }

        abort_unless(in_array($contract->mime_type, self::PREVIEWABLE_MIME_TYPES, true), 415);

        $path = Storage::disk('local')->path($contract->storage_path);
        abort_unless(is_file($path), 404);

        $response = response()->file($path, [
            'Content-Type' => $contract->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
        if ($request->boolean('track_open')) {
            $this->markOpenedIfRecipient($request, $contract, 'preview');
        }

        return $response;
    }

    public function download(Request $request, Contract $contract, ContractDocumentService $documents): StreamedResponse
    {
        $this->authorizeAccess($request, $contract);
        $approvedResponse = $this->approvedResponseForViewer($request, $contract);
        if ($approvedResponse) {
            abort_unless(Storage::disk('local')->exists($approvedResponse->returned_storage_path), 404);

            $response = Storage::disk('local')->download(
                $approvedResponse->returned_storage_path,
                $approvedResponse->returned_filename,
                ['X-Content-Type-Options' => 'nosniff'],
            );
            $this->markOpenedIfRecipient($request, $contract, 'download');

            return $response;
        }

        if ($contract->original_pdf_path && $request->boolean('original')) {
            abort_unless(Storage::disk('local')->exists($contract->original_pdf_path), 404);
            $response = Storage::disk('local')->download(
                $contract->original_pdf_path,
                $contract->title.'-original.pdf',
                ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff'],
            );
            $this->markOpenedIfRecipient($request, $contract, 'download');

            return $response;
        }
        if ($contract->content_html !== null) {
            $format = strtolower((string) $request->query('format', 'pdf'));
            abort_unless(in_array($format, ['pdf', 'docx'], true), 422, 'Choose PDF or Word format.');

            $recipient = $contract->recipients()->where('user_id', $request->user()->id)->exists()
                ? $request->user()
                : null;
            $html = $documents->personalizeHtml($contract->content_html, $recipient);
            $contents = $format === 'pdf'
                ? $documents->renderPdf($html)
                : $documents->renderDocx($html);

            $response = response()->streamDownload(
                static function () use ($contents): void {
                    echo $contents;
                },
                $this->templateFilename($contract->title, $format),
                [
                    'Content-Type' => $format === 'pdf'
                        ? 'application/pdf'
                        : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'X-Content-Type-Options' => 'nosniff',
                ],
            );
            $this->markOpenedIfRecipient($request, $contract, 'download');

            return $response;
        }

        abort_unless(Storage::disk('local')->exists($contract->storage_path), 404);
        $response = Storage::disk('local')->download($contract->storage_path, $contract->original_filename, [
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $this->markOpenedIfRecipient($request, $contract, 'download');

        return $response;
    }

    public function submitResponse(Request $request, Contract $contract): RedirectResponse
    {
        abort_unless($contract->response_mode === 'requires_return', 404);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:35840'],
        ]);
        $recipient = $contract->recipients()
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
        abort_if($recipient->status === 'Approved', 409, 'This contract has already been approved.');

        $file = $data['file'];
        $storagePath = $file->store('contract-responses', 'local');
        if ($storagePath === false) {
            return back()->withErrors(['file' => 'Your completed contract could not be stored.'])->withInput();
        }

        $previousStoragePath = null;
        try {
            DB::transaction(function () use ($request, $contract, $recipient, $file, $storagePath, &$previousStoragePath): void {
                $lockedRecipient = ContractRecipient::whereKey($recipient->id)
                    ->where('contract_id', $contract->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                abort_if($lockedRecipient->status === 'Approved', 409, 'This contract has already been approved.');
                $previousStoragePath = $lockedRecipient->returned_storage_path;
                $submittedAt = now();
                $lockedRecipient->update([
                    'status' => 'Submitted',
                    'returned_storage_path' => $storagePath,
                    'returned_filename' => $file->getClientOriginalName(),
                    'returned_mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'returned_file_size' => $file->getSize(),
                    'review_feedback' => null,
                    'submitted_at' => $submittedAt,
                    'reviewed_at' => null,
                ]);
                $this->audit($request, 'contract.response_submitted', $contract->id, [
                    'recipient_user_id' => $lockedRecipient->user_id,
                    'filename' => $lockedRecipient->returned_filename,
                    'submitted_at' => $submittedAt->toISOString(),
                ]);
            });
        } catch (Throwable $exception) {
            if (! Storage::disk('local')->delete($storagePath)) {
                Log::warning('Unable to remove a contract response after its database update failed.', [
                    'storage_path' => $storagePath,
                    'contract_id' => $contract->id,
                ]);
            }

            throw $exception;
        }

        if ($previousStoragePath && ! Storage::disk('local')->delete($previousStoragePath)) {
            Log::warning('Unable to remove the superseded contract response.', [
                'storage_path' => $previousStoragePath,
                'contract_id' => $contract->id,
                'recipient_id' => $recipient->id,
            ]);
        }

        return back()->with('status', 'Your completed contract was sent to HR for review.');
    }

    public function downloadResponse(Request $request, Contract $contract, ContractRecipient $recipient): StreamedResponse
    {
        $this->authorizeReview($request, $contract, $recipient);
        abort_unless($recipient->returned_storage_path, 404);
        abort_unless(Storage::disk('local')->exists($recipient->returned_storage_path), 404);

        return Storage::disk('local')->download(
            $recipient->returned_storage_path,
            $recipient->returned_filename,
            ['X-Content-Type-Options' => 'nosniff'],
        );
    }

    public function previewResponse(Request $request, Contract $contract, ContractRecipient $recipient): BinaryFileResponse
    {
        $this->authorizeReview($request, $contract, $recipient);
        abort_unless($recipient->returned_storage_path, 404);
        abort_unless(in_array($recipient->returned_mime_type, self::PREVIEWABLE_MIME_TYPES, true), 415);

        $path = Storage::disk('local')->path($recipient->returned_storage_path);
        abort_unless(is_file($path), 404);

        $response = response()->file($path, [
            'Content-Type' => $recipient->returned_mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setContentDisposition('inline', $recipient->returned_filename);

        return $response;
    }

    public function downloadOwnResponse(Request $request, Contract $contract): StreamedResponse
    {
        $recipient = $contract->recipients()
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
        abort_unless($contract->response_mode === 'requires_return' && $recipient->returned_storage_path, 404);
        abort_unless(Storage::disk('local')->exists($recipient->returned_storage_path), 404);

        return Storage::disk('local')->download(
            $recipient->returned_storage_path,
            $recipient->returned_filename,
            ['X-Content-Type-Options' => 'nosniff'],
        );
    }

    public function approveResponse(Request $request, Contract $contract, ContractRecipient $recipient): RedirectResponse
    {
        $this->authorizeReview($request, $contract, $recipient);
        DB::transaction(function () use ($request, $contract, $recipient): void {
            $lockedRecipient = ContractRecipient::whereKey($recipient->id)
                ->where('contract_id', $contract->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($lockedRecipient->status === 'Submitted' && $lockedRecipient->returned_storage_path, 409);
            abort_unless(Storage::disk('local')->exists($lockedRecipient->returned_storage_path), 404);

            $reviewedAt = now();
            $lockedRecipient->update([
                'status' => 'Approved',
                'review_feedback' => null,
                'reviewed_at' => $reviewedAt,
            ]);
            $this->audit($request, 'contract.response_approved', $contract->id, [
                'recipient_user_id' => $lockedRecipient->user_id,
                'filename' => $lockedRecipient->returned_filename,
                'reviewed_at' => $reviewedAt->toISOString(),
            ]);
        });

        return back()->with('status', 'The submitted contract was approved for this recipient.');
    }

    public function requestResponseChanges(Request $request, Contract $contract, ContractRecipient $recipient): RedirectResponse
    {
        $this->authorizeReview($request, $contract, $recipient);
        $data = $request->validate([
            'feedback' => ['required', 'string', 'max:5000'],
        ]);
        DB::transaction(function () use ($request, $contract, $recipient, $data): void {
            $lockedRecipient = ContractRecipient::whereKey($recipient->id)
                ->where('contract_id', $contract->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($lockedRecipient->status === 'Submitted' && $lockedRecipient->returned_storage_path, 409);

            $reviewedAt = now();
            $lockedRecipient->update([
                'status' => 'Changes Requested',
                'review_feedback' => trim($data['feedback']),
                'reviewed_at' => $reviewedAt,
            ]);
            $this->audit($request, 'contract.response_changes_requested', $contract->id, [
                'recipient_user_id' => $lockedRecipient->user_id,
                'reviewed_at' => $reviewedAt->toISOString(),
            ]);
        });

        return back()->with('status', 'Revision feedback was sent to the recipient.');
    }

    private function recipientCandidateQuery(?int $departmentId = null): Builder
    {
        $query = User::query()
            ->where('status', 'Active')
            ->where(fn (Builder $query) => $query
                ->whereNotNull('employee_id')
                ->orWhereNotNull('department_id')
                ->orWhereHas('intern'));

        if ($departmentId !== null) {
            $query->where(function (Builder $query) use ($departmentId): void {
                $query->where('department_id', $departmentId)
                    ->orWhere(function (Builder $query) use ($departmentId): void {
                        $query->whereNull('department_id')
                            ->whereHas('employee', fn (Builder $employee) => $employee->where('department_id', $departmentId));
                    })
                    ->orWhere(function (Builder $query) use ($departmentId): void {
                        $query->whereNull('department_id')
                            ->whereHas('intern', fn (Builder $intern) => $intern->where('department_id', $departmentId));
                    });
            });
        }

        return $query;
    }

    private function authorizeAccess(Request $request, Contract $contract): void
    {
        abort_unless(
            $request->user()->hasPermission('contracts.manage')
                || $contract->recipients()->where('user_id', $request->user()->id)->exists(),
            403,
        );
    }

    private function authorizeReview(Request $request, Contract $contract, ContractRecipient $recipient): void
    {
        abort_unless($request->user()->hasPermission('contracts.manage'), 403);
        abort_unless((int) $recipient->contract_id === (int) $contract->id, 404);
    }

    private function approvedResponseForViewer(Request $request, Contract $contract): ?ContractRecipient
    {
        if ($request->user()->hasPermission('contracts.manage')) {
            return null;
        }

        return $contract->recipients()
            ->where('user_id', $request->user()->id)
            ->where('status', 'Approved')
            ->whereNotNull('returned_storage_path')
            ->first();
    }

    private function resolveRecipientIds(array $data): array
    {
        if ($data['recipient_type'] === 'department') {
            $recipientIds = $this->recipientCandidateQuery((int) $data['department_id'])->pluck('id')->all();
            if ($recipientIds === []) {
                throw ValidationException::withMessages([
                    'department_id' => 'There are no active users in the selected department.',
                ]);
            }

            return $recipientIds;
        }

        if ($data['recipient_type'] === 'all') {
            $recipientIds = User::query()
                ->where('status', 'Active')
                ->where(fn (Builder $query) => $query
                    ->whereNotNull('employee_id')
                    ->orWhereHas('intern'))
                ->whereDoesntHave('roles', fn (Builder $query) => $query->whereIn('slug', ['hr-manager', 'super-admin']))
                ->orderBy('id')
                ->pluck('id')
                ->all();
            if ($recipientIds === []) {
                throw ValidationException::withMessages([
                    'recipient_type' => 'There are no active employees or interns to send this contract to.',
                ]);
            }

            return $recipientIds;
        }

        $requestedIds = array_values(array_unique(array_map('intval', $data['recipient_ids'] ?? [])));
        $recipientIds = $this->recipientCandidateQuery()->whereIn('id', $requestedIds)->pluck('id')->all();
        if (count($recipientIds) !== count($requestedIds)) {
            throw ValidationException::withMessages([
                'recipient_ids' => 'Choose active employee accounts from the recipient list.',
            ]);
        }

        return $recipientIds;
    }

    private function audit(Request $request, string $event, int $contractId, ?array $details = null): void
    {
        AuditLog::create([
            'actor_user_id' => $request->user()->id,
            'event_key' => $event,
            'entity_type' => 'contract',
            'entity_id' => (string) $contractId,
            'details' => $details,
        ]);
    }

    private function markOpenedIfRecipient(Request $request, Contract $contract, string $source): void
    {
        DB::transaction(function () use ($request, $contract, $source): void {
            $recipient = ContractRecipient::where('contract_id', $contract->id)
                ->where('user_id', $request->user()->id)
                ->lockForUpdate()
                ->first();

            if (! $recipient || $recipient->opened_at) {
                return;
            }

            $openedAt = now();
            $recipient->update([
                'status' => in_array($recipient->status, ['Pending', 'Opened'], true)
                    ? 'Opened'
                    : $recipient->status,
                'opened_at' => $openedAt,
                'received_at' => null,
            ]);
            $this->audit($request, 'contract.opened', $contract->id, [
                'source' => $source,
                'opened_at' => $openedAt->toISOString(),
            ]);
        });
    }

    private function recipientCountLabel(int $count): string
    {
        return $count.' '.($count === 1 ? 'recipient' : 'recipients');
    }

    private function storeEditorDocument(string $contentHtml): string
    {
        $path = 'contracts/templates/'.Str::uuid().'.html';
        if (! Storage::disk('local')->put($path, $contentHtml)) {
            throw new \RuntimeException('The contract template could not be stored.');
        }

        return $path;
    }

    private function templateFilename(string $title, string $extension = 'html'): string
    {
        return (Str::slug($title) ?: 'contract').'.'.$extension;
    }
}
