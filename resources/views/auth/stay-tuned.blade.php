<x-auth-layout title="Stay tuned | GPCCI HRIS" heading="Stay tuned">
    <p class="auth-intro">Your account is ready. We are still preparing the next part of your HRIS workspace.</p>
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button class="auth-submit" type="submit">Sign out <span aria-hidden="true">→</span></button>
    </form>
</x-auth-layout>
