<x-admin-layout title="Contracts">
    @php
        $previewableMimeTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    @endphp

    <section class="sa-head">
        <h1>{{ $canManageContracts ? 'CONTRACTS AND FILES' : 'MY CONTRACTS' }}</h1>
        <strong>Hi, {{ auth()->user()->name }}</strong>
    </section>

    @if (session('status'))
        <p class="contract-flash contract-flash-success" role="status">{{ session('status') }}</p>
    @endif
    @if (session('warning'))
        <p class="contract-flash contract-flash-warning" role="alert">{{ session('warning') }}</p>
    @endif
    @if ($errors->any())
        <div class="contract-flash contract-flash-error" role="alert">
            @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif

    <main class="contract-page {{ $canManageContracts ? 'contract-page-manager' : '' }}">
        <div class="contract-page-heading">
            <div>
                <h2>{{ $canManageContracts ? 'Contracts and files' : 'Contracts shared with you' }}</h2>
                <p>{{ $canManageContracts ? 'Create a contract or upload a file, then deliver it to a department or selected employees.' : 'Open or download contracts sent to you by HR. Your access is recorded automatically.' }}</p>
            </div>
        </div>

        @if ($canManageContracts)
            <div class="contract-toolbar">
                <label class="contract-search"><span aria-hidden="true">⌕</span><input id="contract-search" type="search" placeholder="Search contract or file name" aria-label="Search contracts"></label>
                <div class="contract-toolbar-actions">
                    <form class="contract-folder-create" method="POST" action="{{ route('admin.contracts.folders.store') }}">
                        @csrf
                        <label class="sr-only" for="contract-folder-name">New folder name</label>
                        <input id="contract-folder-name" name="name" value="{{ old('name') }}" maxlength="100" placeholder="New folder name" required>
                        <button class="contract-action-secondary" type="submit">＋ New Folder</button>
                    </form>
                    <button id="open-contract-modal" class="contract-primary-button" type="button">＋ <span>New Contract</span></button>
                </div>
            </div>
            @if ($errors->folder->any())
                <p class="contract-folder-error" role="alert">{{ $errors->folder->first('name') }}</p>
            @endif
            <nav class="contract-folder-filters" aria-label="Filter contracts by folder">
                <button class="contract-folder-filter" type="button" data-contract-folder-filter="all" aria-pressed="true">All contracts</button>
                <button class="contract-folder-filter" type="button" data-contract-folder-filter="unfiled" aria-pressed="false">Unfiled</button>
                @foreach ($folders as $folder)
                    <button class="contract-folder-filter" type="button" data-contract-folder-filter="{{ $folder->id }}" aria-pressed="false">{{ $folder->name }}</button>
                @endforeach
            </nav>
        @elseif ($personalFolders->isNotEmpty())
            <p class="contract-personal-folder-label">Personal folder: {{ auth()->user()->name }}</p>
        @endif

        <div class="{{ $canManageContracts ? 'contract-manager-browser' : '' }}">
            <div class="{{ $canManageContracts ? 'contract-manager-content' : '' }}">
                @if ($contracts->isNotEmpty())
                    <div class="contract-section-heading-row">
                        <h3 class="contract-section-heading">{{ $canManageContracts ? 'Recently sent' : 'Recently sent to you' }}</h3>
                        @if ($canManageContracts && $contracts->count() > 4)
                            <button class="contract-show-all" type="button" data-contract-show-all aria-expanded="false">View all {{ $contracts->count() }} contracts</button>
                        @endif
                    </div>
                @endif
                @if ($contracts->isEmpty())
                    <div class="contract-empty">
                        <span class="contract-empty-icon" aria-hidden="true">▤</span>
                        <h3>{{ $canManageContracts ? 'No contracts yet' : 'Nothing has been shared with you yet' }}</h3>
                        <p>{{ $canManageContracts ? 'Add a contract to make it available in the selected employees’ portal.' : 'When HR sends you a contract, it will appear here.' }}</p>
                    </div>
                @else
                    <div class="contract-grid {{ $canManageContracts ? 'contract-grid-recent' : '' }}" id="contract-grid" @if ($canManageContracts) data-contract-limit="4" @endif>
                        @foreach ($contracts as $contract)
                    @php
                        $isTemplate = $contract->content_html !== null;
                        $isPreviewable = $isTemplate || in_array($contract->mime_type, $previewableMimeTypes, true);
                        $isImage = str_starts_with($contract->mime_type, 'image/');
                        $recipient = $canManageContracts ? null : $contract->recipients->first();
                        $departmentName = $contract->department?->department_name;
                        $fileExtension = strtoupper(pathinfo($contract->original_filename, PATHINFO_EXTENSION) ?: 'FILE');
                    @endphp
                    <article
                        class="contract-card"
                        data-contract-card
                        data-contract-index="{{ $loop->index }}"
                        data-folder-id="{{ $contract->folder_id ?? '' }}"
                        data-recipient-user-ids="{{ $contract->recipients->pluck('user_id')->implode(',') }}"
                        data-search="{{ strtolower($contract->title.' '.$contract->original_filename.' '.($departmentName ?? '')) }}"
                    >
                        <div class="contract-preview">
                            @if ($canManageContracts && $isPreviewable && $isImage)
                                <img src="{{ route('admin.contracts.preview', $contract) }}" alt="Preview of {{ $contract->original_filename }}" loading="lazy">
                            @elseif ($canManageContracts && ($contract->mime_type === 'application/pdf' || $isTemplate))
                                <iframe src="{{ route('admin.contracts.preview', $contract) }}" title="Preview of {{ $contract->original_filename }}" loading="lazy"></iframe>
                            @else
                                <div class="contract-file-placeholder">
                                    <span>{{ $fileExtension }}</span>
                                    <strong>{{ $isPreviewable ? 'Preview available' : 'File preview' }}</strong>
                                    <small>{{ $isPreviewable ? 'Open preview to view this file' : 'Download to view this file type' }}</small>
                                </div>
                            @endif
                            @if ($isPreviewable)
                                <a class="contract-preview-link" href="{{ route('admin.contracts.preview', [$contract, 'track_open' => 1]) }}" target="_blank" rel="noopener">Open preview</a>
                            @endif
                        </div>
                        <div class="contract-card-body">
                            <div class="contract-title-row">
                                <div>
                                    <h3>{{ $contract->title }}</h3>
                                    <p class="contract-filename" title="{{ $contract->original_filename }}">{{ $contract->original_filename }}</p>
                                </div>
                                @if ($canManageContracts)
                                    <span class="contract-status-badge sent">Sent</span>
                                @else
                                    <span class="contract-status-badge {{ $recipient->opened_at ? 'opened' : 'pending' }}">{{ $recipient->opened_at ? 'Opened' : 'Not opened' }}</span>
                                @endif
                            </div>
                            @if ($canManageContracts && $contract->folder)
                                <span class="contract-folder-label">{{ $contract->folder->name }}</span>
                            @endif
                            <div class="contract-card-meta">
                                <span>{{ $contract->sent_at?->format('M j, Y') ?? $contract->created_at?->format('M j, Y') }}</span>
                                <span>{{ number_format($contract->file_size / 1024, 0) }} KB</span>
                            </div>
                            @if ($canManageContracts)
                                <p class="contract-audience">
                                    {{ $contract->recipient_type === 'department' ? 'Department: '.($departmentName ?? 'Department removed') : 'Selected employees' }}
                                    <span>{{ $contract->recipients_count }} {{ $contract->recipients_count === 1 ? 'recipient' : 'recipients' }}</span>
                                </p>
                                <div class="contract-receipt-track">
                                    <span>{{ $contract->opened_recipients_count }} opened</span>
                                    <span>{{ $contract->pending_recipients_count }} not opened</span>
                                </div>
                                <div class="contract-recipient-status-list">
                                    @foreach ($contract->recipients as $contractRecipient)
                                        <div class="contract-recipient-status" data-recipient-status-user="{{ $contractRecipient->user_id }}" hidden>
                                            <strong>{{ $contractRecipient->user?->name ?? 'Former user' }}</strong>
                                            <span class="{{ $contractRecipient->opened_at ? 'opened' : 'pending' }}">
                                                {{ $contractRecipient->opened_at ? 'Opened '.$contractRecipient->opened_at->format('M j, Y g:i A') : 'Not opened yet' }}
                                            </span>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <p class="contract-sender">Sent by {{ $contract->createdBy?->name ?? 'HR' }}</p>
                            @endif
                            <div class="contract-card-actions">
                                @if ($isPreviewable)
                                    <a class="contract-action-secondary" href="{{ route('admin.contracts.preview', [$contract, 'track_open' => 1]) }}" target="_blank" rel="noopener">Preview</a>
                                @endif
                                @if ($isTemplate)
                                    <a class="contract-action-primary" href="{{ route('admin.contracts.download', [$contract, 'format' => 'pdf']) }}">PDF</a>
                                    <a class="contract-action-secondary" href="{{ route('admin.contracts.download', [$contract, 'format' => 'docx']) }}">Word</a>
                                    @if ($contract->original_pdf_path)
                                        <a class="contract-action-secondary" href="{{ route('admin.contracts.download', [$contract, 'original' => 1]) }}">Original PDF</a>
                                        <a class="contract-action-secondary" href="{{ route('admin.contracts.preview', [$contract, 'original' => 1, 'track_open' => 1]) }}" target="_blank" rel="noopener">View original</a>
                                    @endif
                                @else
                                    <a class="contract-action-primary" href="{{ route('admin.contracts.download', $contract) }}">Download</a>
                                @endif
                                @if ($canManageContracts)
                                    @if (! $isTemplate && in_array(strtolower(pathinfo($contract->original_filename, PATHINFO_EXTENSION)), ['pdf', 'docx'], true))
                                        <form method="POST" action="{{ route('admin.contracts.convert-editor', $contract) }}">
                                            @csrf
                                            <button class="contract-action-secondary" type="submit">Convert & edit</button>
                                        </form>
                                    @endif
                                    @if ($isTemplate && $contract->original_pdf_path)
                                        <form method="POST" action="{{ route('admin.contracts.convert-editor', $contract) }}" onsubmit="return confirm('This replaces the current editable content with a fresh conversion of the original PDF and resets recipient opened statuses. Continue?')">
                                            @csrf
                                            <button class="contract-action-secondary" type="submit">Rebuild from Original</button>
                                        </form>
                                    @endif
                                    <button
                                        class="contract-action-secondary"
                                        type="button"
                                        data-contract-edit
                                        data-contract-id="{{ $contract->id }}"
                                        data-update-url="{{ route('admin.contracts.update', $contract) }}"
                                        data-delete-url="{{ route('admin.contracts.destroy', $contract) }}"
                                        data-title="{{ $contract->title }}"
                                        data-filename="{{ $contract->original_filename }}"
                                        data-recipient-type="{{ $contract->recipient_type }}"
                                        data-source-type="{{ $isTemplate ? 'editor' : 'upload' }}"
                                        data-content-html="{{ $contract->content_html }}"
                                        data-department-id="{{ $contract->department_id ?? '' }}"
                                        data-recipient-ids="{{ $contract->recipients->pluck('user_id')->implode(',') }}"
                                        data-folder-id="{{ $contract->folder_id ?? '' }}"
                                    >Edit</button>
                                @endif
                                @if (! $canManageContracts && $recipient->opened_at)
                                    <span class="contract-opened-date">Opened {{ $recipient->opened_at->format('M j, Y g:i A') }}</span>
                                @endif
                            </div>
                        </div>
                    </article>
                        @endforeach
                    </div>
                @endif
                @if ($canManageContracts)
                    <p id="contract-search-empty" class="contract-search-empty" hidden>No contracts match your search or folder selection.</p>
                @endif
            </div>

            @if ($canManageContracts)
                <section class="contract-personal-folders">
                    <h3>Employee contracts</h3>
                    <p>Select a recipient’s folder to see the contracts sent to them and their opening progress.</p>
                    <nav class="contract-personal-folder-list" aria-label="Personal contract folders">
                        <button class="contract-personal-folder-item contract-personal-folder-all" type="button" data-personal-folder-filter="" aria-pressed="true">
                            <span class="contract-personal-folder-name">All recipient folders</span>
                            <small>{{ $personalFolders->count() }} folders</small>
                        </button>
                        @foreach ($personalFolders as $folder)
                            @php
                                $totalContracts = $folder->user?->contract_recipients_count ?? 0;
                                $openedContracts = $folder->user?->opened_contract_recipients_count ?? 0;
                                $progress = $totalContracts > 0 ? (int) round($openedContracts / $totalContracts * 100) : 0;
                            @endphp
                            <button class="contract-personal-folder-item" type="button" data-personal-folder-filter="{{ $folder->user_id }}" aria-pressed="false">
                                <span class="contract-personal-folder-name">{{ $folder->user?->name ?? $folder->name }}</span>
                                <small>{{ $totalContracts }} {{ $totalContracts === 1 ? 'contract' : 'contracts' }}</small>
                                <span class="contract-personal-folder-progress" role="progressbar" aria-label="Contracts opened by {{ $folder->user?->name ?? $folder->name }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress }}" style="--progress: {{ $progress }}%">
                                    <span>{{ $progress }}%</span>
                                </span>
                                <small>{{ $openedContracts }} opened</small>
                            </button>
                        @endforeach
                    </nav>
                </section>
            @endif
        </div>
    </main>

    @if ($canManageContracts)
        <div id="contract-modal" class="contract-modal" @if (! $errors->any()) hidden @endif>
            <button class="contract-modal-backdrop" type="button" data-close-contract-modal aria-label="Close dialog"></button>
            <section class="contract-modal-panel" role="dialog" aria-modal="true" aria-labelledby="contract-modal-title" aria-describedby="contract-modal-description">
                <header class="contract-modal-header">
                    <div>
                        <span class="contract-modal-eyebrow">CONTRACT MANAGEMENT</span>
                        <h2 id="contract-modal-title">Send a new contract</h2>
                        <p id="contract-modal-description">Create a contract or upload a file and choose who should receive it.</p>
                    </div>
                    <button class="contract-modal-close" type="button" data-close-contract-modal aria-label="Close">×</button>
                </header>
                <form class="contract-form" method="POST" action="{{ route('admin.contracts.store') }}" data-store-url="{{ route('admin.contracts.store') }}" data-current-source="new" enctype="multipart/form-data">
                    @csrf
                    <input id="contract-method" type="hidden" name="_method" value="PUT" disabled>
                    <input id="contract-record-id" type="hidden" name="contract_id" value="{{ old('contract_id') }}">
                    <input id="contract-source" type="hidden" name="contract_source" value="{{ old('contract_source', 'upload') }}">
                    @if ($errors->any())
                        <div class="contract-form-errors" role="alert">
                            <strong>Please review the following:</strong>
                            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                        </div>
                    @endif
                    <div class="contract-form-field">
                        <label for="contract-title">Contract title <span>*</span></label>
                        <input id="contract-title" name="title" value="{{ old('title') }}" maxlength="200" placeholder="e.g. Employment agreement — 2026" required>
                    </div>
                    <div class="contract-form-field">
                        <label for="contract-folder">Folder</label>
                        <select id="contract-folder" name="folder_id">
                            <option value="">No folder</option>
                            @foreach ($folders as $folder)
                                <option value="{{ $folder->id }}" @selected(old('folder_id') == $folder->id)>{{ $folder->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <fieldset class="contract-document-mode">
                        <legend>Contract content</legend>
                        <label><input type="radio" name="contract_document_mode" value="upload" @checked(old('contract_source', 'upload') === 'upload')><span>Upload file</span></label>
                        <label><input type="radio" name="contract_document_mode" value="editor" @checked(old('contract_source') === 'editor')><span>Create contract</span></label>
                    </fieldset>
                    <div id="contract-upload-panel" class="contract-form-field">
                        <label id="contract-file-label" for="contract-file">Contract file <span>*</span></label>
                        <input id="contract-file" type="file" name="file" @required(old('contract_source', 'upload') === 'upload')>
                        <small id="contract-file-help">Any file type is accepted, up to 35 MB. PDF and common image files preview here; other types can be downloaded.</small>
                        <p id="contract-current-file" class="contract-current-file" hidden>Current file: <strong id="contract-current-filename"></strong></p>
                    </div>
                    <div id="contract-editor-panel" class="contract-form-field" hidden>
                        <label for="contract-editor">Write your contract <span>*</span></label>
                        <div class="contract-editor-toolbar" role="toolbar" aria-label="Contract formatting">
                            <button type="button" data-contract-command="bold" aria-label="Bold"><strong>B</strong></button>
                            <button type="button" data-contract-command="italic" aria-label="Italic"><em>I</em></button>
                            <button type="button" data-contract-command="underline" aria-label="Underline"><u>U</u></button>
                            <button type="button" data-contract-command="insertUnorderedList" aria-label="Bulleted list">• List</button>
                            <button type="button" data-contract-command="insertOrderedList" aria-label="Numbered list">1. List</button>
                            <label class="contract-table-size"><span>Rows</span><input id="contract-table-rows" type="number" min="1" max="10" value="3"></label>
                            <label class="contract-table-size"><span>Columns</span><input id="contract-table-columns" type="number" min="1" max="10" value="3"></label>
                            <button type="button" data-contract-insert-table>Insert table</button>
                            <button type="button" data-contract-insert-section="header">Add header</button>
                            <button type="button" data-contract-insert-section="footer">Add footer</button>
                            <select id="contract-editor-block" aria-label="Text style">
                                <option value="p">Paragraph</option>
                                <option value="h1">Heading 1</option>
                                <option value="h2">Heading 2</option>
                                <option value="h3">Heading 3</option>
                            </select>
                            <label for="contract-placeholder">Insert field</label>
                            <select id="contract-placeholder" aria-label="Insert personalized field">
                                <option value="">Choose field</option>
                                <option value="[User Full Name]">User Full Name</option>
                                <option value="[Work Email]">Work Email</option>
                                <option value="[Department]">Department</option>
                                <option value="[Employee ID]">Employee ID</option>
                                <option value="[Designation]">Designation / Position</option>
                                <option value="[Role]">Role</option>
                                <option value="[Status]">Status</option>
                            </select>
                        </div>
                        <div id="contract-editor" class="contract-editor" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Contract text">{!! $oldContractContentHtml !!}</div>
                        <textarea id="contract-content-html" name="content_html" hidden>{{ old('content_html') }}</textarea>
                        <small>Personalized fields are filled for each recipient. You can download the finished contract as PDF or Word.</small>
                    </div>
                    <fieldset class="contract-recipient-fieldset">
                        <legend>Send to <span>*</span></legend>
                        <div class="contract-recipient-modes">
                            <label><input type="radio" name="recipient_type" value="department" @checked(old('recipient_type', 'department') === 'department')><span><strong>Department</strong><small>Send to active users in one department</small></span></label>
                            <label><input type="radio" name="recipient_type" value="individual" @checked(old('recipient_type') === 'individual')><span><strong>Selected employees</strong><small>Choose one or more recipients</small></span></label>
                        </div>
                        <div id="contract-department-choice" class="contract-target-panel">
                            <label for="contract-department">Department</label>
                            <select id="contract-department" name="department_id">
                                <option value="">Select department</option>
                                @foreach ($departments as $department)
                                    <option value="{{ $department->id }}" @selected(old('department_id') == $department->id)>{{ $department->department_name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div id="contract-individual-choice" class="contract-target-panel" hidden>
                            @if ($recipients->isNotEmpty())
                                <div class="contract-form-field">
                                    <label for="contract-recipient-search">Search employees by name, email, or department</label>
                                    <input id="contract-recipient-search" type="search" placeholder="Search employees..." autocomplete="off">
                                </div>
                                <div class="contract-recipient-list">
                                    @foreach ($recipients as $recipient)
                                        @php
                                            $recipientDepartment = $recipient->department?->department_name
                                                ?? $recipient->employee?->department?->department_name
                                                ?? $recipient->intern?->department?->department_name;
                                            $oldRecipientIds = (array) old('recipient_ids', []);
                                        @endphp
                                        <label data-contract-recipient-option data-search="{{ strtolower($recipient->name.' '.$recipient->email.' '.($recipientDepartment ?? '')) }}">
                                            <input type="checkbox" name="recipient_ids[]" value="{{ $recipient->id }}" @checked(in_array($recipient->id, $oldRecipientIds))>
                                            <span><strong>{{ $recipient->name }}</strong><small>{{ $recipient->email }}{{ $recipientDepartment ? ' · '.$recipientDepartment : '' }}</small></span>
                                        </label>
                                    @endforeach
                                </div>
                                <p id="contract-recipient-search-empty" class="contract-no-recipients" hidden>No employees match your search.</p>
                            @else
                                <div class="contract-recipient-list">
                                    <p class="contract-no-recipients">No active employee accounts are available.</p>
                                </div>
                            @endif
                        </div>
                    </fieldset>
                    <p class="contract-private-note">The file is kept in private storage and will only be available to HR and the selected recipients.</p>
                    <div class="contract-modal-actions">
                        <button id="contract-delete-button" class="contract-delete-button" type="submit" form="contract-delete-form" hidden>Delete contract</button>
                        <button class="contract-cancel-button" type="button" data-close-contract-modal>Cancel</button>
                        <button id="contract-submit-button" class="contract-primary-button" type="submit">Upload and send</button>
                    </div>
                </form>
                <form id="contract-delete-form" method="POST" action="{{ route('admin.contracts') }}" hidden>
                    @csrf
                    @method('DELETE')
                </form>
            </section>
        </div>
    @endif

</x-admin-layout>
