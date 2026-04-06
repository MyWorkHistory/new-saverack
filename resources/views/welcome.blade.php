<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Save Rack</title>
    <link rel="icon" type="image/jpeg" href="{{ asset('logo.jpg') }}?v=20260402a">
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @else
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
              integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
        <style>:root { --bs-primary: #7367f0; }</style>
        @endif
    </head>
<body class="min-vh-100 d-flex flex-column bg-body-secondary">
    <main class="flex-grow-1 d-flex align-items-center justify-content-center p-4">
        <div class="card border-0 shadow-sm text-center" style="max-width: 28rem">
            <div class="card-body p-5">
                <h1 class="h3 fw-bold text-primary mb-2">Save Rack</h1>
                <p class="text-body-secondary small mb-4">Application is running.</p>
                        @if (Route::has('login'))
                                @auth
                        <a href="{{ url('/tickets-app/') }}" class="btn btn-primary fw-semibold rounded-3">Open CRM</a>
                                @else
                        <a href="{{ url('/tickets-app/login') }}" class="btn btn-primary fw-semibold rounded-3">Sign in</a>
                                @endauth
                        @endif
            </div>
        </div>
    </main>
    </body>
</html>
