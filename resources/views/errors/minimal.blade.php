{{-- Replaces the framework's errors::minimal (used by 401, 402, 403, 404, 419, 429, 500 and 503,
     including maintenance mode). Its styles are in the static /errors.css rather than inline, so the
     Content-Security-Policy keeps style-src 'self', and rather than in the Vite build, so the page
     still has its styles when the build is what failed. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>@yield('title') · {{ config('app.name', 'Laravel') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="stylesheet" href="/errors.css">
    </head>
    <body>
        <main class="error">
            <p class="error-code">@yield('code')</p>
            <h1 class="error-message">@yield('message')</h1>
            <a class="error-link" href="{{ url('/') }}">Volver al inicio</a>
        </main>
    </body>
</html>
