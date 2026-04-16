<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $invoice->invoice_number }}</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 15px; line-height: 1.5; color: #2f2f2f;">
    <p style="margin: 0 0 12px;">Hello,</p>
    @if (!empty($customMessage))
        <p style="margin: 0 0 12px;">{{ $customMessage }}</p>
    @endif
    <p style="margin: 0 0 12px;">
        Invoice <strong>{{ $invoice->invoice_number }}</strong>
        @if ($invoice->clientAccount)
            for <strong>{{ $invoice->clientAccount->company_name }}</strong>
        @endif
        has been marked <strong>sent</strong> in {{ config('app.name') }}.
    </p>
    <p style="margin: 0 0 8px;">
        <strong>Total:</strong>
        @php
            $sym = $invoice->currency === 'USD' ? '$' : $invoice->currency.' ';
        @endphp
        {{ $sym }}{{ number_format(((int) $invoice->total_cents) / 100, 2) }}
        &nbsp;·&nbsp;
        <strong>Balance due:</strong>
        {{ $sym }}{{ number_format(((int) $invoice->balance_due_cents) / 100, 2) }}
    </p>
    @if ($invoice->due_at)
        <p style="margin: 0 0 12px; color: #555;">
            <strong>Due:</strong> {{ $invoice->due_at->format('F j, Y') }}
        </p>
    @endif
    @if (!empty($customerViewUrl))
        <p style="margin: 0 0 12px;">
            <a href="{{ $customerViewUrl }}">View Invoice</a>
        </p>
    @endif
</body>
</html>
