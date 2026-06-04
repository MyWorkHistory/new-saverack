<?php

namespace App\Services;

/**
 * Public HTTPS URLs for Slack status truck icons (must be fetchable by Slack).
 */
class SlackStatusIconUrlService
{
    private const LIVE_FILE = 'shipping-status-live.png';

    private const PAUSED_FILE = 'shipping-status-paused.png';

    private const LIVE_THUMB_FILE = 'shipping-status-live-thumb.png';

    private const PAUSED_THUMB_FILE = 'shipping-status-paused-thumb.png';

    /** Served by SlackStatusIconController — works even when static /images/slack/ 404s. */
    private const API_PATH = '/api/slack/status-icons';

    private const LEGACY_STORAGE_PATH = '/storage/slack-status-icons/';

    public function avatarUrl(bool $isLive): string
    {
        $thumb = $isLive ? self::LIVE_THUMB_FILE : self::PAUSED_THUMB_FILE;
        $full = $isLive ? self::LIVE_FILE : self::PAUSED_FILE;

        foreach ([$thumb, $full] as $filename) {
            if ($this->localFileExists($filename)) {
                $url = $this->buildApiUrl($filename);
                if ($url !== '') {
                    return $url;
                }
            }
        }

        return $this->resolveConfiguredUrl(
            $isLive ? self::LIVE_THUMB_FILE : self::PAUSED_THUMB_FILE,
            $isLive ? self::LIVE_FILE : self::PAUSED_FILE,
            $isLive ? 'billing.slack.status_icon_live_thumb_url' : 'billing.slack.status_icon_paused_thumb_url',
            $isLive ? 'billing.slack.status_icon_live_url' : 'billing.slack.status_icon_paused_url'
        );
    }

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
        return $this->avatarUrl(true);
    }

    public function pausedThumbUrl(): string
    {
        return $this->avatarUrl(false);
    }

    private function resolveUrl(string $filename, string $configKey): string
    {
        if ($this->localFileExists($filename)) {
            $url = $this->buildApiUrl($filename);
            if ($url !== '') {
                return $url;
            }
        }

        $explicit = config($configKey);
        if (is_string($explicit) && trim($explicit) !== '') {
            return $this->remapLegacyStorageUrl(trim($explicit), $filename, $filename);
        }

        return $this->buildApiUrl($filename);
    }

    private function resolveConfiguredUrl(
        string $thumbFilename,
        string $fullFilename,
        string $thumbConfigKey,
        string $fullConfigKey
    ): string {
        foreach ([$thumbConfigKey => $thumbFilename, $fullConfigKey => $fullFilename] as $configKey => $filename) {
            $explicit = config($configKey);
            if (is_string($explicit) && trim($explicit) !== '') {
                $url = $this->remapLegacyStorageUrl(trim($explicit), $filename, $fullFilename);
                if ($this->isPublicHttpsUrl($url)) {
                    return $url;
                }
            }
        }

        return '';
    }

    private function remapLegacyStorageUrl(string $url, string $primaryFilename, string $fallbackFilename): string
    {
        if (! str_contains($url, self::LEGACY_STORAGE_PATH)) {
            return $url;
        }

        foreach ([$primaryFilename, $fallbackFilename] as $filename) {
            if ($this->localFileExists($filename)) {
                $apiUrl = $this->buildApiUrl($filename);
                if ($apiUrl !== '') {
                    return $apiUrl;
                }
            }
        }

        return $url;
    }

    private function buildApiUrl(string $filename): string
    {
        $base = $this->publicBaseUrl();
        if ($base === '') {
            return '';
        }

        return $base.self::API_PATH.'/'.$filename;
    }

    private function isPublicHttpsUrl(string $url): bool
    {
        if (! str_starts_with(strtolower($url), 'https://')) {
            return false;
        }

        return ! $this->isNonPublicHost(parse_url($url, PHP_URL_HOST));
    }

    private function localFileExists(string $filename): bool
    {
        return is_file(public_path('images/slack/'.$filename));
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
