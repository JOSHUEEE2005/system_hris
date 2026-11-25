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
        $contracts = Contract::with(['department', 'createdBy'])->latest('sent_at');

        if ($canManageContracts) {
            $contracts->withCount([
                'recipients',
                'recipients as pending_recipients_count' => fn (Builder $query) => $query->where('status', 'Pending'),
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
                ? ContractFolder::with('user')->whereNotNull('user_id')->orderBy('name')->get()
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
            'recipient_type' => ['required', Rule::in(['department', 'individual'])],
            'department_id' => ['nullable', 'required_if:recipient_type,department', 'integer', 'exists:departments,id'],
            'folder_id' => ['nullable', 'integer', Rule::exists('contract_folders', 'id')->whereNull('user_id')],
            'recipient_ids' => ['required_if:recipient_type,individual', 'array', 'min:1'],
            'recipient_ids.*' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('status', 'Active'),
            ],
        ]);
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

        try {
            DB::transaction(function () use ($request, $contract, $contentHtml, $storagePath, $originalPdfPath, $isReconversion): void {
                $lockedContract = Contract::with('recipients')
                    ->whereKey($contract->id)
                    ->lockForUpdate()
                    ->firstOrFail();

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
            'recipient_type' => ['required', Rule::in(['department', 'individual'])],
            'department_id' => ['nullable', 'required_if:recipient_type,department', 'integer', 'exists:departments,id'],
            'folder_id' => ['nullable', 'integer', Rule::exists('contract_folders', 'id')->whereNull('user_id')],
            'recipient_ids' => ['required_if:recipient_type,individual', 'array', 'min:1'],
            'recipient_ids.*' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('status', 'Active'),
            ],
        ]);
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

        $oldStoragePath = null;
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
                ]);

                $lockedContract->recipients()->whereNotIn('user_id', $recipientIds)->delete();
                foreach ($recipientIds as $userId) {
                    $existingRecipient = $oldRecipientsById->get($userId);
                    if ($existingRecipient) {
                        if ($documentChanged) {
                            $existingRecipient->update([
                                'status' => 'Pending',
                                'received_at' => null,
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
                    'acknowledgements_reset' => $documentChanged,
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
        $storagePath = $contract->storage_path;
        DB::transaction(function () use ($request, $contract): void {
            $lockedContract = Contract::with('recipients')
                ->whereKey($contract->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->audit($request, 'contract.deleted', $lockedContract->id, [
                'title' => $lockedContract->title,
                'filename' => $lockedContract->original_filename,
                'recipients' => $lockedContract->recipients->map(fn (ContractRecipient $recipient): array => [
                    'user_id' => $recipient->user_id,
                    'status' => $recipient->status,
                    'received_at' => $recipient->received_at?->toISOString(),
                ])->values()->all(),
            ]);
            $lockedContract->delete();
        });

        if (! Storage::disk('local')->delete($storagePath)) {
            Log::error('The contract record was deleted but its private file could not be removed.', [
                'storage_path' => $storagePath,
                'contract_id' => $contract->id,
            ]);

            return back()->with('warning', 'The contract was deleted, but its stored file could not be removed. Contact an administrator.');
        }

        return back()->with('status', 'Contract deleted successfully.');
    }

    public function preview(Request $request, Contract $contract, ContractDocumentService $documents): BinaryFileResponse|Response
    {
        $this->authorizeAccess($request, $contract);
        if ($request->boolean('original') && $contract->original_pdf_path) {
            $originalPdfPath = Storage::disk('local')->path($contract->original_pdf_path);
            if (is_file($originalPdfPath)) {
                return response()->file($originalPdfPath, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }
        }
        if ($contract->content_html !== null) {
            $recipient = $contract->recipients()->where('user_id', $request->user()->id)->exists()
                ? $request->user()
                : null;
            $html = $documents->personalizeHtml($contract->content_html, $recipient);

            return response($documents->renderHtmlPreview($html), 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }
        abort_unless(in_array($contract->mime_type, self::PREVIEWABLE_MIME_TYPES, true), 415);

        $path = Storage::disk('local')->path($contract->storage_path);
        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => $contract->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function download(Request $request, Contract $contract, ContractDocumentService $documents): StreamedResponse
    {
        $this->authorizeAccess($request, $contract);
        if ($contract->original_pdf_path && $request->boolean('original')) {
            return Storage::disk('local')->download(
                $contract->original_pdf_path,
                $contract->title.'-original.pdf',
                ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff'],
            );
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

            return response()->streamDownload(
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
        }

        return Storage::disk('local')->download($contract->storage_path, $contract->original_filename, [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function markReceived(Request $request, Contract $contract): RedirectResponse
    {
        DB::transaction(function () use ($request, $contract): void {
            $recipient = ContractRecipient::where('contract_id', $contract->id)
                ->where('user_id', $request->user()->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($recipient->status === 'Pending') {
                $recipient->update([
                    'status' => 'Received',
                    'received_at' => now(),
                ]);
                $this->audit($request, 'contract.received', $contract->id);
            }
        });

        return back()->with('status', 'Contract marked as received.');
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
