<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Fulfillment Pricing</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #2f2f2f;
            margin: 28px 32px;
            line-height: 1.45;
        }
        h1 {
            font-size: 18px;
            margin: 0 0 6px;
            color: #1f2430;
        }
        .meta {
            margin: 0 0 20px;
            color: #5c6370;
            font-size: 11px;
        }
        .meta strong { color: #1f2430; }
        .empty {
            margin-top: 36px;
            text-align: center;
            color: #5c6370;
            font-size: 12px;
        }
        .fee-row {
            padding: 10px 0;
            border-bottom: 1px solid #e5e7eb;
            page-break-inside: avoid;
        }
        .fee-row:first-of-type { border-top: 1px solid #e5e7eb; }
        .fee-name {
            font-size: 12px;
            font-weight: 700;
            color: #1f2430;
            margin: 0 0 2px;
        }
        .fee-desc {
            margin: 0 0 4px;
            color: #5c6370;
            font-size: 10px;
        }
        .fee-amount {
            font-size: 12px;
            color: #1f2430;
            font-weight: 600;
        }
        .fee-category {
            font-size: 10px;
            color: #6b7280;
            margin: 0 0 2px;
        }
    </style>
</head>
<body>
    <h1>Fulfillment Pricing</h1>
    <p class="meta">
        <strong>Account:</strong> {{ $accountName }}<br>
        <strong>Date:</strong> {{ $dateLabel }}
    </p>

    @if (!$approved)
        <p class="empty">{{ $emptyMessage }}</p>
    @else
        @foreach ($fees as $fee)
            @php
                $name = is_array($fee) ? (string) ($fee['name'] ?? 'Fee') : 'Fee';
                $description = is_array($fee) ? (string) ($fee['description'] ?? '') : '';
                $category = is_array($fee) ? (string) ($fee['category_label'] ?? $fee['category'] ?? '') : '';
                $amount = is_array($fee) && array_key_exists('amount', $fee) ? $fee['amount'] : null;
                $categoryKey = is_array($fee) ? strtolower((string) ($fee['category'] ?? '')) : '';
                $decimals = $categoryKey === 'storage' ? 3 : 2;
                $amountLabel = $amount === null || $amount === ''
                    ? '—'
                    : '$'.number_format((float) $amount, $decimals, '.', '');
            @endphp
            <div class="fee-row">
                @if ($category !== '')
                    <div class="fee-category">{{ $category }}</div>
                @endif
                <div class="fee-name">{{ $name }}</div>
                @if (trim($description) !== '')
                    <p class="fee-desc">{{ $description }}</p>
                @endif
                <div class="fee-amount">{{ $amountLabel }}</div>
            </div>
        @endforeach
    @endif
</body>
</html>
