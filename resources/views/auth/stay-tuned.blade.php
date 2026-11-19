<x-admin-layout title="Home">
    <section class="sa-head">
        <h1>HOME</h1>
        <strong>Hi, {{ auth()->user()->name }}</strong>
    </section>

    <section class="user-home-placeholder" aria-labelledby="user-home-title">
        <span class="user-home-placeholder-icon" aria-hidden="true">⌂</span>
        <p class="user-home-placeholder-eyebrow">EMPLOYEE HOME</p>
        <h2 id="user-home-title">Coming soon</h2>
        <p>We're still working on your home page. For now, you can view the contracts HR has sent to you.</p>
        <a href="{{ route('admin.contracts') }}">Go to Contracts <span aria-hidden="true">→</span></a>
    </section>
</x-admin-layout>
