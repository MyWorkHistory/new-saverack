<?php

namespace App\Services;

/**
 * Public HTTPS URLs for Slack status truck icons (must be fetchable by Slack).
 *
 * Icons are served as static files under /images/slack/ (no storage:link required).
 */
class SlackStatusIconUrlService
{
    private const LIVE_FILE = 'shipping-status-live.png';

    private const PAUSED_FILE = 'shipping-status-paused.png';

    private const LIVE_THUMB_FILE = 'shipping-status-live-thumb.png';

    private const PAUSED_THUMB_FILE = 'shipping-status-paused-thumb.png';

    /** @var string Relative to site root; nginx/Laravel serves public/images/slack/ directly. */
    private const PUBLIC_PATH = '/images/slack';

    public function liveUrl(): string
    {
        return $this->resolveUrl(self::LIVE_FILE, 'billing.slack.status_icon_live_url');
    }

    public function pausedUrl(): string
    {
        return $this->resolveUrl(self::PAUSED_FILE, 'billing.slack.status_icon_paused_url');
    }

    public function liveThumbUrl(): string
    {
        return $this->resolveUrl(self::LIVE_THUMB_FILE, 'billing.slack.status_icon_live_thumb_url');
    }

    public function pausedThumbUrl(): string
    {
        return $this->resolveUrl(self::PAUSED_THUMB_FILE, 'billing.slack.status_icon_paused_thumb_url');
    }

    private function resolveUrl(string $filename, string $configKey): string
    {
        $explicit = config($configKey);
        if (is_string($explicit) && trim($explicit) !== '') {
            return trim($explicit);
        }

        $relative = self::PUBLIC_PATH.'/'.$filename;
        $base = $this->publicBaseUrl();
        if ($base === '') {
            return url($relative);
        }

        return $base.$relative;
    }

    private function publicBaseUrl(): string
    {
        $base = rtrim((string) config('billing.slack.public_asset_base_url'), '/');
        if ($base === '') {
            $base = rtrim((string) config('app.url'), '/');
        }
        if ($base === '') {
            $base = rtrim((string) config('crm.frontend_url'), '/');
        }

        if (str_starts_with($base, 'http://')) {
            $base = 'https://'.substr($base, 7);
        }

        if ($base === '' || $this->isNonPublicHost(parse_url($base, PHP_URL_HOST))) {
            return '';
        }

        return $base;
    }

    private function isNonPublicHost(?string $host): bool
    {
        if (! is_string($host) || $host === '') {
            return true;
        }

        $host = strtolower($host);

        return $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || $host === '127.0.0.1'
            || str_starts_with($host, '127.');
    }
}
