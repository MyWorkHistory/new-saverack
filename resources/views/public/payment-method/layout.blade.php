<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Payment Method') - Save Rack</title>
    <link rel="icon" href="{{ asset('logo.jpg') }}">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
            background: #eef2f7;
            color: #1f2430;
            min-height: 100vh;
        }
        .pm-page {
            max-width: 520px;
            margin: 0 auto;
            padding: 28px 16px 48px;
        }
        .pm-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
            padding: 28px 24px 24px;
        }
        .pm-logo {
            display: block;
            width: 120px;
            height: auto;
            margin: 0 auto 18px;
            object-fit: contain;
        }
        .pm-title {
            text-align: center;
            font-size: 1.45rem;
            font-weight: 700;
            color: #1e3a5f;
            margin: 0 0 8px;
        }
        .pm-sub {
            text-align: center;
            color: #6b7280;
            font-size: 0.92rem;
            line-height: 1.45;
            margin: 0 0 22px;
        }
        .pm-label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 0.35rem;
            color: #374151;
        }
        .pm-field {
            margin-bottom: 0.9rem;
        }
        .pm-input-wrap {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            padding: 0.65rem 0.75rem;
            background: #fff;
        }
        .pm-input-wrap:focus-within {
            border-color: #2573ba;
            box-shadow: 0 0 0 3px rgba(37, 115, 186, 0.15);
        }
        .pm-input-wrap svg {
            width: 18px;
            height: 18px;
            color: #9ca3af;
            flex-shrink: 0;
        }
        .pm-input-wrap input,
        .pm-input-wrap select {
            border: 0;
            outline: none;
            width: 100%;
            font-size: 0.95rem;
            background: transparent;
        }
        .pm-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
        }
        .pm-row-3 {
            display: grid;
            grid-template-columns: 1.2fr 1fr 0.9fr;
            gap: 0.6rem;
        }
        @media (max-width: 560px) {
            .pm-row, .pm-row-3 { grid-template-columns: 1fr; }
        }
        .pm-section-head {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            font-weight: 700;
            margin: 1.1rem 0 0.75rem;
            color: #1f2430;
        }
        .pm-section-head svg { width: 18px; height: 18px; color: #2573ba; }
        .pm-toggle {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.6rem;
            margin-bottom: 0.9rem;
        }
        .pm-toggle button {
            border: 1px solid #d1d5db;
            background: #fff;
            border-radius: 10px;
            padding: 0.75rem 0.5rem;
            font-weight: 600;
            cursor: pointer;
            color: #374151;
        }
        .pm-toggle button.is-active {
            border-color: #2573ba;
            color: #1e58b7;
            box-shadow: 0 0 0 2px rgba(37, 115, 186, 0.15);
        }
        .pm-hint {
            display: flex;
            gap: 0.55rem;
            align-items: flex-start;
            background: #eff6ff;
            border-radius: 10px;
            padding: 0.7rem 0.8rem;
            color: #1e40af;
            font-size: 0.8rem;
            line-height: 1.4;
            margin: 0.35rem 0 0.85rem;
        }
        .pm-hint svg { width: 16px; height: 16px; flex-shrink: 0; margin-top: 2px; }
        .pm-check-graphic {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 0.75rem;
            margin-bottom: 1rem;
            background: #f9fafb;
            font-size: 0.75rem;
            color: #6b7280;
        }
        .pm-check-graphic .routing { color: #2563eb; font-weight: 700; }
        .pm-check-graphic .account { color: #059669; font-weight: 700; }
        .pm-terms {
            display: flex;
            gap: 0.65rem;
            align-items: flex-start;
            background: #eff6ff;
            border-radius: 10px;
            padding: 0.85rem 0.9rem;
            margin: 1rem 0;
            font-size: 0.9rem;
        }
        .pm-terms a {
            color: #2573ba;
            font-weight: 600;
            text-decoration: none;
        }
        .pm-terms a:hover { text-decoration: underline; }
        .pm-submit {
            display: block;
            width: 100%;
            border: 0;
            border-radius: 10px;
            background: #1e58b7;
            color: #fff;
            font-weight: 700;
            font-size: 1rem;
            padding: 0.9rem 1rem;
            cursor: pointer;
        }
        .pm-submit:disabled {
            opacity: 0.65;
            cursor: not-allowed;
        }
        .pm-secure {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.4rem;
            margin-top: 0.85rem;
            color: #9ca3af;
            font-size: 0.78rem;
        }
        .pm-secure svg { width: 14px; height: 14px; }
        .pm-error {
            background: #fef2f2;
            color: #b91c1c;
            border-radius: 8px;
            padding: 0.65rem 0.75rem;
            font-size: 0.85rem;
            margin-bottom: 0.85rem;
            display: none;
        }
        .pm-error.is-visible { display: block; }
        .StripeElement { width: 100%; }
        .pm-lightbox {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 50;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .pm-lightbox.is-open { display: flex; }
        .pm-lightbox__backdrop {
            position: absolute;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
        }
        .pm-lightbox__panel {
            position: relative;
            background: #fff;
            border-radius: 14px;
            max-width: 560px;
            width: 100%;
            max-height: min(85vh, 640px);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            box-shadow: 0 20px 50px rgba(0,0,0,0.2);
        }
        .pm-lightbox__head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 1.1rem;
            border-bottom: 1px solid #e5e7eb;
        }
        .pm-lightbox__head h2 {
            margin: 0;
            font-size: 1.05rem;
        }
        .pm-lightbox__close {
            border: 0;
            background: transparent;
            font-size: 1.4rem;
            line-height: 1;
            cursor: pointer;
            color: #6b7280;
        }
        .pm-lightbox__body {
            padding: 1rem 1.1rem 1.25rem;
            overflow-y: auto;
            font-size: 0.9rem;
            line-height: 1.55;
        }
        .pm-lightbox__body h2 { font-size: 1.05rem; margin: 0 0 0.75rem; }
        .pm-lightbox__body p { margin: 0 0 0.75rem; }
        .pm-brands { display: flex; gap: 0.25rem; margin-left: auto; font-size: 0.65rem; color: #6b7280; font-weight: 700; letter-spacing: 0.02em; }
        #card-element { width: 100%; padding: 2px 0; }
    </style>
    @yield('head')
</head>
<body>
    <div class="pm-page">
        <div class="pm-card">
            <img class="pm-logo" src="{{ asset('logo.jpg') }}" alt="Save Rack" width="120" height="40">
            @yield('content')
        </div>
    </div>
    @yield('modals')
    @yield('scripts')
</body>
</html>
