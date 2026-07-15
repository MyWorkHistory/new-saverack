<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} - Save Rack</title>
    <link rel="icon" href="{{ asset('logo.jpg') }}">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Georgia, "Times New Roman", Times, serif;
            font-size: 16px;
            line-height: 1.55;
            color: #1f2430;
            background: #fff;
        }
        .wrap {
            max-width: 720px;
            margin: 0 auto;
            padding: 28px 20px 48px;
        }
        .logo {
            display: block;
            width: 56px;
            height: auto;
            margin: 0 auto 28px;
            object-fit: contain;
        }
        .content h2 {
            font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
            font-size: 1.25rem;
            margin: 1.75rem 0 0.75rem;
            line-height: 1.3;
        }
        .content h3, .content h4 {
            font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
            font-size: 1.05rem;
            margin: 1.4rem 0 0.55rem;
            line-height: 1.3;
        }
        .content p { margin: 0 0 0.85rem; }
        .content ul, .content ol { margin: 0 0 0.85rem; padding-left: 1.35rem; }
        .content li { margin: 0.25rem 0; }
        .content strong, .content b { font-weight: 700; }
        .empty {
            font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
            color: #6b7280;
            text-align: center;
            margin-top: 24px;
        }
    </style>
</head>
<body>
    <div class="wrap">
        <img class="logo" src="{{ asset('logo.jpg') }}" alt="Save Rack" width="56" height="56">
        <div class="content">
            @if(trim(strip_tags($body_html)) === '')
                <p class="empty">Terms of Service are not available yet.</p>
            @else
                {!! $body_html !!}
            @endif
        </div>
    </div>
</body>
</html>
