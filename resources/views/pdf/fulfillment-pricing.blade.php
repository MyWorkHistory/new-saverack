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
            margin: 24px 28px;
            line-height: 1.45;
        }
        h1 {
            font-size: 18px;
            margin: 0 0 6px;
            color: #1f2430;
        }
        .meta {
            margin: 0 0 18px;
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
        .category-banner {
            margin: 18px 0 8px;
            padding: 12px 14px;
            border-radius: 8px;
            color: #ffffff;
            page-break-inside: avoid;
        }
        .category-banner:first-of-type {
            margin-top: 4px;
        }
        .category-banner-title {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            margin: 0 0 2px;
        }
        .category-banner-sub {
            font-size: 10px;
            margin: 0;
            opacity: 0.92;
        }
        .fee-list {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
        }
        .fee-row {
            width: 100%;
            border: 1px solid #e8e7ed;
            border-radius: 8px;
            background: #ffffff;
            page-break-inside: avoid;
        }
        .fee-row td {
            padding: 10px 12px;
            vertical-align: middle;
        }
        .fee-row .fee-icon-cell {
            width: 58px;
            padding-right: 0;
        }
        .fee-icon {
            width: 48px;
            height: 48px;
            border-radius: 6px;
            background: #f8fafc;
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
            margin: 0;
        }
        .fee-name {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #1f2430;
            margin: 0 0 4px;
        }
        .fee-divider-cell {
            width: 12px;
            padding-left: 0;
            padding-right: 0;
            text-align: center;
        }
        .fee-divider {
            display: inline-block;
            width: 1px;
            height: 36px;
            background: #e8e7ed;
        }
        .fee-price-cell {
            width: 92px;
            text-align: right;
            white-space: nowrap;
        }
        .fee-amount {
            font-size: 15px;
            color: #2563eb;
            font-weight: 800;
        }
        .fee-category {
            display: inline-block;
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
        .banner--fulfillment { background: #2563eb; }
        .banner--returns { background: #d97706; }
        .banner--storage { background: #0d9488; }
        .banner--receiving { background: #ea580c; }
        .banner--custom_work { background: #7c3aed; }
        .banner--wholesale { background: #1d4ed8; }
        .banner--packaging { background: #0284c7; }
        .banner--amazon { background: #ea580c; }
        .banner--postage { background: #475569; }
        .banner--other { background: #64748b; }
    </style>
</head>
<body>
    @php
        $categoryOrder = ['fulfillment', 'returns', 'storage', 'receiving', 'custom_work', 'wholesale', 'packaging', 'amazon', 'postage'];
        $categoryMeta = [
            'fulfillment' => ['label' => 'Fulfillment', 'subtitle' => 'Pick, pack, and ship orders per unit.'],
            'returns' => ['label' => 'Returns', 'subtitle' => 'Processing, labels, and restocking for returns.'],
            'storage' => ['label' => 'Storage', 'subtitle' => 'Warehouse storage and inventory holding fees.'],
            'receiving' => ['label' => 'Receiving', 'subtitle' => 'Inbound ASN and receiving labor charges.'],
            'custom_work' => ['label' => 'Custom Work', 'subtitle' => 'Special projects and non-standard services.'],
            'wholesale' => ['label' => 'Wholesale', 'subtitle' => 'Wholesale order handling and processing.'],
            'packaging' => ['label' => 'Packaging', 'subtitle' => 'Boxes, mailers, and packaging materials.'],
            'amazon' => ['label' => 'Amazon', 'subtitle' => 'Amazon FBA prep and labeling services.'],
            'postage' => ['label' => 'Postage', 'subtitle' => 'Carrier postage fees.'],
        ];
        $grouped = [];
        foreach (($fees ?? []) as $fee) {
            if (! is_array($fee)) {
                continue;
            }
            $key = strtolower(trim((string) ($fee['category'] ?? 'other')));
            if ($key === '') {
                $key = 'other';
            }
            if (! isset($grouped[$key])) {
                $grouped[$key] = [];
            }
            $grouped[$key][] = $fee;
        }
        $sections = [];
        foreach ($categoryOrder as $key) {
            if (! empty($grouped[$key])) {
                $sections[$key] = $grouped[$key];
                unset($grouped[$key]);
            }
        }
        foreach ($grouped as $key => $rows) {
            $sections[$key] = $rows;
        }
    @endphp

    <h1>{{ $title ?? 'Save Rack Fulfillment Pricing' }}</h1>
    <p class="meta">
        <strong>Account Name:</strong> {{ $accountName }}<br>
        <strong>Date:</strong> {{ $dateLabel }}
    </p>

    @if (!$approved)
        <p class="empty">{{ $emptyMessage }}</p>
    @elseif (empty($sections))
        <p class="empty">{{ $emptyMessage }}</p>
    @else
        @foreach ($sections as $categoryKey => $sectionFees)
            @php
                $meta = $categoryMeta[$categoryKey] ?? [
                    'label' => ucwords(str_replace('_', ' ', (string) $categoryKey)),
                    'subtitle' => '',
                ];
                $bannerClass = 'banner--'.(isset($categoryMeta[$categoryKey]) ? $categoryKey : 'other');
            @endphp
            <div class="category-banner {{ $bannerClass }}">
                <p class="category-banner-title">{{ strtoupper($meta['label']) }} FEES</p>
                @if ($meta['subtitle'] !== '')
                    <p class="category-banner-sub">{{ $meta['subtitle'] }}</p>
                @endif
            </div>
            <table class="fee-list" cellspacing="0" cellpadding="0">
                <tbody>
                    @foreach ($sectionFees as $fee)
                        @php
                            $name = (string) ($fee['name'] ?? 'Fee');
                            $category = (string) ($fee['category_label'] ?? $fee['category'] ?? '');
                            $amount = array_key_exists('amount', $fee) ? $fee['amount'] : null;
                            $icon = $fee['icon_data_uri'] ?? null;
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
                            </td>
                            <td class="fee-divider-cell">
                                <span class="fee-divider"></span>
                            </td>
                            <td class="fee-price-cell">
                                <span class="fee-amount">{{ $amountLabel }}</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endforeach
    @endif
</body>
</html>
