<x-admin-layout title="Calendar">
    @php
        $monthStart = $selectedMonth->copy()->startOfMonth();
        $previousMonth = $monthStart->copy()->subMonth();
        $nextMonth = $monthStart->copy()->addMonth();
        $currentMonth = now()->isSameMonth($monthStart);
        $calendarDays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    @endphp

    <section class="sa-head"><h1>SUPER ADMIN</h1><strong>Calendar</strong></section>

    <div class="sa-calendar-layout">
        <article class="sa-card sa-calendar-card">
            <div class="sa-calendar-heading">
                <h2 class="sa-section-title">Calendar of Events:
                    <strong>{{ $viewMode === 'year' ? $selectedYear : strtoupper($monthStart->format('F Y')) }}</strong>
                </h2>
                <div class="sa-calendar-controls">
                    <nav class="sa-calendar-view-toggle" aria-label="Calendar view">
                        <a href="{{ route('admin.calendar', ['view' => 'month', 'year' => $selectedYear, 'month' => $monthStart->month]) }}"
                            @class(['active' => $viewMode === 'month']) @if($viewMode === 'month') aria-current="page" @endif>Month</a>
                        <a href="{{ route('admin.calendar', ['view' => 'year', 'year' => $selectedYear]) }}"
                            @class(['active' => $viewMode === 'year']) @if($viewMode === 'year') aria-current="page" @endif>Year</a>
                    </nav>

                    <div class="calendar-event-legend" aria-label="Employment calendar event key">
                        <span class="start">Employment or internship start</span>
                        <span class="end">End of employment or internship</span>
                    </div>

                    @if ($viewMode === 'month')
                        <nav class="sa-calendar-navigation" aria-label="Month navigation">
                            @if ($monthStart->year > $minimumCalendarYear || $monthStart->month > 1)
                                <a href="{{ route('admin.calendar', ['view' => 'month', 'year' => $previousMonth->year, 'month' => $previousMonth->month]) }}" aria-label="Previous month">‹</a>
                            @else
                                <span class="disabled" aria-hidden="true">‹</span>
                            @endif
                            <span>{{ $monthStart->format('F, Y') }}</span>
                            @if ($monthStart->year < $maximumCalendarYear || $monthStart->month < 12)
                                <a href="{{ route('admin.calendar', ['view' => 'month', 'year' => $nextMonth->year, 'month' => $nextMonth->month]) }}" aria-label="Next month">›</a>
                            @else
                                <span class="disabled" aria-hidden="true">›</span>
                            @endif
                        </nav>
                    @else
                        <nav class="sa-calendar-navigation" aria-label="Year navigation">
                            @if ($selectedYear > $minimumCalendarYear)
                                <a href="{{ route('admin.calendar', ['view' => 'year', 'year' => $selectedYear - 1]) }}" aria-label="Previous year">‹</a>
                            @else
                                <span class="disabled" aria-hidden="true">‹</span>
                            @endif
                            <span>{{ $selectedYear }}</span>
                            @if ($selectedYear < $maximumCalendarYear)
                                <a href="{{ route('admin.calendar', ['view' => 'year', 'year' => $selectedYear + 1]) }}" aria-label="Next year">›</a>
                            @else
                                <span class="disabled" aria-hidden="true">›</span>
                            @endif
                        </nav>
                    @endif

                    <form class="sa-calendar-year-picker" method="GET" action="{{ route('admin.calendar') }}">
                        <input type="hidden" name="view" value="{{ $viewMode }}">
                        @if ($viewMode === 'month')
                            <input type="hidden" name="month" value="{{ $monthStart->month }}">
                        @endif
                        <label class="sr-only" for="calendar-year">Go to year</label>
                        <input id="calendar-year" type="number" name="year" min="{{ $minimumCalendarYear }}" max="{{ $maximumCalendarYear }}" value="{{ $selectedYear }}">
                        <button type="submit">Go</button>
                    </form>
                </div>
            </div>

            @if ($viewMode === 'month')
                <div class="sa-calendar-grid" aria-label="{{ $monthStart->format('F Y') }}">
                    @foreach ($calendarDays as $weekday)
                        <div class="weekday">{{ $weekday }}</div>
                    @endforeach
                    @for ($blank = 0; $blank < $monthStart->dayOfWeek; $blank++)
                        <div class="calendar-empty" aria-hidden="true"></div>
                    @endfor
                    @for ($day = 1; $day <= $monthStart->daysInMonth; $day++)
                        @php
                            $isToday = $currentMonth && $day === now()->day;
                            $dayDate = $monthStart->copy()->day($day)->toDateString();
                            $dayEvents = $calendarEventsByDate->get($dayDate, collect());
                        @endphp
                        <div @class(['calendar-day', 'is-today' => $isToday])>
                            <div class="calendar-day-heading">
                                <span>{{ $day }}</span>
                                @if ($isToday)<span class="calendar-today-label">Today</span>@endif
                            </div>
                            @if ($dayEvents->isNotEmpty())
                                <div class="calendar-day-events">
                                    @foreach ($dayEvents->take(2) as $event)
                                        <div class="calendar-date-event {{ $event['kind'] }}" title="{{ $event['event_type'] }}: {{ $event['name'] }}">
                                            <strong>{{ $event['label'] }}</strong>
                                            <span>{{ $event['name'] }}</span>
                                        </div>
                                    @endforeach
                                    @if ($dayEvents->count() > 2)
                                        <span class="calendar-more-events">+{{ $dayEvents->count() - 2 }} more</span>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endfor
                </div>
            @else
                <div class="sa-calendar-year-grid">
                    @for ($monthNumber = 1; $monthNumber <= 12; $monthNumber++)
                        @php
                            $yearMonth = $monthStart->copy()->setMonth($monthNumber)->startOfMonth();
                            $isCurrentYearMonth = now()->isSameMonth($yearMonth);
                            $monthEventCount = $calendarEventCountsByMonth->get($monthNumber, 0);
                        @endphp
                        <section class="sa-calendar-mini-month">
                            <h3>
                                <a href="{{ route('admin.calendar', ['view' => 'month', 'year' => $selectedYear, 'month' => $monthNumber]) }}">
                                    {{ $yearMonth->format('F') }}
                                </a>
                                @if ($monthEventCount > 0)
                                    <small>{{ $monthEventCount }} {{ $monthEventCount === 1 ? 'date' : 'dates' }}</small>
                                @endif
                            </h3>
                            <div class="sa-calendar-mini-grid">
                                @foreach (['S', 'M', 'T', 'W', 'T', 'F', 'S'] as $weekday)
                                    <span class="weekday">{{ $weekday }}</span>
                                @endforeach
                                @for ($blank = 0; $blank < $yearMonth->dayOfWeek; $blank++)
                                    <span aria-hidden="true"></span>
                                @endfor
                                @for ($day = 1; $day <= $yearMonth->daysInMonth; $day++)
                                    @php
                                        $miniDate = $yearMonth->copy()->day($day);
                                        $miniEvents = $calendarEventsByDate->get($miniDate->toDateString(), collect());
                                        $miniEventLabel = $miniEvents->map(fn (array $event): string => $event['event_type'].': '.$event['name'])->implode(' · ');
                                    @endphp
                                    <span
                                        @class([
                                            'is-today' => $isCurrentYearMonth && $day === now()->day,
                                            'has-events' => $miniEvents->isNotEmpty(),
                                            'has-start' => $miniEvents->contains(fn (array $event): bool => $event['kind'] === 'start'),
                                            'has-end' => $miniEvents->contains(fn (array $event): bool => $event['kind'] === 'end'),
                                        ])
                                        @if ($miniEvents->isNotEmpty())
                                            title="{{ $miniEventLabel }}"
                                            aria-label="{{ $miniDate->format('F j, Y') }}: {{ $miniEventLabel }}"
                                        @endif
                                    >{{ $day }}</span>
                                @endfor
                            </div>
                        </section>
                    @endfor
                </div>
            @endif
        </article>

        <aside class="space-y-4">
            <article class="sa-card min-h-28">
                <h2 class="sa-section-title">Today’s Agenda</h2>
                <p class="sa-muted">({{ now()->format('M d, Y') }})</p>
                @if ($todayEvents->isNotEmpty())
                    <div class="calendar-agenda-list">
                        @foreach ($todayEvents as $event)
                            <div class="calendar-agenda-event">
                                <span class="calendar-agenda-marker {{ $event['kind'] }}">{{ $event['label'] }}</span>
                                <div><strong>{{ $event['name'] }}</strong><small>{{ $event['event_type'] }}</small></div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="mt-6 text-center text-xs text-slate-400">No employment dates today.</p>
                @endif
            </article>
            <article class="sa-card min-h-28">
                <h2 class="sa-section-title">Upcoming Agenda</h2>
                @if ($upcomingEvents->isNotEmpty())
                    <div class="calendar-agenda-list">
                        @foreach ($upcomingEvents as $event)
                            <div class="calendar-agenda-event">
                                <span class="calendar-agenda-date">{{ $event['date_label'] }}</span>
                                <span class="calendar-agenda-marker {{ $event['kind'] }}">{{ $event['label'] }}</span>
                                <div><strong>{{ $event['name'] }}</strong><small>{{ $event['event_type'] }}</small></div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="mt-6 text-center text-xs text-slate-400">No employment dates in the next 30 days.</p>
                @endif
            </article>
        </aside>
    </div>

    <style>
        .sa-calendar-heading{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}.sa-calendar-heading .sa-section-title{margin:0}.sa-calendar-controls{display:flex;flex-wrap:wrap;align-items:center;gap:10px}.sa-calendar-view-toggle,.sa-calendar-navigation{display:flex;align-items:center;gap:6px}.sa-calendar-view-toggle{border:1px solid #d5e0e8;border-radius:8px;padding:3px}.sa-calendar-view-toggle a{border-radius:6px;padding:6px 10px;color:#46647a;font-size:12px;font-weight:700;text-decoration:none}.sa-calendar-view-toggle a.active{background:#0d5b88;color:#fff}.sa-calendar-navigation a,.sa-calendar-navigation .disabled{display:grid;width:32px;height:32px;place-items:center;border-radius:6px;background:#0d5b88;color:#fff;font-size:20px;line-height:1;text-decoration:none}.sa-calendar-navigation a:hover{background:#094a70}.sa-calendar-navigation .disabled{background:#dbe4ea;color:#9aaab6}.sa-calendar-navigation>span{min-width:110px;text-align:center;font-size:13px;font-weight:700;color:#173c56}.sa-calendar-year-picker{display:flex;gap:5px}.sa-calendar-year-picker input{width:82px;height:32px;border:1px solid #d5e0e8;border-radius:6px;padding:4px 7px;color:#173c56;font-size:12px}.sa-calendar-year-picker button{border:0;border-radius:6px;background:#e8f4fa;padding:0 10px;color:#0d5b88;font-size:12px;font-weight:700;cursor:pointer}.sa-calendar-year-picker button:hover{background:#d5ebf7}.sa-calendar-grid>.calendar-empty{background:#f7f9fa}.sa-calendar-grid>.calendar-day{display:flex;flex-direction:column;align-items:flex-start;gap:8px}.sa-calendar-grid>.calendar-day.is-today{background:#e8f7f0;color:#056b48;font-weight:700}.calendar-today-label{border-radius:10px;background:#00845e;padding:2px 8px;color:#fff;font-size:9px;text-transform:uppercase}.sa-calendar-year-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.sa-calendar-mini-month{border:1px solid #dbe4ea;border-radius:8px;padding:10px}.sa-calendar-mini-month h3{margin:0 0 7px;font-size:12px;font-weight:800}.sa-calendar-mini-month h3 a{color:#0d5b88;text-decoration:none}.sa-calendar-mini-month h3 a:hover{text-decoration:underline}.sa-calendar-mini-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:2px;text-align:center}.sa-calendar-mini-grid>span{display:grid;min-height:23px;place-items:center;border-radius:4px;color:#36566d;font-size:10px}.sa-calendar-mini-grid>.weekday{color:#8393a0;font-size:9px;font-weight:700}.sa-calendar-mini-grid>.is-today{background:#00845e;color:#fff;font-weight:800}@media(max-width:900px){.sa-calendar-year-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:520px){.sa-calendar-heading{align-items:flex-start;flex-direction:column}.sa-calendar-controls{align-items:flex-start;flex-direction:column}.sa-calendar-year-grid{grid-template-columns:1fr}}
        .calendar-event-legend{display:flex;flex-wrap:wrap;gap:6px 14px;margin:0 0 10px;color:#526579;font-size:10px}.calendar-event-legend span{display:inline-flex;align-items:center;gap:5px}.calendar-event-legend span::before{width:8px;height:8px;border-radius:50%;content:""}.calendar-event-legend .start::before{background:#16a34a}.calendar-event-legend .end::before{background:#ea580c}
        .sa-calendar-grid>.calendar-day{min-height:110px;align-items:stretch;gap:6px}.calendar-day-heading{display:flex;align-items:center;justify-content:space-between}.calendar-day-events{display:grid;align-content:start;gap:3px}.calendar-date-event{display:flex;min-width:0;align-items:center;gap:4px;border-radius:4px;padding:3px 4px;font-size:9px;line-height:1.2}.calendar-date-event strong{flex:none;font-size:8px;letter-spacing:.03em}.calendar-date-event span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.calendar-date-event.start,.calendar-agenda-marker.start{background:#dcfce7;color:#166534}.calendar-date-event.end,.calendar-agenda-marker.end{background:#ffedd5;color:#9a3412}.calendar-more-events{color:#64748b;font-size:8px}
        .calendar-agenda-list{display:grid;max-height:300px;gap:8px;overflow-y:auto;margin-top:12px}.calendar-agenda-event{display:flex;align-items:center;gap:8px;border:1px solid #e1e9ee;border-radius:7px;background:#f8fafc;padding:8px}.calendar-agenda-date,.calendar-agenda-marker{flex:none;border-radius:5px;padding:5px 6px;font-size:8px;font-weight:800}.calendar-agenda-date{background:#e8eef5;color:#315d78}.calendar-agenda-event>div{display:grid;min-width:0;gap:2px}.calendar-agenda-event strong{overflow:hidden;color:#173c56;font-size:10px;text-overflow:ellipsis;white-space:nowrap}.calendar-agenda-event small{color:#71889a;font-size:9px}
        .sa-calendar-mini-month h3{display:flex;align-items:center;justify-content:space-between;gap:8px}.sa-calendar-mini-month h3 small{color:#64748b;font-size:9px;font-weight:600}.sa-calendar-mini-grid>span.has-events{position:relative}.sa-calendar-mini-grid>span.has-events::after{position:absolute;right:4px;bottom:2px;width:4px;height:4px;border-radius:50%;content:""}.sa-calendar-mini-grid>span.has-start::after{background:#16a34a}.sa-calendar-mini-grid>span.has-end::after{background:#ea580c}.sa-calendar-mini-grid>span.has-start.has-end::after{background:linear-gradient(90deg,#16a34a 50%,#ea580c 50%)}
    </style>
</x-admin-layout>
