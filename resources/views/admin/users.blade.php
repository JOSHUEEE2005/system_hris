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
            @if ($canManageEmployees)
                <button id="open-user-modal" class="sa-card flex items-center justify-center gap-2 text-center font-bold text-[#0d5b88] hover:bg-slate-50" type="button">♟ <span>Add new User +</span></button>
            @endif
            <article class="sa-card"><h2 class="sa-section-title">Pending Applicants</h2><ul class="sa-list">@forelse($users->where('status','Pending')->take(5) as $user)<li class="flex justify-between gap-2"><span>{{ $user->name }}</span><strong class="text-amber-600">(review)</strong></li>@empty<li>No pending applicants.</li>@endforelse</ul></article>
            <article class="sa-card"><h2 class="sa-section-title">Status Report</h2><div class="mx-auto my-3 h-24 w-24 rounded-full" style="background: conic-gradient(#1760df 0 65%, #22a8f3 65% 82%, #9d54d8 82% 100%);"></div><ul class="sa-list"><li class="flex justify-between">Active Users <strong>{{ $users->where('status','Active')->count() }}</strong></li><li class="flex justify-between">Pending <strong>{{ $users->where('status','Pending')->count() }}</strong></li><li class="flex justify-between">Disabled <strong>{{ $users->where('status','Disabled')->count() }}</strong></li></ul></article>
        </aside>
    </div>
    @if ($canManageEmployees)
        <div id="user-modal" class="sa-modal" hidden>
            <div class="sa-modal-backdrop" data-close-user-modal></div>
            <section class="sa-modal-panel" role="dialog" aria-modal="true" aria-labelledby="user-modal-title">
                <div class="sa-modal-header"><div><h2 id="user-modal-title">Add new User</h2><p>Complete the employee profile details.</p></div><button class="sa-modal-close" type="button" data-close-user-modal aria-label="Close">×</button></div>
                <form class="sa-modal-form" method="POST" action="{{ route('admin.employees.store') }}">
                    @csrf
                    <div class="sa-modal-grid">
                        <div class="sa-field"><label for="modal-employee-name">Employee Name</label><input id="modal-employee-name" name="employee_name" required></div>
                        <div class="sa-field"><label for="modal-designation">Designation</label><input id="modal-designation" name="designation" required></div>
                        <div class="sa-field"><label for="modal-department">Department</label><select id="modal-department" name="department" required><option value="">Select department</option>@foreach(['Chamber Services','Dual Training','Government Affairs','Trade Promotions','Communications','Internal Org','Interns'] as $department)<option>{{ $department }}</option>@endforeach</select></div>
                        <div class="sa-field"><label for="modal-starting-date">Starting Date</label><input id="modal-starting-date" type="date" name="starting_date" required></div>
                        <div class="sa-field"><label for="modal-period">Period <span class="font-normal">(Interns only)</span></label><input id="modal-period" name="period"></div>
                        <div class="sa-field"><label for="modal-id-number">ID Number</label><input id="modal-id-number" name="id_number" required></div>
                        <div class="sa-field"><label for="modal-preparer">Preparer</label><input id="modal-preparer" name="preparer" required></div>
                        <div class="sa-field"><label for="modal-assigned-it">Assigned IT</label><input id="modal-assigned-it" name="assigned_it" required></div>
                        <div class="sa-field"><label for="modal-last-date">Last Date</label><input id="modal-last-date" type="date" name="last_date"></div>
                        <div class="sa-field"><label for="modal-employment-status">Employment Status</label><select id="modal-employment-status" name="employment_status" required><option value="">Select status</option><option>Employee</option><option>Intern</option></select></div>
                        <div class="sa-field"><label for="modal-employment-type">Employment</label><select id="modal-employment-type" name="employment_type" required><option>Employment</option><option>Internship</option></select></div>
                    </div>
                    <div class="sa-modal-actions"><button class="sa-modal-secondary" type="button" data-close-user-modal>Cancel</button><button class="sa-primary" type="submit">Save User</button></div>
                </form>
            </section>
        </div>
    @endif
    <style>
        .sa-modal{position:fixed;inset:0;z-index:50;display:grid;place-items:center;padding:24px}.sa-modal[hidden]{display:none}.sa-modal-backdrop{position:absolute;inset:0;background:#0b2944aa}.sa-modal-panel{position:relative;width:min(760px,100%);max-height:calc(100vh - 48px);overflow:auto;border-radius:12px;background:#fff;box-shadow:0 20px 60px #082b4666}.sa-modal-header{display:flex;justify-content:space-between;gap:18px;padding:20px 24px;background:#0d5b88;color:#fff}.sa-modal-header h2{margin:0;font-size:20px}.sa-modal-header p{margin:4px 0 0;color:#d9edf9;font-size:12px}.sa-modal-close{border:0;background:transparent;color:#fff;font-size:28px;line-height:1;cursor:pointer}.sa-modal-form{padding:22px 24px}.sa-modal-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 16px}.sa-modal-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:22px}.sa-modal-secondary{min-height:38px;border:1px solid #b7c8d5;border-radius:7px;background:#fff;padding:8px 16px;color:#315d78;font-size:12px;font-weight:800;cursor:pointer}@media(max-width:600px){.sa-modal-grid{grid-template-columns:1fr}}
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
            const closeModal = () => { if (modal) modal.hidden = true; };
            if (modal && openModal) {
                openModal.addEventListener('click', () => { modal.hidden = false; });
                modal.querySelectorAll('[data-close-user-modal]').forEach((control) => control.addEventListener('click', closeModal));
            }
        })();
    </script>
</x-admin-layout>
