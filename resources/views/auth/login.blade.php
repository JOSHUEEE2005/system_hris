<x-auth-layout title="Sign in | GPCCI HRIS" heading="Sign in">
    <p class="auth-intro">Sign in to your GPCCI HRIS account.</p>
    <form class="auth-form" method="POST" action="{{ route('login.store') }}">
        @csrf
        <label class="auth-field">Email address
            <input class="auth-input" type="email" name="email" value="{{ old('email') }}" autocomplete="email" placeholder="you@example.com" required>
        </label>
        <label class="auth-field">Password
            <input class="auth-input" type="password" name="password" autocomplete="current-password" placeholder="Enter your password" required>
        </label>
        <label class="auth-checkbox"><input type="checkbox" name="remember" value="1"> Remember me</label>
        <button class="auth-submit" type="submit">Sign in <span aria-hidden="true">→</span></button>
    </form>
    <p class="auth-footer">Need an account? <a href="{{ route('register.email') }}">Register</a></p>
</x-auth-layout>
