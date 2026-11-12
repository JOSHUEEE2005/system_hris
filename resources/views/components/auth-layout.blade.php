@props(['title' => 'GPCCI HRIS', 'heading' => 'GPCCI HRIS'])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="auth-page">
    <main class="auth-shell">
        <a class="auth-back-link" href="{{ route('home') }}">← GPCCI HRIS</a>
        <section class="auth-card">
            <div class="auth-heading">
                <span class="auth-eyebrow">GPCCI · People & operations</span>
                <h1>{{ $heading }}</h1>
            </div>
            @if (session('status'))
                <p class="auth-status">{{ session('status') }}</p>
            @endif
            @if ($errors->any())
                <div class="auth-errors" role="alert">
                    <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif
            {{ $slot }}
        </section>
    </main>
</body>
</html>
