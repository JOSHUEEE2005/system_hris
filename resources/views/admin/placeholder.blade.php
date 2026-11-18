<x-admin-layout :title="$pageTitle">
    <section class="sa-head"><h1>SUPER ADMIN</h1><strong>{{ $pageTitle }}</strong></section>
    @if($pageTitle === 'Calendar')
        @php $month = now()->startOfMonth(); $days = $month->daysInMonth; $offset = $month->dayOfWeek; @endphp
        <div class="sa-calendar-layout">
            <article class="sa-card"><h2 class="sa-section-title">Calendar of Events: <strong>{{ strtoupper($month->format('F Y')) }}</strong></h2><div class="mb-3 flex items-center gap-2"><button class="rounded bg-[#0d5b88] px-3 py-1 text-white">‹</button><span class="text-sm font-bold">{{ $month->format('F, Y') }}</span><button class="rounded bg-[#0d5b88] px-3 py-1 text-white">›</button></div><div class="sa-calendar-grid">@foreach(['Sun','Mon','Tues','Wed','Thurs','Fri','Sat'] as $day)<div class="weekday">{{ $day }}</div>@endforeach @for($blank=0;$blank<$offset;$blank++)<div></div>@endfor @for($day=1;$day<=$days;$day++)<div class="{{ $day === now()->day ? 'bg-emerald-500 text-white' : '' }}">{{ $day }} @if($day === now()->day)<span class="mt-2 block rounded bg-emerald-700 px-1 text-[9px]">today</span>@endif</div>@endfor</div></article>
            <aside class="space-y-4"><article class="sa-card min-h-28"><h2 class="sa-section-title">Today’s Agenda</h2><p class="sa-muted">({{ now()->format('M d, Y') }})</p><p class="mt-6 text-center text-xs text-slate-400">Nothing to see here...</p></article><article class="sa-card min-h-28"><h2 class="sa-section-title">Upcoming Agenda</h2><p class="text-center text-xs text-slate-400">No upcoming events.</p></article></aside>
        </div>
    @else
        <article class="sa-card mt-4"><h2 class="sa-section-title">{{ $pageTitle }}</h2><p class="sa-muted">{{ $pageDescription }}</p><p class="mt-6 text-sm text-slate-600">This page is ready for the {{ strtolower($pageTitle) }} module.</p></article>
    @endif
</x-admin-layout>
