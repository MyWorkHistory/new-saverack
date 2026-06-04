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

    /** @var string Legacy storage path that often 404s without storage:link. */
    private const LEGACY_STORAGE_PATH = '/storage/slack-status-icons/';

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
        return $this->resolveThumbUrl(
            self::LIVE_THUMB_FILE,
            self::LIVE_FILE,
            'billing.slack.status_icon_live_thumb_url',
            'billing.slack.status_icon_live_url'
        );
    }

    public function pausedThumbUrl(): string
    {
        return $this->resolveThumbUrl(
            self::PAUSED_THUMB_FILE,
            self::PAUSED_FILE,
            'billing.slack.status_icon_paused_thumb_url',
            'billing.slack.status_icon_paused_url'
        );
    }

    /**
     * Prefer avatar-sized thumb; fall back to full icon when thumb is missing or misconfigured.
     */
    private function resolveThumbUrl(
        string $thumbFilename,
        string $fullFilename,
        string $thumbConfigKey,
        string $fullConfigKey
    ): string {
        $explicitThumb = config($thumbConfigKey);
        if (is_string($explicitThumb) && trim($explicitThumb) !== '') {
            return $this->remapLegacyStorageUrl(trim($explicitThumb), $thumbFilename, $fullFilename);
        }

        if (is_file(public_path('images/slack/'.$thumbFilename))) {
            return $this->buildPublicUrl($thumbFilename);
        }

        return $this->resolveUrl($fullFilename, $fullConfigKey);
    }

    private function resolveUrl(string $filename, string $configKey): string
    {
        $explicit = config($configKey);
        if (is_string($explicit) && trim($explicit) !== '') {
            return $this->remapLegacyStorageUrl(trim($explicit), $filename, $filename);
        }

        return $this->buildPublicUrl($filename);
    }

    /**
     * Old deployments pointed at /storage/slack-status-icons/ which often 404s.
     * Prefer /images/slack/ when the file exists in public/.
     */
    private function remapLegacyStorageUrl(string $url, string $primaryFilename, string $fallbackFilename): string
    {
        if (! str_contains($url, self::LEGACY_STORAGE_PATH)) {
            return $url;
        }

        foreach ([$primaryFilename, $fallbackFilename] as $filename) {
            if (is_file(public_path('images/slack/'.$filename))) {
                return $this->buildPublicUrl($filename);
            }
        }

        return $url;
    }

    private function buildPublicUrl(string $filename): string
    {
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
