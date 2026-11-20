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
                        @php $isToday = $currentMonth && $day === now()->day; @endphp
                        <div @class(['calendar-day', 'is-today' => $isToday])>
                            <span>{{ $day }}</span>
                            @if ($isToday)<span class="calendar-today-label">Today</span>@endif
                        </div>
                    @endfor
                </div>
            @else
                <div class="sa-calendar-year-grid">
                    @for ($monthNumber = 1; $monthNumber <= 12; $monthNumber++)
                        @php
                            $yearMonth = $monthStart->copy()->setMonth($monthNumber)->startOfMonth();
                            $isCurrentYearMonth = now()->isSameMonth($yearMonth);
                        @endphp
                        <section class="sa-calendar-mini-month">
                            <h3>
                                <a href="{{ route('admin.calendar', ['view' => 'month', 'year' => $selectedYear, 'month' => $monthNumber]) }}">
                                    {{ $yearMonth->format('F') }}
                                </a>
                            </h3>
                            <div class="sa-calendar-mini-grid">
                                @foreach (['S', 'M', 'T', 'W', 'T', 'F', 'S'] as $weekday)
                                    <span class="weekday">{{ $weekday }}</span>
                                @endforeach
                                @for ($blank = 0; $blank < $yearMonth->dayOfWeek; $blank++)
                                    <span aria-hidden="true"></span>
                                @endfor
                                @for ($day = 1; $day <= $yearMonth->daysInMonth; $day++)
                                    <span @class(['is-today' => $isCurrentYearMonth && $day === now()->day])>{{ $day }}</span>
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
                <p class="mt-6 text-center text-xs text-slate-400">Nothing to see here...</p>
            </article>
            <article class="sa-card min-h-28">
                <h2 class="sa-section-title">Upcoming Agenda</h2>
                <p class="text-center text-xs text-slate-400">No upcoming events.</p>
            </article>
        </aside>
    </div>

    <style>
        .sa-calendar-heading{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}.sa-calendar-heading .sa-section-title{margin:0}.sa-calendar-controls{display:flex;flex-wrap:wrap;align-items:center;gap:10px}.sa-calendar-view-toggle,.sa-calendar-navigation{display:flex;align-items:center;gap:6px}.sa-calendar-view-toggle{border:1px solid #d5e0e8;border-radius:8px;padding:3px}.sa-calendar-view-toggle a{border-radius:6px;padding:6px 10px;color:#46647a;font-size:12px;font-weight:700;text-decoration:none}.sa-calendar-view-toggle a.active{background:#0d5b88;color:#fff}.sa-calendar-navigation a,.sa-calendar-navigation .disabled{display:grid;width:32px;height:32px;place-items:center;border-radius:6px;background:#0d5b88;color:#fff;font-size:20px;line-height:1;text-decoration:none}.sa-calendar-navigation a:hover{background:#094a70}.sa-calendar-navigation .disabled{background:#dbe4ea;color:#9aaab6}.sa-calendar-navigation>span{min-width:110px;text-align:center;font-size:13px;font-weight:700;color:#173c56}.sa-calendar-year-picker{display:flex;gap:5px}.sa-calendar-year-picker input{width:82px;height:32px;border:1px solid #d5e0e8;border-radius:6px;padding:4px 7px;color:#173c56;font-size:12px}.sa-calendar-year-picker button{border:0;border-radius:6px;background:#e8f4fa;padding:0 10px;color:#0d5b88;font-size:12px;font-weight:700;cursor:pointer}.sa-calendar-year-picker button:hover{background:#d5ebf7}.sa-calendar-grid>.calendar-empty{background:#f7f9fa}.sa-calendar-grid>.calendar-day{display:flex;flex-direction:column;align-items:flex-start;gap:8px}.sa-calendar-grid>.calendar-day.is-today{background:#e8f7f0;color:#056b48;font-weight:700}.calendar-today-label{border-radius:10px;background:#00845e;padding:2px 8px;color:#fff;font-size:9px;text-transform:uppercase}.sa-calendar-year-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.sa-calendar-mini-month{border:1px solid #dbe4ea;border-radius:8px;padding:10px}.sa-calendar-mini-month h3{margin:0 0 7px;font-size:12px;font-weight:800}.sa-calendar-mini-month h3 a{color:#0d5b88;text-decoration:none}.sa-calendar-mini-month h3 a:hover{text-decoration:underline}.sa-calendar-mini-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:2px;text-align:center}.sa-calendar-mini-grid>span{display:grid;min-height:23px;place-items:center;border-radius:4px;color:#36566d;font-size:10px}.sa-calendar-mini-grid>.weekday{color:#8393a0;font-size:9px;font-weight:700}.sa-calendar-mini-grid>.is-today{background:#00845e;color:#fff;font-weight:800}@media(max-width:900px){.sa-calendar-year-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:520px){.sa-calendar-heading{align-items:flex-start;flex-direction:column}.sa-calendar-controls{align-items:flex-start;flex-direction:column}.sa-calendar-year-grid{grid-template-columns:1fr}}
    </style>
</x-admin-layout>
