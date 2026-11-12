<x-auth-layout title="Complete registration | GPCCI HRIS" heading="Complete your profile">
    <p class="auth-intro">Create an account with your email. Email verification is paused for now. HR will review your registration before you can sign in.</p>
    <form class="auth-form" method="POST" action="{{ route('register.store') }}">
        @csrf
        <label class="auth-field">Full name
            <input class="auth-input" type="text" name="name" value="{{ old('name') }}" autocomplete="name" placeholder="Your full name" required maxlength="255">
        </label>
        <label class="auth-field">Email address
            <input class="auth-input" type="email" name="email" value="{{ old('email') }}" autocomplete="email" placeholder="you@example.com" required maxlength="150">
        </label>
        <label class="auth-field">Contact number <span class="auth-optional">Optional</span>
            <input class="auth-input" type="tel" name="contact_number" value="{{ old('contact_number') }}" autocomplete="tel" placeholder="e.g. 0917 123 4567" maxlength="20">
        </label>
        <label class="auth-field">Password <span class="auth-optional">At least 12 characters</span>
            <input class="auth-input" type="password" name="password" autocomplete="new-password" placeholder="Create a password" required minlength="12" maxlength="128">
        </label>
        <label class="auth-field">Confirm password
            <input class="auth-input" type="password" name="password_confirmation" autocomplete="new-password" placeholder="Enter your password again" required minlength="12" maxlength="128">
        </label>
        <button class="auth-submit" type="submit">Create account <span aria-hidden="true">→</span></button>
    </form>
    <p class="auth-footer">Already registered? <a href="{{ route('login') }}">Sign in</a></p>
</x-auth-layout>
