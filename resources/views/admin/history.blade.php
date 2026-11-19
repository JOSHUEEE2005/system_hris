<x-admin-layout title="System History">
    <section class="sa-head">
        <h1>SYSTEM HISTORY</h1>
        <strong>Hi, {{ auth()->user()->name }}</strong>
    </section>

    <main class="history-page">
        <div class="history-heading">
            <div>
                <h2>System activity</h2>
                <p>Review recorded changes and actions across the system.</p>
            </div>
        </div>

        <form class="history-search" method="GET" action="{{ route('admin.history') }}">
            <label for="history-search">Search activity</label>
            <div>
                <input id="history-search" name="search" value="{{ $search }}" placeholder="Action, area, record ID, or user">
                <button class="contract-primary-button" type="submit">Search</button>
                @if ($search !== '')
                    <a class="contract-action-secondary" href="{{ route('admin.history') }}">Clear</a>
                @endif
            </div>
        </form>

        @if ($logs->isEmpty())
            <div class="contract-empty">
                <h3>No activity found</h3>
                <p>{{ $search !== '' ? 'Try another search.' : 'System activity will appear here as changes are recorded.' }}</p>
            </div>
        @else
            <div class="history-table-wrap">
                <table class="sa-table history-table">
                    <thead>
                        <tr>
                            <th>Date and time</th>
                            <th>Action</th>
                            <th>Record</th>
                            <th>Performed by</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($logs as $log)
                            <tr>
                                <td>{{ $log->created_at?->format('M j, Y · g:i A') ?? '—' }}</td>
                                <td>{{ ucfirst(str_replace(['.', '_'], ' ', $log->event_key)) }}</td>
                                <td>{{ ucfirst($log->entity_type) }}{{ $log->entity_id ? ' #'.$log->entity_id : '' }}</td>
                                <td>{{ $log->actor?->name ?? 'System' }}</td>
                                <td>
                                    @if ($log->details)
                                        <details class="history-details">
                                            <summary>View</summary>
                                            <pre>{{ json_encode($log->details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                        </details>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="history-pagination">{{ $logs->links() }}</div>
        @endif
    </main>
</x-admin-layout>
