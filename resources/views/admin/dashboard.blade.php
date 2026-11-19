<x-admin-layout title="Dashboard">
    <section class="sa-head"><h1>SUPER ADMIN</h1><strong>Hi, {{ auth()->user()->name }}</strong></section>
    <section class="sa-kpis">
        <article class="sa-card sa-kpi"><span class="sa-kpi-icon">♙</span><div><strong>{{ $activeUserCount }}</strong><small>Active Users</small></div></article>
        <article class="sa-card sa-kpi"><span class="sa-kpi-icon">◷</span><div><strong>{{ $pendingCount }}</strong><small>Pending applications</small></div></article>
        <article class="sa-card sa-kpi"><span class="sa-kpi-icon">↪</span><div><strong>{{ $activeEmployeeCount }}</strong><small>Onboarding</small></div></article>
        <article class="sa-card sa-kpi"><span class="sa-kpi-icon">☑</span><div><strong>{{ $inactiveEmployeeCount }}</strong><small>Offboarding</small></div></article>
    </section>
    <section class="sa-grid">
        <article class="sa-card"><h2 class="sa-section-title">Recent Activities @if ($canViewAudit)<a href="{{ route('admin.history') }}">View all</a>@endif</h2><div class="sa-table-scroll"><table class="sa-table"><thead><tr><th>Activity</th><th>User</th><th>Date</th><th>Status</th></tr></thead><tbody>@forelse($auditLogs as $log)<tr><td>{{ ucfirst(str_replace('.', ' ', $log->event_key)) }}</td><td>{{ $log->actor?->name ?? 'System' }}</td><td>{{ $log->created_at?->format('m-d-Y') }}</td><td>Recorded</td></tr>@empty<tr><td colspan="4">No recent activities</td></tr>@endforelse</tbody></table></div></article>
        <article class="sa-card"><h2 class="sa-section-title">Task to Accomplish</h2><ul class="sa-list"><li>Review pending applications <strong>{{ $pendingCount }}</strong></li><li>Active users <strong>{{ $activeUserCount }}</strong></li><li>Employee records <strong>{{ $employeeCount }}</strong></li><li>Departments <strong>{{ $departmentCount }}</strong></li></ul></article>
    </section>
    <section class="sa-grid">
        <article class="sa-card"><h2 class="sa-section-title">Reports</h2><div class="sa-report-grid">@foreach([['Employees',$employeeCount],['Users',$userCount],['Interns',$internCount],['Departments',$departmentCount]] as $report)<div>{{ $report[0] }}<strong>{{ $report[1] }}</strong></div>@endforeach</div></article>
        <article class="sa-card"><h2 class="sa-section-title">Announcements</h2><ul class="sa-list"><li>Keep employee records current.</li><li>Review pending applications.</li><li>Check recent activities.</li><li>Use audit history for follow-up.</li></ul></article>
    </section>
</x-admin-layout>
