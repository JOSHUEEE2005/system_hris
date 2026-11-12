<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HR Administration | GPCCI HRIS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-stone-50 text-slate-800">
<header class="flex items-center justify-between border-b bg-white px-6 py-4">
    <div><a href="{{ route('home') }}" class="font-bold">GPCCI HRIS</a><span class="ml-3 text-sm text-slate-500">Administration</span></div>
    <form method="POST" action="{{ route('logout') }}">@csrf<button class="text-sm underline">Sign out</button></form>
</header>
<main class="mx-auto max-w-7xl space-y-8 px-5 py-8">
    <h1 class="text-3xl font-bold">HR administration</h1>
    @if (session('status'))<p class="rounded bg-emerald-50 p-3 text-emerald-800">{{ session('status') }}</p>@endif
    @if ($errors->any())<div class="rounded bg-red-50 p-3 text-red-800"><ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    @if ($canReviewOnboarding)
        <section class="rounded-xl border bg-white p-6">
            <h2 class="text-xl font-bold">Pending registrations</h2>
            @forelse ($pending as $request)
                <div class="mt-5 border-t pt-5">
                    <p class="font-semibold">{{ $request->user->name }} <span class="font-normal text-slate-500">{{ $request->user->email }}</span></p>
                    <p class="text-sm text-slate-500">Contact: {{ $request->contact_number ?: 'Not provided' }}</p>
                    <p class="text-sm text-amber-700">Email was not verified during registration. Confirm the applicant’s identity and email through your normal HR process before approving.</p>
                    <form class="mt-3 grid gap-3 md:grid-cols-5" method="POST" action="{{ route('admin.onboarding.approve', $request) }}">
                        @csrf
                        <label class="flex items-center gap-2 text-sm text-amber-800 md:col-span-5">
                            <input type="checkbox" name="confirmed_identity" value="1" required>
                            I verified the applicant’s identity and email outside the HRIS.
                        </label>
                        <input class="rounded border-slate-300" name="employee_no" placeholder="Employee no. (if no existing record)" maxlength="20">
                        <select class="rounded border-slate-300" name="department_id"><option value="">No department</option>@foreach ($departments as $department)<option value="{{ $department->id }}">{{ $department->department_name }}</option>@endforeach</select>
                        <input class="rounded border-slate-300" name="position" placeholder="Position">
                        <input class="rounded border-slate-300" type="date" name="hire_date">
                        <input class="rounded border-slate-300" type="number" step="0.01" min="0" name="salary" placeholder="Salary">
                        <button class="rounded bg-emerald-700 px-4 py-2 text-white md:col-span-5 md:justify-self-start" type="submit">Approve and create employee record</button>
                    </form>
                    <p class="mt-2 text-xs text-slate-500">If the email matches an existing employee record, approval links it and ignores the employee number fields.</p>
                    <form class="mt-2" method="POST" action="{{ route('admin.onboarding.reject', $request) }}">
                        @csrf<button class="text-sm text-red-700 underline" type="submit">Reject registration</button>
                    </form>
                </div>
            @empty<p class="mt-3 text-sm text-slate-500">No pending registrations.</p>@endforelse
        </section>
    @endif

    @if ($canManageUsers)
        <section class="rounded-xl border bg-white p-6">
            <h2 class="text-xl font-bold">User accounts</h2>
            <form class="mt-4 grid gap-3 md:grid-cols-5" method="POST" action="{{ route('admin.users.store') }}">
                @csrf
                <input class="rounded border-slate-300" name="name" placeholder="Name" required>
                <input class="rounded border-slate-300" type="email" name="email" placeholder="Email" required>
                <input class="rounded border-slate-300" type="password" name="password" placeholder="Password (12+ chars)" required>
                <input class="rounded border-slate-300" type="password" name="password_confirmation" placeholder="Confirm password" required>
                <select class="rounded border-slate-300" name="role_id" required>@foreach ($roles as $role)<option value="{{ $role->id }}">{{ $role->name }}</option>@endforeach</select>
                <input type="hidden" name="status" value="Active">
                <button class="rounded bg-slate-900 px-4 py-2 text-white md:col-span-5 md:justify-self-start">Create account</button>
            </form>
            <div class="mt-5 space-y-3">
                @foreach ($users as $user)
                    <div class="grid gap-3 border-t pt-4 md:grid-cols-[1fr_2fr_auto] md:items-center">
                        <div><p class="font-semibold">{{ $user->name }}</p><p class="text-sm text-slate-500">{{ $user->email }} · {{ $user->employee?->employee_no ?? 'No employee link' }}</p></div>
                        <form class="flex flex-wrap gap-2" method="POST" action="{{ route('admin.users.update', $user) }}">
                            @csrf @method('PUT')
                            <select class="rounded border-slate-300" name="role_id">@foreach ($roles as $role)<option value="{{ $role->id }}" @selected($user->roles->contains('id', $role->id))>{{ $role->name }}</option>@endforeach</select>
                            <select class="rounded border-slate-300" name="status">@foreach (['Pending','Active','Disabled'] as $status)<option @selected($user->status === $status)>{{ $status }}</option>@endforeach</select>
                            <select class="rounded border-slate-300" name="employee_id"><option value="">No employee link</option>@foreach ($employees as $employee)<option value="{{ $employee->id }}" @selected($user->employee_id === $employee->id)>{{ $employee->employee_no }} — {{ $employee->first_name }} {{ $employee->last_name }}</option>@endforeach</select>
                            <button class="rounded border px-3 py-2">Save</button>
                        </form>
                        <form method="POST" action="{{ route('admin.users.destroy', $user) }}" onsubmit="return confirm('Delete this account?')">
                            @csrf @method('DELETE')<button class="text-sm text-red-700 underline">Delete</button>
                        </form>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if ($canManageRoles)
        <section class="rounded-xl border bg-white p-6">
            <h2 class="text-xl font-bold">Roles and permissions</h2>
            <form class="mt-4 grid gap-3 md:grid-cols-3" method="POST" action="{{ route('admin.roles.store') }}">
                @csrf
                <input class="rounded border-slate-300" name="name" placeholder="Role name" required>
                <input class="rounded border-slate-300" name="slug" placeholder="role-slug" required>
                <input class="rounded border-slate-300" name="description" placeholder="Description">
                <div class="flex flex-wrap gap-3 md:col-span-3">@foreach ($permissions as $permission)<label class="text-sm"><input type="checkbox" name="permission_ids[]" value="{{ $permission->id }}"> {{ $permission->permission_key }}</label>@endforeach</div>
                <button class="rounded bg-slate-900 px-4 py-2 text-white md:col-span-3 md:justify-self-start">Create role</button>
            </form>
            @foreach ($roles as $role)
                <form class="mt-4 border-t pt-4" method="POST" action="{{ route('admin.roles.update', $role) }}">
                    @csrf @method('PUT')
                    <div class="flex flex-wrap gap-2">
                        <input class="rounded border-slate-300" name="name" value="{{ $role->name }}" required>
                        <input class="rounded border-slate-300" name="slug" value="{{ $role->slug }}" required>
                        <input class="rounded border-slate-300" name="description" value="{{ $role->description }}">
                        <span class="self-center text-sm text-slate-500">{{ $role->users_count }} user(s)</span>
                    </div>
                    @if ($role->slug !== 'super-admin')
                        <div class="mt-3 flex flex-wrap gap-3">@foreach ($permissions as $permission)<label class="text-sm"><input type="checkbox" name="permission_ids[]" value="{{ $permission->id }}" @checked($role->permissions->contains('id', $permission->id))> {{ $permission->permission_key }}</label>@endforeach</div>
                        <button class="mt-3 rounded border px-3 py-2">Save role</button>
                    @else
                        <p class="mt-2 text-sm text-slate-500">Built-in role permissions are protected.</p>
                    @endif
                </form>
                @if (! in_array($role->slug, ['super-admin', 'employee', 'hr-manager'], true) && $role->users_count === 0)
                    <form class="mt-2" method="POST" action="{{ route('admin.roles.destroy', $role) }}">@csrf @method('DELETE')<button class="text-sm text-red-700 underline">Delete {{ $role->name }}</button></form>
                @endif
            @endforeach
        </section>
    @endif

    @if ($canManageDepartments)
        <section class="rounded-xl border bg-white p-6">
            <h2 class="text-xl font-bold">Departments</h2>
            <form class="mt-4 flex flex-wrap gap-2" method="POST" action="{{ route('admin.departments.store') }}">@csrf
                <input class="rounded border-slate-300" name="department_name" placeholder="Department name" required>
                <button class="rounded bg-slate-900 px-4 py-2 text-white">Add department</button>
            </form>
            @foreach ($departments as $department)
                <div class="mt-3 flex flex-wrap items-center gap-2 border-t pt-3">
                    <form class="flex gap-2" method="POST" action="{{ route('admin.departments.update', $department) }}">@csrf @method('PUT')
                        <input class="rounded border-slate-300" name="department_name" value="{{ $department->department_name }}" required>
                        <button class="rounded border px-3 py-2">Save</button>
                    </form>
                    <span class="text-sm text-slate-500">{{ $department->employees_count }} employees</span>
                    <form method="POST" action="{{ route('admin.departments.destroy', $department) }}">@csrf @method('DELETE')<button class="text-sm text-red-700 underline">Delete</button></form>
                </div>
            @endforeach
        </section>
    @endif

    @if ($canManageEmployees)
        <section class="rounded-xl border bg-white p-6">
            <h2 class="text-xl font-bold">Employee records</h2>
            <form class="mt-4 grid gap-3 md:grid-cols-4" method="POST" action="{{ route('admin.employees.store') }}">@csrf
                <input class="rounded border-slate-300" name="employee_no" placeholder="Employee no." required>
                <input class="rounded border-slate-300" name="first_name" placeholder="First name" required>
                <input class="rounded border-slate-300" name="last_name" placeholder="Last name" required>
                <input class="rounded border-slate-300" type="email" name="email" placeholder="Email">
                <input class="rounded border-slate-300" name="contact_number" placeholder="Contact number">
                <input class="rounded border-slate-300" name="address" placeholder="Address">
                <select class="rounded border-slate-300" name="gender"><option value="">Gender (optional)</option><option>Male</option><option>Female</option></select>
                <input class="rounded border-slate-300" name="position" placeholder="Position">
                <select class="rounded border-slate-300" name="department_id"><option value="">No department</option>@foreach ($departments as $department)<option value="{{ $department->id }}">{{ $department->department_name }}</option>@endforeach</select>
                <input class="rounded border-slate-300" type="date" name="hire_date">
                <input class="rounded border-slate-300" type="number" step="0.01" min="0" name="salary" placeholder="Salary">
                <select class="rounded border-slate-300" name="status"><option>Active</option><option>Inactive</option></select>
                <button class="rounded bg-slate-900 px-4 py-2 text-white md:col-span-4 md:justify-self-start">Add employee</button>
            </form>
            @foreach ($employees as $employee)
                <form class="mt-4 grid gap-2 border-t pt-4 md:grid-cols-4" method="POST" action="{{ route('admin.employees.update', $employee) }}">@csrf @method('PUT')
                    <input class="rounded border-slate-300" name="employee_no" value="{{ $employee->employee_no }}" required>
                    <input class="rounded border-slate-300" name="first_name" value="{{ $employee->first_name }}" required>
                    <input class="rounded border-slate-300" name="last_name" value="{{ $employee->last_name }}" required>
                    <input class="rounded border-slate-300" type="email" name="email" value="{{ $employee->email }}">
                    <input class="rounded border-slate-300" name="contact_number" value="{{ $employee->contact_number }}">
                    <input class="rounded border-slate-300" name="address" value="{{ $employee->address }}" placeholder="Address">
                    <select class="rounded border-slate-300" name="gender"><option value="">Gender</option><option @selected($employee->gender === 'Male')>Male</option><option @selected($employee->gender === 'Female')>Female</option></select>
                    <input class="rounded border-slate-300" name="position" value="{{ $employee->position }}">
                    <select class="rounded border-slate-300" name="department_id"><option value="">No department</option>@foreach ($departments as $department)<option value="{{ $department->id }}" @selected($employee->department_id === $department->id)>{{ $department->department_name }}</option>@endforeach</select>
                    <input class="rounded border-slate-300" type="date" name="hire_date" value="{{ $employee->hire_date?->format('Y-m-d') }}">
                    <input class="rounded border-slate-300" type="number" step="0.01" min="0" name="salary" value="{{ $employee->salary }}">
                    <select class="rounded border-slate-300" name="status"><option @selected($employee->status === 'Active')>Active</option><option @selected($employee->status === 'Inactive')>Inactive</option></select>
                    <button class="rounded border px-3 py-2">Save</button>
                    <span class="self-center text-sm text-slate-500">{{ $employee->employee_no }} · {{ $employee->department?->department_name ?? 'No department' }}</span>
                </form>
                <form method="POST" action="{{ route('admin.employees.destroy', $employee) }}">@csrf @method('DELETE')<button class="text-sm text-red-700 underline">Delete employee record</button></form>
            @endforeach
        </section>
    @endif

    @if ($canViewAudit)
        <section class="rounded-xl border bg-white p-6">
            <h2 class="text-xl font-bold">Recent audit history</h2>
            <div class="mt-4 space-y-2">
                @forelse ($auditLogs as $log)
                    <p class="border-t pt-2 text-sm">
                        <strong>{{ $log->event_key }}</strong> · {{ $log->entity_type }} {{ $log->entity_id }}
                        · {{ $log->actor?->name ?? 'System' }} · {{ $log->created_at->toDayDateTimeString() }}
                    </p>
                @empty
                    <p class="text-sm text-slate-500">No recorded actions yet.</p>
                @endforelse
            </div>
        </section>
    @endif
</main>
</body>
</html>
