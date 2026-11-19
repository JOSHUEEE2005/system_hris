<x-admin-layout title="Dashboard">
    <section class="sa-head"><h1>SUPER ADMIN</h1><strong>Hi, {{ auth()->user()->name }}</strong></section>
    <section class="sa-kpis">
        <article class="sa-card sa-kpi"><span class="sa-kpi-icon">♙</span><div><strong>{{ $users->where('status','Active')->count() }}</strong><small>Active Users</small></div></article>
        <article class="sa-card sa-kpi"><span class="sa-kpi-icon">◷</span><div><strong>{{ $pending->count() }}</strong><small>Pending applications</small></div></article>
        <article class="sa-card sa-kpi"><span class="sa-kpi-icon">↪</span><div><strong>{{ $employees->where('status','Active')->count() }}</strong><small>Onboarding</small></div></article>
        <article class="sa-card sa-kpi"><span class="sa-kpi-icon">☑</span><div><strong>{{ $employees->where('status','Inactive')->count() }}</strong><small>Offboarding</small></div></article>
    </section>
    <section class="sa-grid">
        <article class="sa-card"><h2 class="sa-section-title">Recent Activities @if ($canViewAudit)<a href="{{ route('admin.history') }}">View all</a>@endif</h2><table class="sa-table"><thead><tr><th>Activity</th><th>User</th><th>Date</th><th>Status</th></tr></thead><tbody>@forelse($auditLogs->take(5) as $log)<tr><td>{{ ucfirst(str_replace('.', ' ', $log->event_key)) }}</td><td>{{ $log->actor?->name ?? 'System' }}</td><td>{{ $log->created_at?->format('m-d-Y') }}</td><td>Recorded</td></tr>@empty<tr><td colspan="4">No recent activities</td></tr>@endforelse</tbody></table></article>
        <article class="sa-card"><h2 class="sa-section-title">Task to Accomplish</h2><ul class="sa-list"><li>Review pending applications <strong>{{ $pending->count() }}</strong></li><li>Active users <strong>{{ $users->where('status','Active')->count() }}</strong></li><li>Employee records <strong>{{ $employees->count() }}</strong></li><li>Departments <strong>{{ $departments->count() }}</strong></li></ul></article>
    </section>
    <section class="sa-grid">
        <article class="sa-card"><h2 class="sa-section-title">Reports</h2><div class="sa-report-grid">@foreach([['Employees',$employees->count()],['Users',$users->count()],['Interns',$employees->where('employment_type','Internship')->count()],['Departments',$departments->count()]] as $report)<div>{{ $report[0] }}<strong>{{ $report[1] }}</strong></div>@endforeach</div></article>
        <article class="sa-card"><h2 class="sa-section-title">Announcements</h2><ul class="sa-list"><li>Keep employee records current.</li><li>Review pending applications.</li><li>Check recent activities.</li><li>Use audit history for follow-up.</li></ul></article>
    </section>
</x-admin-layout>
