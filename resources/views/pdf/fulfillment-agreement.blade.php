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
            margin-top: 26px;
            padding-top: 16px;
            border-top: 1px solid #d8d6de;
        }
        .sig-section-title {
            font-size: 13px;
            font-weight: 700;
            margin: 0 0 14px;
            color: #1f2430;
        }
        .sig-field {
            margin: 0 0 16px;
            width: 100%;
        }
        .sig-field-label {
            font-size: 11px;
            color: #1f2430;
            margin: 0 0 4px;
        }
        .sig-field-line {
            border-bottom: 1px solid #4b4b4b;
            height: 28px;
            line-height: 28px;
            padding: 0 2px 2px;
            font-size: 12px;
            color: #1f2430;
        }
        .sig-field-line .dots {
            color: #9aa0a6;
            letter-spacing: 0.12em;
            font-size: 11px;
        }
        .sig-image {
            max-height: 40px;
            max-width: 260px;
            vertical-align: middle;
        }
    </style>
</head>
<body>
    <h1>Fulfillment Agreement</h1>

    <div class="agreement-body">
        {!! $bodyHtml !!}
    </div>

    <div class="sig-section">
        <div class="sig-field">
            <div class="sig-field-label">Company Name</div>
            <div class="sig-field-line">
                @if (!empty($client['company']))
                    {{ $client['company'] }}
                @else
                    <span class="dots">.............................................</span>
                @endif
            </div>
        </div>
        <div class="sig-field">
            <div class="sig-field-label">Authorized Representative</div>
            <div class="sig-field-line">
                @if (!empty($client['rep_name']))
                    {{ $client['rep_name'] }}
                @else
                    <span class="dots">.............................................</span>
                @endif
            </div>
        </div>
        <div class="sig-field">
            <div class="sig-field-label">Date</div>
            <div class="sig-field-line">
                @if (!empty($client['signed_at_label']))
                    {{ $client['signed_at_label'] }}
                @else
                    <span class="dots">.............................................</span>
                @endif
            </div>
        </div>
        <div class="sig-field">
            <div class="sig-field-label">Signature</div>
            <div class="sig-field-line">
                @if (!empty($client['signature_data_uri']))
                    <img class="sig-image" src="{{ $client['signature_data_uri'] }}" alt="Client signature">
                @elseif (!empty($client['signature_text']))
                    {{ $client['signature_text'] }}
                @else
                    <span class="dots">.............................................</span>
                @endif
            </div>
        </div>
    </div>

    <div class="sig-section">
        <div class="sig-section-title">Save Rack LLC</div>
        <div class="sig-field">
            <div class="sig-field-label">Authorized Representative</div>
            <div class="sig-field-line">
                @if (!empty($staff['rep_name']))
                    {{ $staff['rep_name'] }}
                @else
                    <span class="dots">.............................................</span>
                @endif
            </div>
        </div>
        <div class="sig-field">
            <div class="sig-field-label">Date</div>
            <div class="sig-field-line">
                @if (!empty($staff['signed_at_label']))
                    {{ $staff['signed_at_label'] }}
                @else
                    <span class="dots">.............................................</span>
                @endif
            </div>
        </div>
        <div class="sig-field">
            <div class="sig-field-label">Signature</div>
            <div class="sig-field-line">
                @if (!empty($staff['signature_data_uri']))
                    <img class="sig-image" src="{{ $staff['signature_data_uri'] }}" alt="Save Rack signature">
                @elseif (!empty($staff['signature_text']))
                    {{ $staff['signature_text'] }}
                @else
                    <span class="dots">.............................................</span>
                @endif
            </div>
        </div>
    </div>
</body>
</html>
