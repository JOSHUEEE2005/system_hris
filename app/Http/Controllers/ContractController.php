<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\ContractRecipient;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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

    public function index(Request $request): View
    {
        $viewer = $request->user();
        $canManageContracts = $viewer->hasPermission('contracts.manage');
        $contracts = Contract::with(['department', 'createdBy'])->latest('sent_at');

        if ($canManageContracts) {
            $contracts->withCount([
                'recipients',
                'recipients as pending_recipients_count' => fn (Builder $query) => $query->where('status', 'Pending'),
            ])->with('recipients.user.department', 'recipients.user.employee.department');
        } else {
            $contracts->whereHas('recipients', fn (Builder $query) => $query->where('user_id', $viewer->id))
                ->with(['recipients' => fn ($query) => $query->where('user_id', $viewer->id)]);
        }

        return view('admin.contracts', [
            'contracts' => $contracts->get(),
            'departments' => $canManageContracts
                ? Department::orderBy('department_name')->get()
                : collect(),
            'recipients' => $canManageContracts
                ? $this->recipientCandidateQuery()->with(['department', 'employee.department'])->orderBy('name')->get()
                : collect(),
            'canManageContracts' => $canManageContracts,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'file' => ['required', 'file', 'max:35840'],
            'recipient_type' => ['required', Rule::in(['department', 'individual'])],
            'department_id' => ['nullable', 'required_if:recipient_type,department', 'integer', 'exists:departments,id'],
            'recipient_ids' => ['required_if:recipient_type,individual', 'array', 'min:1'],
            'recipient_ids.*' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('status', 'Active'),
            ],
        ]);
        $recipientIds = $this->resolveRecipientIds($data);

        $file = $data['file'];
        $storagePath = $file->store('contracts', 'local');
        if ($storagePath === false) {
            return back()->withErrors(['file' => 'The contract file could not be stored.'])->withInput();
        }

        try {
            DB::transaction(function () use ($data, $file, $storagePath, $recipientIds, $request): void {
                $contract = Contract::create([
                    'title' => $data['title'],
                    'original_filename' => $file->getClientOriginalName(),
                    'storage_path' => $storagePath,
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'file_size' => $file->getSize(),
                    'recipient_type' => $data['recipient_type'],
                    'department_id' => $data['recipient_type'] === 'department' ? $data['department_id'] : null,
                    'created_by' => $request->user()->id,
                    'sent_at' => now(),
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

    public function update(Request $request, Contract $contract): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'file' => ['nullable', 'file', 'max:35840'],
            'recipient_type' => ['required', Rule::in(['department', 'individual'])],
            'department_id' => ['nullable', 'required_if:recipient_type,department', 'integer', 'exists:departments,id'],
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
        $file = $data['file'] ?? null;
        $newStoragePath = $file?->store('contracts', 'local');
        if ($file && $newStoragePath === false) {
            return back()->withErrors(['file' => 'The replacement file could not be stored.'])->withInput();
        }

        $oldStoragePath = null;
        try {
            DB::transaction(function () use ($data, $file, $newStoragePath, $recipientIds, $request, $contract, &$oldStoragePath): void {
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
                $departmentId = $data['recipient_type'] === 'department' ? $data['department_id'] : null;

                $lockedContract->update([
                    'title' => $data['title'],
                    'original_filename' => $file?->getClientOriginalName() ?? $lockedContract->original_filename,
                    'storage_path' => $newStoragePath ?? $lockedContract->storage_path,
                    'mime_type' => $file ? ($file->getMimeType() ?: 'application/octet-stream') : $lockedContract->mime_type,
                    'file_size' => $file?->getSize() ?? $lockedContract->file_size,
                    'recipient_type' => $data['recipient_type'],
                    'department_id' => $departmentId,
                ]);

                $lockedContract->recipients()->whereNotIn('user_id', $recipientIds)->delete();
                foreach ($recipientIds as $userId) {
                    $existingRecipient = $oldRecipientsById->get($userId);
                    if ($existingRecipient) {
                        if ($file) {
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
                        'replaced' => (bool) $file,
                        'previous_filename' => $file ? $oldFilename : null,
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
                    'recipient_ids' => [
                        'added' => array_values(array_diff($recipientIds, $oldRecipientIds)),
                        'removed' => array_values(array_diff($oldRecipientIds, $recipientIds)),
                        'current' => $recipientIds,
                    ],
                    'acknowledgements_reset' => (bool) $file,
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

        if ($file && $oldStoragePath && ! Storage::disk('local')->delete($oldStoragePath)) {
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

    public function preview(Request $request, Contract $contract): BinaryFileResponse
    {
        $this->authorizeAccess($request, $contract);
        abort_unless(in_array($contract->mime_type, self::PREVIEWABLE_MIME_TYPES, true), 415);

        $path = Storage::disk('local')->path($contract->storage_path);
        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => $contract->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function download(Request $request, Contract $contract): StreamedResponse
    {
        $this->authorizeAccess($request, $contract);

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
            ->where(fn (Builder $query) => $query->whereNotNull('employee_id')->orWhereNotNull('department_id'));

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
}
