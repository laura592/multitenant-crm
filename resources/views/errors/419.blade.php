@php
    // Ricostruiamo l'URL a cui l'utente vuole tornare dopo il login: o dal
    // parametro ?redirect= (impostato da resources/js/app.js sul 419 delle
    // richieste Livewire), o dall'URL della richiesta precedente (caso del
    // 419 "pieno" su submit di un form non-Livewire, dove non passiamo per
    // il redirect JS ma questa vista viene renderizzata direttamente).
    // Filament::Login::authenticate() fa redirect()->intended(...), quindi
    // basta valorizzare session('url.intended') per farci riportare li'.
    $redirectUrl = request()->query('redirect') ?: url()->previous();
    $redirectHost = $redirectUrl ? parse_url($redirectUrl, PHP_URL_HOST) : null;

    $isSafeRedirect = $redirectUrl
        && $redirectHost === request()->getHost()
        && ! str_starts_with($redirectUrl, route('login'))
        && ! str_starts_with($redirectUrl, route('session.expired'));

    if ($isSafeRedirect) {
        session()->put('url.intended', $redirectUrl);
    }
@endphp
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sessione scaduta - {{ config('app.name') }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: linear-gradient(to bottom right, #1c2033, #12141f, #1c2033);
            color: #e5e7eb;
            padding: 1.5rem;
        }
        .card {
            width: 100%;
            max-width: 26rem;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 1rem;
            padding: 2.5rem 2rem;
            text-align: center;
            box-shadow: 0 20px 40px -12px rgba(0, 0, 0, 0.5);
        }
        .logo { display: block; width: 11rem; height: auto; margin: 0 auto 1.75rem; }
        .icon {
            width: 3.5rem;
            height: 3.5rem;
            margin: 0 auto 1.25rem;
            border-radius: 9999px;
            background: rgba(49, 110, 180, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #5b9bd8;
        }
        .icon svg { width: 1.75rem; height: 1.75rem; }
        h1 { font-size: 1.25rem; margin: 0 0 0.5rem; color: #fff; font-weight: 600; }
        p { margin: 0 0 1.75rem; color: #9ca3af; line-height: 1.55; font-size: 0.9375rem; }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            background: #316EB4;
            color: #fff;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9375rem;
            padding: 0.625rem 1.5rem;
            border-radius: 0.5rem;
            transition: background 0.15s ease;
        }
        .btn:hover { background: #2a5c99; }
    </style>
</head>
<body>
    <div class="card">
        <img src="{{ asset('img/logo-white.svg') }}" alt="{{ config('app.name') }}" class="logo">
        <div class="icon">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2" />
                <circle cx="12" cy="12" r="9" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </div>
        <h1>Sessione scaduta</h1>
        <p>Per motivi di sicurezza la tua sessione &egrave; scaduta. Accedi di nuovo per continuare a usare {{ config('app.name') }}.</p>
        <a href="{{ route('login') }}" class="btn">Accedi di nuovo</a>
    </div>
</body>
</html>
