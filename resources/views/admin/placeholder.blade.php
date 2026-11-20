<x-admin-layout :title="$pageTitle">
    <section class="sa-head"><h1>SUPER ADMIN</h1><strong>{{ $pageTitle }}</strong></section>
    <article class="sa-card mt-4"><h2 class="sa-section-title">{{ $pageTitle }}</h2><p class="sa-muted">{{ $pageDescription }}</p><p class="mt-6 text-sm text-slate-600">This page is ready for the {{ strtolower($pageTitle) }} module.</p></article>
</x-admin-layout>
