<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Fulfillment Agreement</title>
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
            margin: 0 0 14px;
            color: #1f2430;
        }
        .agreement-body {
            margin-bottom: 28px;
        }
        .agreement-body p { margin: 0 0 8px; }
        .agreement-body ul, .agreement-body ol {
            margin: 0 0 8px;
            padding-left: 18px;
        }
        .agreement-body h2, .agreement-body h3, .agreement-body h4 {
            font-size: 13px;
            margin: 12px 0 6px;
            color: #1f2430;
        }
        .sig-section {
            page-break-inside: avoid;
            margin-top: 22px;
            padding-top: 14px;
            border-top: 1px solid #d8d6de;
        }
        .sig-section-title {
            font-size: 13px;
            font-weight: 700;
            margin: 0 0 12px;
            color: #1f2430;
        }
        .sig-row {
            width: 100%;
            margin-bottom: 14px;
        }
        .sig-row td {
            vertical-align: bottom;
            padding: 0 8px 0 0;
            width: 50%;
        }
        .sig-label {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #6c757d;
            margin-bottom: 4px;
        }
        .sig-line {
            border-bottom: 1px solid #4b4b4b;
            min-height: 28px;
            padding: 2px 0 4px;
        }
        .sig-value {
            font-size: 12px;
            color: #1f2430;
        }
        .sig-image {
            max-height: 42px;
            max-width: 240px;
        }
        .sig-placeholder {
            color: #adb5bd;
            font-size: 10px;
        }
    </style>
</head>
<body>
    <h1>Fulfillment Agreement</h1>

    <div class="agreement-body">
        {!! $bodyHtml !!}
    </div>

    <div class="sig-section">
        <div class="sig-section-title">Client</div>
        <table class="sig-row">
            <tr>
                <td>
                    <div class="sig-label">Company Name</div>
                    <div class="sig-line">
                        @if (!empty($client['company']))
                            <span class="sig-value">{{ $client['company'] }}</span>
                        @endif
                    </div>
                </td>
                <td>
                    <div class="sig-label">Authorized Representative</div>
                    <div class="sig-line">
                        @if (!empty($client['rep_name']))
                            <span class="sig-value">{{ $client['rep_name'] }}</span>
                        @endif
                    </div>
                </td>
            </tr>
        </table>
        <table class="sig-row">
            <tr>
                <td>
                    <div class="sig-label">Date</div>
                    <div class="sig-line">
                        @if (!empty($client['signed_at_label']))
                            <span class="sig-value">{{ $client['signed_at_label'] }}</span>
                        @endif
                    </div>
                </td>
                <td>
                    <div class="sig-label">Signature</div>
                    <div class="sig-line">
                        @if (!empty($client['signature_data_uri']))
                            <img class="sig-image" src="{{ $client['signature_data_uri'] }}" alt="Client signature">
                        @elseif (!empty($client['signature_text']))
                            <span class="sig-value">{{ $client['signature_text'] }}</span>
                        @endif
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="sig-section">
        <div class="sig-section-title">Save Rack LLC</div>
        <table class="sig-row">
            <tr>
                <td>
                    <div class="sig-label">Authorized Representative</div>
                    <div class="sig-line">
                        @if (!empty($staff['rep_name']))
                            <span class="sig-value">{{ $staff['rep_name'] }}</span>
                        @endif
                    </div>
                </td>
                <td>
                    <div class="sig-label">Date</div>
                    <div class="sig-line">
                        @if (!empty($staff['signed_at_label']))
                            <span class="sig-value">{{ $staff['signed_at_label'] }}</span>
                        @endif
                    </div>
                </td>
            </tr>
        </table>
        <table class="sig-row">
            <tr>
                <td colspan="2">
                    <div class="sig-label">Signature</div>
                    <div class="sig-line">
                        @if (!empty($staff['signature_data_uri']))
                            <img class="sig-image" src="{{ $staff['signature_data_uri'] }}" alt="Save Rack signature">
                        @elseif (!empty($staff['signature_text']))
                            <span class="sig-value">{{ $staff['signature_text'] }}</span>
                        @endif
                    </div>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
