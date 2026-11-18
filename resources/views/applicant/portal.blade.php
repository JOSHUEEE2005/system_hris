<x-auth-layout title="Applicant Portal | GPCCI HRIS" heading="Applicant portal">
    <p class="auth-intro">You are signed in. Your application and onboarding updates will appear here.</p>
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button class="auth-submit" type="submit">Sign out <span aria-hidden="true">→</span></button>
    </form>
</x-auth-layout>
