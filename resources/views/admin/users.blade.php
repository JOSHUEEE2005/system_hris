<x-admin-layout title="Users">
    <section class="sa-head"><h1>SUPER ADMIN</h1><strong>Hi, {{ auth()->user()->name }}</strong></section>

    @if (session('status'))
        <p class="mt-4 rounded bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <div class="mt-4 rounded bg-red-50 p-3 text-sm text-red-800">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
    @endif

    @php
        $userDepartment = fn ($user) => $user->department?->department_name
            ?? $user->employee?->department?->department_name
            ?? $user->intern?->department?->department_name;
        $directoryGroups = [
            'Chamber Services' => $users->filter(fn ($user) => $user->status === 'Active' && $userDepartment($user) === 'Chamber Services'),
            'Dual Training' => $users->filter(fn ($user) => $user->status === 'Active' && $userDepartment($user) === 'Dual Training'),
            'Government Affairs' => $users->filter(fn ($user) => $user->status === 'Active' && $userDepartment($user) === 'Government Affairs'),
            'Trade Promotions' => $users->filter(fn ($user) => $user->status === 'Active' && $userDepartment($user) === 'Trade Promotions'),
            'Communications' => $users->filter(fn ($user) => $user->status === 'Active' && in_array($userDepartment($user), ['Communications', 'Comms'], true)),
            'Internal Org' => $users->filter(fn ($user) => $user->status === 'Active' && in_array($userDepartment($user), ['Internal Org', 'Internal Organization'], true)),
            'Unassigned Interns' => $users->filter(fn ($user) => $user->status === 'Active'
                && $user->intern !== null
                && $user->intern->department_id === null),
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
                                    @php
                                        $editProfileData = [
                                            'profile_type' => $record->intern ? 'intern' : 'employee',
                                            'email' => $record->email,
                                            'role_id' => $record->roles->first()?->id,
                                            'status' => $record->status,
                                            'department_id' => $record->intern
                                                ? $record->intern->department_id
                                                : ($record->employee?->department_id ?? $record->department_id),
                                        ];
                                        if ($record->intern) {
                                            $editProfileData += [
                                                'intern_no' => $record->intern->intern_no,
                                                'intern_name' => $record->intern->intern_name,
                                                'designation' => $record->intern->designation,
                                                'birth_date' => $record->intern->birth_date?->format('Y-m-d'),
                                                'internship_start_date' => $record->intern->internship_start_date?->format('Y-m-d'),
                                                'internship_end_date' => $record->intern->internship_end_date?->format('Y-m-d'),
                                                'gender' => $record->intern->gender,
                                                'position' => $record->intern->position,
                                                'immediate_supervisor' => $record->intern->immediate_supervisor,
                                                'personal_email' => $record->intern->personal_email,
                                                'intern_address' => $record->intern->address,
                                                'intern_contact_number' => $record->intern->contact_number,
                                                'university' => $record->intern->university,
                                                'course' => $record->intern->course,
                                                'degree_expected' => $record->intern->degree_expected,
                                                'internship_status' => $record->intern->internship_status,
                                            ];
                                        } elseif ($record->employee) {
                                            $editProfileData += [
                                                'employee_no' => $record->employee->employee_no,
                                                'first_name' => $record->employee->first_name,
                                                'middle_name' => $record->employee->middle_name,
                                                'last_name' => $record->employee->last_name,
                                                'nationality' => $record->employee->nationality,
                                                'gender' => $record->employee->gender,
                                                'position' => $record->employee->position,
                                                'immediate_supervisor' => $record->employee->immediate_supervisor,
                                                'personal_email' => $record->employee->personal_email,
                                                'birth_date' => $record->employee->birth_date?->format('Y-m-d'),
                                                'hire_date' => $record->employee->hire_date?->format('Y-m-d'),
                                                'regularized_date' => $record->employee->regularized_date?->format('Y-m-d'),
                                                'employment_status' => $record->employee->employment_status,
                                                'last_date' => $record->employee->last_date?->format('Y-m-d'),
                                                'sss_number' => $record->employee->sss_number,
                                                'tin_number' => $record->employee->tin_number,
                                                'hdmf_number' => $record->employee->hdmf_number,
                                                'philhealth_number' => $record->employee->philhealth_number,
                                                'address' => $record->employee->address,
                                                'contact_number' => $record->employee->contact_number,
                                                'place_of_birth' => $record->employee->place_of_birth,
                                                'emergency_contact_person' => $record->employee->emergency_contact_person,
                                                'emergency_contact_number' => $record->employee->emergency_contact_number,
                                                'hmo_status' => $record->employee->hmo_status,
                                                'ecu' => $record->employee->ecu,
                                                'mobile_subscription' => $record->employee->mobile_subscription,
                                                'gpcci_mobile_number' => $record->employee->gpcci_mobile_number,
                                                'sick_leave_days' => $record->employee->sick_leave_days,
                                                'birthday_leave_days' => $record->employee->birthday_leave_days,
                                                'vacation_leave_days' => $record->employee->vacation_leave_days,
                                                'business_card' => $record->employee->business_card ? '1' : '0',
                                            ];
                                        }
                                    @endphp
                                    <tr data-directory-row data-search="{{ strtolower($record->name.' '.$record->email.' '.($record->employee?->employee_no ?? $record->intern?->intern_no ?? '').' '.($userDepartment($record) ?? '')) }}" data-department="{{ $userDepartment($record) ?? 'Pending Applicants' }}" data-role="{{ $record->roles->pluck('name')->implode(',') }}" data-status="{{ $record->status }}">
                                        <td><strong>{{ $record->name }}</strong><br><span class="sa-muted">{{ $record->email }}</span></td>
                                        <td>{{ $userDepartment($record) ?? 'Pending Applicants' }}</td>
                                        <td>{{ $record->intern?->intern_no ?? $record->employee?->employee_no ?? '—' }}</td>
                                        <td><span class="sa-badge {{ strtolower($record->status) }}">{{ $record->status }}</span></td>
                                        <td>
                                            @if ($canManageUsers && $canManageEmployees && ($record->employee || $record->intern))
                                                <button class="sa-user-edit" type="button" data-edit-user
                                                    data-edit-user-id="{{ $record->id }}"
                                                    data-update-url="{{ route('admin.users.profile.update', $record) }}"
                                                    data-user="{{ json_encode($editProfileData, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}">
                                                    Edit
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
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
                    <div><span class="sa-modal-eyebrow">USER DIRECTORY</span><h2 id="user-modal-title" data-modal-title>Add a new user</h2><p id="user-modal-description" data-modal-description>Create an employee profile and its sign-in account together.</p></div>
                    <button class="sa-modal-close" type="button" data-close-user-modal aria-label="Close">×</button>
                </div>
                <form id="user-profile-form" class="sa-modal-form" method="POST" action="{{ route('admin.users.with-employee.store') }}">
                    @csrf
                    <input type="hidden" name="_method" value="PUT" data-edit-method disabled>
                    @if ($errors->any())
                        <div class="sa-modal-error" role="alert">
                            <strong>Please review the following:</strong>
                            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                        </div>
                    @endif
                    <fieldset class="sa-profile-type">
                        <legend>Profile type</legend>
                        <label><input type="radio" name="profile_type" value="employee" @checked(old('profile_type', 'employee') === 'employee')><span>Employee</span></label>
                        <label><input type="radio" name="profile_type" value="intern" @checked(old('profile_type') === 'intern')><span>Intern</span></label>
                    </fieldset>
                    <div class="sa-modal-grid sa-common-profile-fields">
                        <div class="sa-field"><label for="modal-department">Department <span>*</span></label><select id="modal-department" name="department_id" required><option value="">Select department</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(old('department_id') == $department->id)>{{ $department->department_name === 'Internal Org' ? 'Internal Organization' : $department->department_name }}</option>@endforeach</select></div>
                        <div class="sa-field"><label for="modal-email"><span data-email-label>Office email address</span> <span>*</span></label><input id="modal-email" type="email" name="email" value="{{ old('email') }}" autocomplete="email" maxlength="150" required><small>Used as the account sign-in email.</small></div>
                        <div class="sa-field"><label for="modal-gender">Gender</label><select id="modal-gender" name="gender"><option value="">Select gender</option><option value="Male" @selected(old('gender') === 'Male')>Male</option><option value="Female" @selected(old('gender') === 'Female')>Female</option></select></div>
                        <div class="sa-field"><label for="modal-position">Position <span>*</span></label><input id="modal-position" name="position" value="{{ old('position') }}" maxlength="100" required></div>
                        <div class="sa-field"><label for="modal-supervisor">HOD / immediate supervisor</label><input id="modal-supervisor" name="immediate_supervisor" value="{{ old('immediate_supervisor') }}" maxlength="200"></div>
                        <div class="sa-field"><label for="modal-personal-email">Personal email</label><input id="modal-personal-email" type="email" name="personal_email" value="{{ old('personal_email') }}" maxlength="150"></div>
                        <div class="sa-field"><label for="modal-birth-date"><span data-birth-label>Birth date</span></label><input id="modal-birth-date" type="date" name="birth_date" value="{{ old('birth_date') }}" data-birth-date></div>
                        <div class="sa-field sa-derived-field"><label>Age</label><output data-age-output>—</output></div>
                    </div>
                    <section class="sa-modal-section" data-profile-section="employee" aria-labelledby="employee-details-heading">
                        <div class="sa-modal-section-heading"><span>01</span><div><h3 id="employee-details-heading">Employee details</h3><p>Employee record, employment dates, benefits, and contact information.</p></div></div>
                        <div class="sa-modal-grid">
                            <div class="sa-field"><label for="modal-employee-no">Employee number <span>*</span></label><input id="modal-employee-no" name="employee_no" value="{{ old('employee_no') }}" maxlength="20" data-required="employee"></div>
                            <div class="sa-field"><label for="modal-last-name">Last name <span>*</span></label><input id="modal-last-name" name="last_name" value="{{ old('last_name') }}" maxlength="100" data-required="employee"></div>
                            <div class="sa-field"><label for="modal-first-name">First name <span>*</span></label><input id="modal-first-name" name="first_name" value="{{ old('first_name') }}" maxlength="100" data-required="employee"></div>
                            <div class="sa-field"><label for="modal-middle-name">Middle name</label><input id="modal-middle-name" name="middle_name" value="{{ old('middle_name') }}" maxlength="100"></div>
                            <div class="sa-field"><label for="modal-nationality">Nationality</label><input id="modal-nationality" name="nationality" value="{{ old('nationality') }}" maxlength="100"></div>
                            <div class="sa-field"><label for="modal-full-name">Full name</label><input id="modal-full-name" value="{{ old('full_name') }}" readonly data-full-name></div>
                            <div class="sa-field"><label for="modal-hire-date">Date hired <span>*</span></label><input id="modal-hire-date" type="date" name="hire_date" value="{{ old('hire_date') }}" data-required="employee" data-tenure-date></div>
                            <div class="sa-field"><label for="modal-regularized-date">Date regularized</label><input id="modal-regularized-date" type="date" name="regularized_date" value="{{ old('regularized_date') }}"></div>
                            <div class="sa-field sa-derived-field"><label>Tenure in months / years</label><output data-tenure-output>—</output></div>
                            <div class="sa-field"><label for="modal-employment-status">Employment status <span>*</span></label><select id="modal-employment-status" name="employment_status" data-required="employee"><option value="">Select status</option>@foreach(['Active', 'Resigned', 'Active OIO', 'Active project based', 'Active agency'] as $employmentStatus)<option value="{{ $employmentStatus }}" @selected(old('employment_status', 'Active') === $employmentStatus)>{{ $employmentStatus }}</option>@endforeach</select></div>
                            <div class="sa-field"><label for="modal-end-employment">End of employment</label><input id="modal-end-employment" type="date" name="last_date" value="{{ old('last_date') }}" data-tenure-end></div>
                            <div class="sa-field sa-derived-field"><label>Years with GPCCI</label><output data-years-output>—</output></div>
                            <div class="sa-field"><label for="modal-national-id-sss">SSS</label><input id="modal-national-id-sss" name="sss_number" value="{{ old('sss_number') }}" maxlength="50"></div>
                            <div class="sa-field"><label for="modal-national-id-tin">TIN</label><input id="modal-national-id-tin" name="tin_number" value="{{ old('tin_number') }}" maxlength="50"></div>
                            <div class="sa-field"><label for="modal-national-id-hdmf">HDMF</label><input id="modal-national-id-hdmf" name="hdmf_number" value="{{ old('hdmf_number') }}" maxlength="50"></div>
                            <div class="sa-field"><label for="modal-national-id-philhealth">PhilHealth</label><input id="modal-national-id-philhealth" name="philhealth_number" value="{{ old('philhealth_number') }}" maxlength="50"></div>
                            <div class="sa-field sa-field-wide"><label for="modal-employee-address">Address</label><input id="modal-employee-address" name="address" value="{{ old('address') }}" maxlength="10000"></div>
                            <div class="sa-field"><label for="modal-employee-contact">Contact number</label><input id="modal-employee-contact" name="contact_number" value="{{ old('contact_number') }}" maxlength="30"></div>
                            <div class="sa-field"><label for="modal-place-of-birth">Place of birth</label><input id="modal-place-of-birth" name="place_of_birth" value="{{ old('place_of_birth') }}" maxlength="255"></div>
                            <div class="sa-field"><label for="modal-emergency-person">Emergency contact person</label><input id="modal-emergency-person" name="emergency_contact_person" value="{{ old('emergency_contact_person') }}" maxlength="200"></div>
                            <div class="sa-field"><label for="modal-emergency-number">Emergency contact number</label><input id="modal-emergency-number" name="emergency_contact_number" value="{{ old('emergency_contact_number') }}" maxlength="30"></div>
                            <div class="sa-field"><label for="modal-hmo">HMO</label><select id="modal-hmo" name="hmo_status"><option value="">Select HMO status</option>@foreach(['Yes', 'No', 'N/A', 'Upon regularization'] as $hmoStatus)<option value="{{ $hmoStatus }}" @selected(old('hmo_status') === $hmoStatus)>{{ $hmoStatus }}</option>@endforeach</select></div>
                            <div class="sa-field"><label for="modal-ecu">ECU</label><input id="modal-ecu" name="ecu" value="{{ old('ecu') }}" maxlength="100"></div>
                            <div class="sa-field"><label for="modal-mobile-subscription">Mobile subscription</label><select id="modal-mobile-subscription" name="mobile_subscription"><option value="">Select subscription</option>@foreach(['Yes 1499 subs', 'Yes 1799 subs', 'Yes 799 subs'] as $subscription)<option value="{{ $subscription }}" @selected(old('mobile_subscription') === $subscription)>{{ $subscription }}</option>@endforeach</select></div>
                            <div class="sa-field"><label for="modal-gpcci-mobile">GPCCI-issued mobile number</label><input id="modal-gpcci-mobile" name="gpcci_mobile_number" value="{{ old('gpcci_mobile_number') }}" maxlength="30"></div>
                            <div class="sa-field"><label for="modal-sick-leave">Sick leave days / year</label><input id="modal-sick-leave" type="number" min="0" step="0.5" name="sick_leave_days" value="{{ old('sick_leave_days') }}"></div>
                            <div class="sa-field"><label for="modal-birthday-leave">Birthday leave days / year</label><input id="modal-birthday-leave" type="number" min="0" step="0.5" name="birthday_leave_days" value="{{ old('birthday_leave_days') }}"></div>
                            <div class="sa-field"><label for="modal-vacation-leave">Vacation leave days / year</label><input id="modal-vacation-leave" type="number" min="0" step="0.5" name="vacation_leave_days" value="{{ old('vacation_leave_days') }}"></div>
                            <div class="sa-field"><label for="modal-business-card">Business card</label><select id="modal-business-card" name="business_card"><option value="0" @selected(old('business_card', '0') === '0')>No</option><option value="1" @selected(old('business_card') === '1')>Yes</option></select></div>
                        </div>
                    </section>
                    <section class="sa-modal-section" data-profile-section="intern" aria-labelledby="intern-details-heading">
                        <div class="sa-modal-section-heading"><span>01</span><div><h3 id="intern-details-heading">Intern details</h3><p>Internship dates, education details, and contact information.</p></div></div>
                        <div class="sa-modal-grid">
                            <div class="sa-field"><label for="modal-intern-no">Intern number <span>*</span></label><input id="modal-intern-no" name="intern_no" value="{{ old('intern_no') }}" maxlength="30" data-required="intern"></div>
                            <div class="sa-field"><label for="modal-intern-name">Intern name <span>*</span></label><input id="modal-intern-name" name="intern_name" value="{{ old('intern_name') }}" maxlength="255" data-required="intern"></div>
                            <div class="sa-field"><label for="modal-intern-designation">Designation <span>*</span></label><input id="modal-intern-designation" name="designation" value="{{ old('designation') }}" maxlength="150" data-required="intern"></div>
                            <div class="sa-field"><label for="modal-intern-start">Start of internship <span>*</span></label><input id="modal-intern-start" type="date" name="internship_start_date" value="{{ old('internship_start_date') }}" data-required="intern" data-tenure-date></div>
                            <div class="sa-field"><label for="modal-intern-end">End of internship</label><input id="modal-intern-end" type="date" name="internship_end_date" value="{{ old('internship_end_date') }}" data-tenure-end></div>
                            <div class="sa-field sa-derived-field"><label>Tenure in months</label><output data-tenure-output>—</output></div>
                            <div class="sa-field"><label for="modal-intern-address">Address</label><input id="modal-intern-address" name="intern_address" value="{{ old('intern_address') }}" maxlength="10000"></div>
                            <div class="sa-field"><label for="modal-intern-contact">Contact number</label><input id="modal-intern-contact" name="intern_contact_number" value="{{ old('intern_contact_number') }}" maxlength="30"></div>
                            <div class="sa-field"><label for="modal-university">University</label><input id="modal-university" name="university" value="{{ old('university') }}" maxlength="200"></div>
                            <div class="sa-field"><label for="modal-course">Course</label><input id="modal-course" name="course" value="{{ old('course') }}" maxlength="200"></div>
                            <div class="sa-field"><label for="modal-degree-expected">Degree expected</label><input id="modal-degree-expected" name="degree_expected" value="{{ old('degree_expected') }}" maxlength="200"></div>
                            <div class="sa-field"><label for="modal-internship-status">Internship status / remarks <span>*</span></label><select id="modal-internship-status" name="internship_status" data-required="intern"><option value="">Select status</option>@foreach(['Finished', 'Pre terminated', 'Active', 'Cancelled'] as $internStatus)<option value="{{ $internStatus }}" @selected(old('internship_status', 'Active') === $internStatus)>{{ $internStatus }}</option>@endforeach</select></div>
                        </div>
                    </section>
                    <section class="sa-modal-section sa-account-section" aria-labelledby="account-details-heading">
                        <div class="sa-modal-section-heading"><span>02</span><div><h3 id="account-details-heading">Account access</h3><p>Create the employee or intern sign-in account.</p></div></div>
                        <div class="sa-modal-grid">
                            <div class="sa-field"><label for="modal-role">Access role <span>*</span></label><select id="modal-role" name="role_id" required><option value="">Select role</option>@foreach($roles as $role)<option value="{{ $role->id }}" @selected(old('role_id', $roles->firstWhere('slug', 'employee')?->id) == $role->id)>{{ $role->name }}</option>@endforeach</select></div>
                            <div class="sa-field sa-edit-only" data-edit-only hidden><label for="modal-user-status">Account status <span>*</span></label><select id="modal-user-status" name="status" disabled required><option>Pending</option><option>Active</option><option>Disabled</option></select></div>
                            <div class="sa-field sa-create-only" data-create-only><label for="modal-password">Initial password <span>*</span></label><input id="modal-password" type="password" name="password" autocomplete="new-password" minlength="12" required><small>Use at least 12 characters.</small></div>
                            <div class="sa-field sa-create-only" data-create-only><label for="modal-password-confirmation">Confirm password <span>*</span></label><input id="modal-password-confirmation" type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required></div>
                        </div>
                    </section>
                    <div class="sa-modal-actions"><button class="sa-modal-secondary" type="button" data-close-user-modal>Cancel</button><button class="sa-primary" type="submit" data-submit-label>Create user</button></div>
                </form>
            </section>
        </div>
    @endif
    <style>
        .sa-modal{position:fixed;inset:0;z-index:50;display:grid;place-items:center;padding:20px}.sa-modal[hidden]{display:none}.sa-modal-backdrop{position:absolute;inset:0;background:rgba(8,31,50,.64);backdrop-filter:blur(3px)}.sa-modal-panel{position:relative;width:min(1080px,100%);max-height:calc(100vh - 40px);overflow:auto;border:1px solid #dce6ee;border-radius:16px;background:#fff;box-shadow:0 28px 80px rgba(8,31,50,.3)}.sa-modal-header{position:sticky;top:0;z-index:1;display:flex;justify-content:space-between;gap:18px;padding:22px 28px;background:linear-gradient(120deg,#0d5b88,#1676a8);color:#fff}.sa-modal-eyebrow{display:block;margin-bottom:5px;color:#c5e8fa;font-size:10px;font-weight:800;letter-spacing:.14em}.sa-modal-header h2{margin:0;color:#fff;font-size:22px;font-weight:800;letter-spacing:-.02em}.sa-modal-header p{margin:5px 0 0;color:#e0f2fc;font-size:13px}.sa-modal-close{display:grid;width:36px;height:36px;flex:none;place-items:center;border:1px solid #ffffff55;border-radius:9px;background:#ffffff12;color:#fff;font-size:25px;line-height:1;cursor:pointer}.sa-modal-close:hover{background:#ffffff28}.sa-modal-form{padding:22px 28px 26px}.sa-profile-type{display:flex;gap:10px;margin:0 0 22px;padding:0;border:0}.sa-profile-type legend{padding:0 0 8px;color:#294c65;font-size:12px;font-weight:700}.sa-profile-type label{display:flex;min-width:120px;align-items:center;gap:8px;border:1px solid #ccd8e1;border-radius:8px;padding:10px 12px;color:#294c65;font-size:12px;font-weight:700;cursor:pointer}.sa-profile-type label:has(input:checked){border-color:#1681b4;background:#e8f4fa;color:#0d5b88}.sa-modal-section{margin-top:24px;padding-top:22px;border-top:1px solid #e8eef3}.sa-modal-section-heading{display:flex;align-items:center;gap:11px;margin-bottom:16px}.sa-modal-section-heading>span{display:grid;width:30px;height:30px;flex:none;place-items:center;border-radius:9px;background:#e8f4fa;color:#0d5b88;font-size:11px;font-weight:800}.sa-modal-section-heading h3{margin:0;color:#173c56;font-size:14px;font-weight:800}.sa-modal-section-heading p{margin:2px 0 0;color:#71869a;font-size:11px}.sa-modal-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 18px}.sa-field{display:flex;min-width:0;flex-direction:column;gap:6px}.sa-field-wide{grid-column:1/-1}.sa-field label{color:#294c65;font-size:12px;font-weight:700}.sa-field label span{color:#d25555}.sa-field small{color:#8294a3;font-size:10px;font-weight:400}.sa-field input,.sa-field select,.sa-derived-field output{width:100%;min-height:40px;border:1px solid #ccd8e1;border-radius:8px;background:#fff;padding:9px 11px;color:#173c56;font-size:13px;outline:none;transition:border-color .15s,box-shadow .15s}.sa-derived-field output{background:#f4f8fa;color:#49687d}.sa-field input:focus,.sa-field select:focus{border-color:#1681b4;box-shadow:0 0 0 3px #1681b41c}.sa-account-section{border-top:1px solid #e8eef3}.sa-modal-error{margin-bottom:20px;border:1px solid #fecaca;border-radius:9px;background:#fef2f2;padding:12px 14px;color:#991b1b;font-size:12px}.sa-modal-error ul{margin:5px 0 0;padding-left:18px}.sa-modal-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:24px;padding-top:18px;border-top:1px solid #e8eef3}.sa-modal-secondary,.sa-primary{min-height:40px;border-radius:8px;padding:9px 17px;font-size:12px;font-weight:800;cursor:pointer}.sa-modal-secondary{border:1px solid #cbd7e0;background:#fff;color:#35566e}.sa-modal-secondary:hover{background:#f5f8fa}.sa-primary{border:1px solid #0d5b88;background:#0d5b88;color:#fff;box-shadow:0 2px 5px #0d5b8830}.sa-primary:hover{background:#094a70}.sa-primary:disabled{opacity:.65;cursor:wait}@media(max-width:600px){.sa-modal{padding:10px}.sa-modal-panel{max-height:calc(100vh - 20px);border-radius:12px}.sa-modal-header{padding:18px}.sa-modal-form{padding:18px}.sa-modal-grid{grid-template-columns:1fr}.sa-field-wide{grid-column:auto}.sa-modal-actions{position:sticky;bottom:-18px;margin:20px -18px -18px;padding:14px 18px;background:#fff}}
        .sa-user-edit{border:1px solid #c6d9e6;border-radius:7px;background:#fff;padding:6px 11px;color:#0d5b88;font-size:11px;font-weight:800;cursor:pointer}.sa-user-edit:hover{border-color:#0d5b88;background:#eaf5fb}.sa-edit-only[hidden]{display:none}.sa-profile-type input:disabled+span{cursor:default}
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
            const form = document.getElementById('user-profile-form');
            const profileTypeInputs = [...document.querySelectorAll('input[name="profile_type"]')];
            const profileSections = [...document.querySelectorAll('[data-profile-section]')];
            const createOnlyFields = [...document.querySelectorAll('[data-create-only]')];
            const editOnlyFields = [...document.querySelectorAll('[data-edit-only]')];
            const editMethod = document.querySelector('[data-edit-method]');
            const modalTitle = document.querySelector('[data-modal-title]');
            const modalDescription = document.querySelector('[data-modal-description]');
            const submitLabel = document.querySelector('[data-submit-label]');
            const emailLabel = document.querySelector('[data-email-label]');
            const birthLabel = document.querySelector('[data-birth-label]');
            const firstName = document.getElementById('modal-first-name');
            const middleName = document.getElementById('modal-middle-name');
            const lastName = document.getElementById('modal-last-name');
            const fullName = document.querySelector('[data-full-name]');
            const birthDate = document.querySelector('[data-birth-date]');
            const ageOutput = document.querySelector('[data-age-output]');
            const createAction = form?.action || '';
            const oldInput = @json(old());
            const editUserIdOnLoad = @json(session('edit_user_id'));

            const selectedProfileType = () => profileTypeInputs.find((input) => input.checked)?.value || 'employee';
            const updateProfileType = () => {
                const profileType = selectedProfileType();
                profileSections.forEach((section) => {
                    const active = section.dataset.profileSection === profileType;
                    section.hidden = !active;
                    section.querySelectorAll('input, select, textarea').forEach((field) => {
                        field.disabled = !active;
                        field.required = active && field.dataset.required === profileType;
                    });
                });
                if (emailLabel) emailLabel.textContent = profileType === 'intern' ? 'Email address' : 'Office email address';
                if (birthLabel) birthLabel.textContent = profileType === 'intern' ? 'Birthday' : 'Birth date';
            };
            const updateFullName = () => {
                if (!(fullName instanceof HTMLInputElement)) return;
                fullName.value = [firstName?.value, middleName?.value, lastName?.value]
                    .map((part) => part?.trim() || '')
                    .filter(Boolean)
                    .join(' ');
            };
            const parseDate = (value) => {
                if (!value) return null;
                const [year, month, day] = value.split('-').map(Number);
                return new Date(year, month - 1, day);
            };
            const completeMonthsBetween = (start, end) => {
                let months = (end.getFullYear() - start.getFullYear()) * 12 + end.getMonth() - start.getMonth();
                if (end.getDate() < start.getDate()) months--;
                return Math.max(0, months);
            };
            const updateDateSummaries = () => {
                const birth = parseDate(birthDate?.value);
                if (birth && ageOutput) {
                    const today = new Date();
                    let age = today.getFullYear() - birth.getFullYear();
                    if (today.getMonth() < birth.getMonth()
                        || (today.getMonth() === birth.getMonth() && today.getDate() < birth.getDate())) age--;
                    ageOutput.textContent = age >= 0 ? String(age) : '—';
                } else if (ageOutput) {
                    ageOutput.textContent = '—';
                }

                const profileType = selectedProfileType();
                const startInput = document.querySelector(profileType === 'intern'
                    ? '[data-profile-section="intern"] [data-tenure-date]'
                    : '[data-profile-section="employee"] [data-tenure-date]');
                const endInput = document.querySelector(`[data-profile-section="${profileType}"] [data-tenure-end]`);
                const start = parseDate(startInput?.value);
                const end = endInput?.value
                    ? parseDate(endInput.value)
                    : new Date();
                const output = document.querySelector(`[data-profile-section="${profileType}"] [data-tenure-output]`);
                if (start && end && output) {
                    const months = completeMonthsBetween(start, end);
                    output.textContent = profileType === 'intern'
                        ? `${months} months`
                        : `${months} months (${(months / 12).toFixed(1)} years)`;
                } else if (output) {
                    output.textContent = '—';
                }

                const yearsOutput = document.querySelector('[data-years-output]');
                const employeeHireDate = parseDate(document.querySelector('[data-profile-section="employee"] [data-tenure-date]')?.value);
                const employeeEndDate = parseDate(document.querySelector('[data-profile-section="employee"] [data-tenure-end]')?.value);
                if (yearsOutput && employeeHireDate) {
                    yearsOutput.textContent = `${(completeMonthsBetween(employeeHireDate, employeeEndDate || new Date()) / 12).toFixed(1)} years`;
                } else if (yearsOutput) {
                    yearsOutput.textContent = '—';
                }
            };
            const closeModal = () => {
                if (!modal) return;
                modal.hidden = true;
                document.body.style.overflow = '';
                openModal?.focus();
            };
            const setFieldValue = (name, value) => {
                const field = form?.elements.namedItem(name);
                if (field instanceof HTMLInputElement || field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement) {
                    field.value = value === null || value === undefined ? '' : String(value);
                }
            };
            const setEditMode = (editing) => {
                if (!form) return;
                form.action = editing ? form.action : createAction;
                if (editMethod) editMethod.disabled = !editing;
                profileTypeInputs.forEach((input) => { input.disabled = editing; });
                createOnlyFields.forEach((wrapper) => {
                    wrapper.hidden = editing;
                    wrapper.querySelectorAll('input, select, textarea').forEach((field) => {
                        field.disabled = editing;
                        field.required = !editing;
                    });
                });
                editOnlyFields.forEach((wrapper) => {
                    wrapper.hidden = !editing;
                    wrapper.querySelectorAll('input, select, textarea').forEach((field) => {
                        field.disabled = !editing;
                        field.required = editing;
                    });
                });
                if (modalTitle) modalTitle.textContent = editing ? 'Edit user profile' : 'Add a new user';
                if (modalDescription) modalDescription.textContent = editing
                    ? 'Update this user’s account and HR profile details.'
                    : 'Create an employee profile and its sign-in account together.';
                if (submitLabel) submitLabel.textContent = editing ? 'Save changes' : 'Create user';
            };
            const populateEditForm = (button, previousInput = {}) => {
                if (!modal || !form) return;
                form.reset();
                form.action = button.dataset.updateUrl || createAction;
                setEditMode(true);
                const data = JSON.parse(button.dataset.user || '{}');
                const profileType = data.profile_type === 'intern' ? 'intern' : 'employee';
                profileTypeInputs.forEach((input) => { input.checked = input.value === profileType; });
                updateProfileType();
                Object.entries(data).forEach(([name, value]) => setFieldValue(name, value));
                Object.entries(previousInput).forEach(([name, value]) => {
                    if (!['_token', '_method', 'password', 'password_confirmation', 'profile_type'].includes(name)) {
                        setFieldValue(name, value);
                    }
                });
                modal.hidden = false;
                document.body.style.overflow = 'hidden';
                updateFullName();
                updateDateSummaries();
                modal.querySelector('[data-edit-only] select')?.focus();
            };
            profileTypeInputs.forEach((input) => input.addEventListener('change', () => {
                updateProfileType();
                updateDateSummaries();
            }));
            [firstName, middleName, lastName].forEach((input) => input?.addEventListener('input', updateFullName));
            [birthDate, ...document.querySelectorAll('[data-tenure-date], [data-tenure-end]')]
                .forEach((input) => input?.addEventListener('input', updateDateSummaries));
            [birthDate, ...document.querySelectorAll('[data-tenure-date], [data-tenure-end]')]
                .forEach((input) => input?.addEventListener('change', updateDateSummaries));
            updateProfileType();
            updateFullName();
            updateDateSummaries();
            if (modal && openModal) {
                setEditMode(false);
                updateProfileType();
                if (!modal.hidden) {
                    document.body.style.overflow = 'hidden';
                    modal.querySelector('input:not([type="hidden"])')?.focus();
                }
                openModal.addEventListener('click', () => {
                    form?.reset();
                    setEditMode(false);
                    modal.hidden = false;
                    document.body.style.overflow = 'hidden';
                    updateProfileType();
                    updateFullName();
                    updateDateSummaries();
                    modal.querySelector('input:not([type="hidden"])')?.focus();
                });
                document.querySelectorAll('[data-edit-user]').forEach((button) => {
                    button.addEventListener('click', () => populateEditForm(button));
                });
                if (editUserIdOnLoad) {
                    const button = document.querySelector(`[data-edit-user-id="${editUserIdOnLoad}"]`);
                    if (button) populateEditForm(button, oldInput);
                }
                modal.querySelectorAll('[data-close-user-modal]').forEach((control) => control.addEventListener('click', closeModal));
                document.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape' && !modal.hidden) closeModal();
                });
                modal.querySelector('form')?.addEventListener('submit', (event) => {
                    if (!event.currentTarget.checkValidity()) return;
                    const submit = event.currentTarget.querySelector('button[type="submit"]');
                    submit.disabled = true;
                    submit.textContent = editMethod?.disabled ? 'Creating…' : 'Saving…';
                });
            }
        })();
    </script>
</x-admin-layout>
