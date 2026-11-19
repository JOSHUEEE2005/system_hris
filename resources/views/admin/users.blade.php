<x-admin-layout title="Users">
    <section class="sa-head"><h1>SUPER ADMIN</h1><strong>Hi, {{ auth()->user()->name }}</strong></section>

    @if (session('status'))
        <p class="mt-4 rounded bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <div class="mt-4 rounded bg-red-50 p-3 text-sm text-red-800">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
    @endif

    @php
        $userDepartment = fn ($user) => $user->department?->department_name ?? $user->employee?->department?->department_name;
        $directoryGroups = [
            'Chamber Services' => $users->filter(fn ($user) => $user->status === 'Active' && $userDepartment($user) === 'Chamber Services'),
            'Dual Training' => $users->filter(fn ($user) => $user->status === 'Active' && $userDepartment($user) === 'Dual Training'),
            'Government Affairs' => $users->filter(fn ($user) => $user->status === 'Active' && $userDepartment($user) === 'Government Affairs'),
            'Trade Promotions' => $users->filter(fn ($user) => $user->status === 'Active' && $userDepartment($user) === 'Trade Promotions'),
            'Communications' => $users->filter(fn ($user) => $user->status === 'Active' && in_array($userDepartment($user), ['Communications', 'Comms'], true)),
            'Internal Org' => $users->filter(fn ($user) => $user->status === 'Active' && in_array($userDepartment($user), ['Internal Org', 'Internal Organization'], true)),
            'Interns' => $users->filter(fn ($user) => $user->status === 'Active' && ($userDepartment($user) === 'Interns' || $user->employee?->employment_type === 'Internship')),
            'Pending Applicants' => $users->where('status', 'Pending'),
        ];
        $directoryTotal = $users->count();
    @endphp

    <div class="sa-user-layout">
        <article class="sa-card">
            <div class="mb-4 flex items-end justify-between gap-3">
                <div><h2 class="sa-section-title mb-1">User Directories Total ({{ $directoryTotal }})</h2><p class="sa-muted">Browse all employees by department and employment type.</p></div>
            </div>
            <div class="sa-directory-toolbar">
                <input id="directory-search" aria-label="Search users" placeholder="Search by name, ID, or email">
                <select id="directory-department" aria-label="Filter department"><option value="">All departments</option>@foreach($departments as $department)<option value="{{ $department->department_name }}">{{ $department->department_name }}</option>@endforeach</select>
                <select id="directory-role" aria-label="Filter role"><option value="">All roles</option>@foreach($roles as $role)<option value="{{ $role->name }}">{{ $role->name }}</option>@endforeach</select>
                <select id="directory-status" aria-label="Filter status"><option value="">All statuses</option><option>Active</option><option>Pending</option><option>Disabled</option></select>
            </div>
            <div class="sa-directory-groups">
                @foreach($directoryGroups as $groupName => $groupUsers)
                    @if($groupUsers->isNotEmpty())
                        <section class="sa-directory-group" data-directory-group>
                            <div class="sa-group-title"><span>⌄ {{ $groupName }}</span><span>{{ $groupUsers->count() }} {{ $groupUsers->count() === 1 ? 'user' : 'users' }}</span></div>
                            <table class="sa-table"><tbody>
                                @foreach($groupUsers as $record)
                                    <tr data-directory-row data-search="{{ strtolower($record->name.' '.$record->email.' '.($record->employee?->employee_no ?? '').' '.($userDepartment($record) ?? '')) }}" data-department="{{ $userDepartment($record) ?? 'Pending Applicants' }}" data-role="{{ $record->roles->pluck('name')->implode(',') }}" data-status="{{ $record->status }}"><td><strong>{{ $record->name }}</strong><br><span class="sa-muted">{{ $record->email }}</span></td><td>{{ $userDepartment($record) ?? 'Pending Applicants' }}</td><td>{{ $record->employee?->employee_no ?? '—' }}</td><td><span class="sa-badge {{ strtolower($record->status) }}">{{ $record->status }}</span></td></tr>
                                @endforeach
                            </tbody></table>
                        </section>
                    @endif
                @endforeach
            </div>
            <p id="directory-empty" class="sa-directory-empty" hidden>No users match the selected filters.</p>
        </article>

        <aside class="sa-user-aside">
            @if ($canManageEmployees && $canManageUsers)
                <button id="open-user-modal" class="sa-card flex items-center justify-center gap-2 text-center font-bold text-[#0d5b88] hover:bg-slate-50" type="button">♟ <span>Add new User +</span></button>
            @endif
            <article class="sa-card"><h2 class="sa-section-title">Pending Applicants</h2><ul class="sa-list">@forelse($users->where('status','Pending')->take(5) as $user)<li class="flex justify-between gap-2"><span>{{ $user->name }}</span><strong class="text-amber-600">(review)</strong></li>@empty<li>No pending applicants.</li>@endforelse</ul></article>
            <article class="sa-card"><h2 class="sa-section-title">Status Report</h2><div class="mx-auto my-3 h-24 w-24 rounded-full" style="background: conic-gradient(#1760df 0 65%, #22a8f3 65% 82%, #9d54d8 82% 100%);"></div><ul class="sa-list"><li class="flex justify-between">Active Users <strong>{{ $users->where('status','Active')->count() }}</strong></li><li class="flex justify-between">Pending <strong>{{ $users->where('status','Pending')->count() }}</strong></li><li class="flex justify-between">Disabled <strong>{{ $users->where('status','Disabled')->count() }}</strong></li></ul></article>
        </aside>
    </div>
    @if ($canManageEmployees && $canManageUsers)
        <div id="user-modal" class="sa-modal" @if (!$errors->any()) hidden @endif>
            <div class="sa-modal-backdrop" data-close-user-modal></div>
            <section class="sa-modal-panel" role="dialog" aria-modal="true" aria-labelledby="user-modal-title" aria-describedby="user-modal-description">
                <div class="sa-modal-header">
                    <div><span class="sa-modal-eyebrow">USER DIRECTORY</span><h2 id="user-modal-title">Add a new user</h2><p id="user-modal-description">Create an employee profile and its sign-in account together.</p></div>
                    <button class="sa-modal-close" type="button" data-close-user-modal aria-label="Close">×</button>
                </div>
                <form class="sa-modal-form" method="POST" action="{{ route('admin.users.with-employee.store') }}">
                    @csrf
                    @if ($errors->any())
                        <div class="sa-modal-error" role="alert">
                            <strong>Please review the following:</strong>
                            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                        </div>
                    @endif
                    <section class="sa-modal-section" aria-labelledby="employee-details-heading">
                        <div class="sa-modal-section-heading"><span>01</span><div><h3 id="employee-details-heading">Employee details</h3><p>Profile information saved to the employee record.</p></div></div>
                        <div class="sa-modal-grid">
                            <div class="sa-field"><label for="modal-employee-name">Employee name <span>*</span></label><input id="modal-employee-name" name="employee_name" value="{{ old('employee_name') }}" autocomplete="name" maxlength="200" required></div>
                            <div class="sa-field"><label for="modal-designation">Designation <span>*</span></label><input id="modal-designation" name="designation" value="{{ old('designation') }}" maxlength="100" required></div>
                            <div class="sa-field"><label for="modal-department">Department <span>*</span></label><select id="modal-department" name="department_id" required><option value="">Select department</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(old('department_id') == $department->id)>{{ $department->department_name }}</option>@endforeach</select></div>
                            <div class="sa-field"><label for="modal-starting-date">Starting date <span>*</span></label><input id="modal-starting-date" type="date" name="starting_date" value="{{ old('starting_date') }}" required></div>
                            <div class="sa-field"><label for="modal-period">Period <span class="sa-field-hint">(required for interns)</span></label><input id="modal-period" name="period" value="{{ old('period') }}" maxlength="100"><small>Enter the internship duration or term.</small></div>
                            <div class="sa-field"><label for="modal-id-number">Employee ID <span>*</span></label><input id="modal-id-number" name="id_number" value="{{ old('id_number') }}" maxlength="20" required></div>
                            <div class="sa-field"><label for="modal-preparer">Prepared by <span>*</span></label><input id="modal-preparer" name="preparer" value="{{ old('preparer') }}" maxlength="150" required></div>
                            <div class="sa-field"><label for="modal-assigned-it">Assigned IT <span>*</span></label><input id="modal-assigned-it" name="assigned_it" value="{{ old('assigned_it') }}" maxlength="150" required></div>
                            <div class="sa-field"><label for="modal-last-date">Last date</label><input id="modal-last-date" type="date" name="last_date" value="{{ old('last_date') }}"><small>Leave blank if the employee is still active.</small></div>
                            <div class="sa-field"><label for="modal-employment-type">Employment type <span>*</span></label><select id="modal-employment-type" name="employment_type" required><option value="Employment" @selected(old('employment_type', 'Employment') === 'Employment')>Employment</option><option value="Internship" @selected(old('employment_type') === 'Internship')>Internship</option></select></div>
                            <div class="sa-field"><label for="modal-employment-status">Employment status <span>*</span></label><select id="modal-employment-status" name="employment_status" required><option value="Employee" @selected(old('employment_status', 'Employee') === 'Employee')>Employee</option><option value="Intern" @selected(old('employment_status') === 'Intern')>Intern</option></select></div>
                        </div>
                    </section>
                    <section class="sa-modal-section sa-account-section" aria-labelledby="account-details-heading">
                        <div class="sa-modal-section-heading"><span>02</span><div><h3 id="account-details-heading">Account access</h3><p>These details are saved to the user account.</p></div></div>
                        <div class="sa-modal-grid">
                            <div class="sa-field"><label for="modal-email">Work email <span>*</span></label><input id="modal-email" type="email" name="email" value="{{ old('email') }}" autocomplete="email" maxlength="150" required><small>Used as the sign-in address.</small></div>
                            <div class="sa-field"><label for="modal-role">Access role <span>*</span></label><select id="modal-role" name="role_id" required><option value="">Select role</option>@foreach($roles as $role)<option value="{{ $role->id }}" @selected(old('role_id', $roles->firstWhere('slug', 'employee')?->id) == $role->id)>{{ $role->name }}</option>@endforeach</select></div>
                            <div class="sa-field"><label for="modal-password">Initial password <span>*</span></label><input id="modal-password" type="password" name="password" autocomplete="new-password" minlength="12" required><small>Use at least 12 characters.</small></div>
                            <div class="sa-field"><label for="modal-password-confirmation">Confirm password <span>*</span></label><input id="modal-password-confirmation" type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required></div>
                        </div>
                    </section>
                    <div class="sa-modal-actions"><button class="sa-modal-secondary" type="button" data-close-user-modal>Cancel</button><button class="sa-primary" type="submit">Create user</button></div>
                </form>
            </section>
        </div>
    @endif
    <style>
        .sa-modal{position:fixed;inset:0;z-index:50;display:grid;place-items:center;padding:20px}.sa-modal[hidden]{display:none}.sa-modal-backdrop{position:absolute;inset:0;background:rgba(8,31,50,.64);backdrop-filter:blur(3px)}.sa-modal-panel{position:relative;width:min(880px,100%);max-height:calc(100vh - 40px);overflow:auto;border:1px solid #dce6ee;border-radius:16px;background:#fff;box-shadow:0 28px 80px rgba(8,31,50,.3)}.sa-modal-header{position:sticky;top:0;z-index:1;display:flex;justify-content:space-between;gap:18px;padding:22px 28px;background:linear-gradient(120deg,#0d5b88,#1676a8);color:#fff}.sa-modal-eyebrow{display:block;margin-bottom:5px;color:#c5e8fa;font-size:10px;font-weight:800;letter-spacing:.14em}.sa-modal-header h2{margin:0;color:#fff;font-size:22px;font-weight:800;letter-spacing:-.02em}.sa-modal-header p{margin:5px 0 0;color:#e0f2fc;font-size:13px}.sa-modal-close{display:grid;width:36px;height:36px;flex:none;place-items:center;border:1px solid #ffffff55;border-radius:9px;background:#ffffff12;color:#fff;font-size:25px;line-height:1;cursor:pointer}.sa-modal-close:hover{background:#ffffff28}.sa-modal-form{padding:22px 28px 26px}.sa-modal-section+.sa-modal-section{margin-top:24px;padding-top:22px;border-top:1px solid #e8eef3}.sa-modal-section-heading{display:flex;align-items:center;gap:11px;margin-bottom:16px}.sa-modal-section-heading>span{display:grid;width:30px;height:30px;flex:none;place-items:center;border-radius:9px;background:#e8f4fa;color:#0d5b88;font-size:11px;font-weight:800}.sa-modal-section-heading h3{margin:0;color:#173c56;font-size:14px;font-weight:800}.sa-modal-section-heading p{margin:2px 0 0;color:#71869a;font-size:11px}.sa-modal-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 18px}.sa-field{display:flex;min-width:0;flex-direction:column;gap:6px}.sa-field label{color:#294c65;font-size:12px;font-weight:700}.sa-field label span{color:#d25555}.sa-field-hint,.sa-field small{color:#8294a3;font-size:10px;font-weight:400}.sa-field input,.sa-field select{width:100%;min-height:40px;border:1px solid #ccd8e1;border-radius:8px;background:#fff;padding:9px 11px;color:#173c56;font-size:13px;outline:none;transition:border-color .15s,box-shadow .15s}.sa-field input:focus,.sa-field select:focus{border-color:#1681b4;box-shadow:0 0 0 3px #1681b41c}.sa-account-section{border-top:1px solid #e8eef3}.sa-modal-error{margin-bottom:20px;border:1px solid #fecaca;border-radius:9px;background:#fef2f2;padding:12px 14px;color:#991b1b;font-size:12px}.sa-modal-error ul{margin:5px 0 0;padding-left:18px}.sa-modal-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:24px;padding-top:18px;border-top:1px solid #e8eef3}.sa-modal-secondary,.sa-primary{min-height:40px;border-radius:8px;padding:9px 17px;font-size:12px;font-weight:800;cursor:pointer}.sa-modal-secondary{border:1px solid #cbd7e0;background:#fff;color:#35566e}.sa-modal-secondary:hover{background:#f5f8fa}.sa-primary{border:1px solid #0d5b88;background:#0d5b88;color:#fff;box-shadow:0 2px 5px #0d5b8830}.sa-primary:hover{background:#094a70}.sa-primary:disabled{opacity:.65;cursor:wait}@media(max-width:600px){.sa-modal{padding:10px}.sa-modal-panel{max-height:calc(100vh - 20px);border-radius:12px}.sa-modal-header{padding:18px}.sa-modal-form{padding:18px}.sa-modal-grid{grid-template-columns:1fr}.sa-modal-actions{position:sticky;bottom:-18px;margin:20px -18px -18px;padding:14px 18px;background:#fff}}
    </style>
    <script>
        (() => {
            const search = document.getElementById('directory-search');
            const department = document.getElementById('directory-department');
            const role = document.getElementById('directory-role');
            const status = document.getElementById('directory-status');
            const empty = document.getElementById('directory-empty');
            const groups = [...document.querySelectorAll('[data-directory-group]')];

            const filterDirectory = () => {
                const query = search.value.trim().toLowerCase();
                let visibleRows = 0;

                groups.forEach((group) => {
                    let groupRows = 0;
                    group.querySelectorAll('[data-directory-row]').forEach((row) => {
                        const matches = (!query || row.dataset.search.includes(query))
                            && (!department.value || row.dataset.department === department.value)
                            && (!role.value || row.dataset.role.split(',').includes(role.value))
                            && (!status.value || row.dataset.status === status.value);
                        row.hidden = !matches;
                        if (matches) groupRows++;
                    });
                    group.hidden = groupRows === 0;
                    visibleRows += groupRows;
                });

                empty.hidden = visibleRows !== 0;
            };

            [search, department, role, status].forEach((control) => control.addEventListener('input', filterDirectory));
            [department, role, status].forEach((control) => control.addEventListener('change', filterDirectory));

            const modal = document.getElementById('user-modal');
            const openModal = document.getElementById('open-user-modal');
            const employmentType = document.getElementById('modal-employment-type');
            const employmentStatus = document.getElementById('modal-employment-status');
            const period = document.getElementById('modal-period');
            const closeModal = () => {
                if (!modal) return;
                modal.hidden = true;
                document.body.style.overflow = '';
                openModal?.focus();
            };
            if (modal && openModal) {
                if (!modal.hidden) {
                    document.body.style.overflow = 'hidden';
                    modal.querySelector('input:not([type="hidden"])')?.focus();
                }
                openModal.addEventListener('click', () => {
                    modal.hidden = false;
                    document.body.style.overflow = 'hidden';
                    modal.querySelector('input:not([type="hidden"])')?.focus();
                });
                modal.querySelectorAll('[data-close-user-modal]').forEach((control) => control.addEventListener('click', closeModal));
                document.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape' && !modal.hidden) closeModal();
                });
                modal.querySelector('form')?.addEventListener('submit', (event) => {
                    if (!event.currentTarget.checkValidity()) return;
                    const submit = event.currentTarget.querySelector('button[type="submit"]');
                    submit.disabled = true;
                    submit.textContent = 'Creating…';
                });
            }
            if (employmentType && employmentStatus && period) {
                const updateEmploymentFields = () => {
                    const isInternship = employmentType.value === 'Internship';
                    employmentStatus.value = isInternship ? 'Intern' : 'Employee';
                    period.required = isInternship;
                };
                employmentType.addEventListener('change', updateEmploymentFields);
                updateEmploymentFields();
            }
        })();
    </script>
</x-admin-layout>
