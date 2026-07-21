<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title ?? 'Save Rack Fulfillment Pricing' }}</title>
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
        .fee-list {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 10px;
        }
        .fee-row {
            width: 100%;
            border: 1px solid #e8e7ed;
            border-radius: 6px;
            background: #ffffff;
            page-break-inside: avoid;
        }
        .fee-row td {
            padding: 12px;
            vertical-align: top;
        }
        .fee-row .fee-icon-cell {
            width: 58px;
            padding-right: 0;
        }
        .fee-icon {
            width: 48px;
            height: 48px;
            border-radius: 5px;
            background: #f1f3f5;
            text-align: center;
            overflow: hidden;
        }
        .fee-icon img {
            display: block;
            width: 48px;
            height: 48px;
            object-fit: contain;
        }
        .fee-icon-fallback {
            color: #64748b;
            font-size: 19px;
            font-weight: 700;
            line-height: 48px;
            text-transform: uppercase;
        }
        .fee-main {
            width: auto;
        }
        .fee-heading {
            margin: 0 0 5px;
            white-space: nowrap;
        }
        .fee-name {
            font-size: 12px;
            font-weight: 600;
            color: #1f2430;
            margin-right: 6px;
        }
        .fee-desc {
            margin: 0;
            color: #5c6370;
            font-size: 10px;
            line-height: 1.45;
        }
        .fee-price-cell {
            width: 88px;
            text-align: right;
            white-space: nowrap;
        }
        .fee-amount {
            font-size: 13px;
            color: #1f2430;
            font-weight: 700;
        }
        .fee-category {
            display: inline;
            padding: 2px 6px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: #f1f5f9;
            color: #475569;
            font-size: 8px;
            font-weight: 600;
        }
        .fee-category--fulfillment {
            color: #1d4ed8; background: #dbeafe; border-color: #bfdbfe;
        }
        .fee-category--returns {
            color: #b45309; background: #fef3c7; border-color: #fde68a;
        }
        .fee-category--storage {
            color: #0f766e; background: #ccfbf1; border-color: #99f6e4;
        }
        .fee-category--receiving {
            color: #7c2d12; background: #ffedd5; border-color: #fdba74;
        }
        .fee-category--custom_work {
            color: #6b21a8; background: #f3e8ff; border-color: #e9d5ff;
        }
        .fee-category--wholesale {
            color: #1e3a8a; background: #dbeafe; border-color: #93c5fd;
        }
        .fee-category--packaging {
            color: #0369a1; background: #e0f2fe; border-color: #7dd3fc;
        }
        .fee-category--amazon {
            color: #c2410c; background: #ffedd5; border-color: #fdba74;
        }
    </style>
</head>
<body>
    <h1>{{ $title ?? 'Save Rack Fulfillment Pricing' }}</h1>
    <p class="meta">
        <strong>Account Name:</strong> {{ $accountName }}<br>
        <strong>Date:</strong> {{ $dateLabel }}
    </p>

    @if (!$approved)
        <p class="empty">{{ $emptyMessage }}</p>
    @elseif (empty($fees))
        <p class="empty">{{ $emptyMessage }}</p>
    @else
        <table class="fee-list" cellspacing="0" cellpadding="0">
            <tbody>
                @foreach ($fees as $fee)
                    @php
                        $name = is_array($fee) ? (string) ($fee['name'] ?? 'Fee') : 'Fee';
                        $description = is_array($fee) ? (string) ($fee['description'] ?? '') : '';
                        $category = is_array($fee) ? (string) ($fee['category_label'] ?? $fee['category'] ?? '') : '';
                        $categoryKey = is_array($fee) ? strtolower((string) ($fee['category'] ?? '')) : '';
                        $amount = is_array($fee) && array_key_exists('amount', $fee) ? $fee['amount'] : null;
                        $icon = is_array($fee) ? ($fee['icon_data_uri'] ?? null) : null;
                        $decimals = $categoryKey === 'storage' ? 3 : 2;
                        $amountLabel = $amount === null || $amount === ''
                            ? '—'
                            : '$'.number_format((float) $amount, $decimals, '.', '');
                    @endphp
                    <tr class="fee-row">
                        <td class="fee-icon-cell">
                            <div class="fee-icon">
                                @if (is_string($icon) && $icon !== '')
                                    <img src="{{ $icon }}" alt="">
                                @else
                                    <div class="fee-icon-fallback">{{ mb_substr($category !== '' ? $category : $name, 0, 1) }}</div>
                                @endif
                            </div>
                        </td>
                        <td class="fee-main">
                            <div class="fee-heading">
                                <span class="fee-name">{{ $name }}</span>
                                @if ($category !== '')
                                    <span class="fee-category fee-category--{{ $categoryKey }}">{{ $category }}</span>
                                @endif
                            </div>
                            <p class="fee-desc">
                                {{ trim($description) !== '' ? $description : 'No description' }}
                            </p>
                        </td>
                        <td class="fee-price-cell">
                            <span class="fee-amount">{{ $amountLabel }}</span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
