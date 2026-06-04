<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

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

    private const API_PATH = '/api/slack/status-icons';

    public function liveUrl(): string
    {
        return $this->resolveUrl(self::LIVE_FILE, self::PUBLIC_PATH, 'billing.slack.status_icon_live_url');
    }

    public function pausedUrl(): string
    {
        return $this->resolveUrl(self::PAUSED_FILE, self::PUBLIC_PATH, 'billing.slack.status_icon_paused_url');
    }

    public function liveThumbUrl(): string
    {
        return $this->resolveUrl(self::LIVE_THUMB_FILE, self::PUBLIC_PATH, 'billing.slack.status_icon_live_thumb_url');
    }

    public function pausedThumbUrl(): string
    {
        return $this->resolveUrl(self::PAUSED_THUMB_FILE, self::PUBLIC_PATH, 'billing.slack.status_icon_paused_thumb_url');
    }

    public function liveApiUrl(): string
    {
        return $this->resolveUrl(self::LIVE_FILE, self::API_PATH, '');
    }

    public function pausedApiUrl(): string
    {
        return $this->resolveUrl(self::PAUSED_FILE, self::API_PATH, '');
    }

    /**
     * First reachable icon URL: thumb → full PNG → API route.
     */
    public function resolveReachableIconUrl(bool $isLive): string
    {
        $candidates = $isLive
            ? [$this->liveThumbUrl(), $this->liveUrl(), $this->liveApiUrl()]
            : [$this->pausedThumbUrl(), $this->pausedUrl(), $this->pausedApiUrl()];

        foreach ($candidates as $url) {
            if ($url !== '' && $this->isReachableImage($url)) {
                return $url;
            }
        }

        return $candidates[0] ?? '';
    }

    public function isReachableImage(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        try {
            $response = Http::timeout(5)->head($url);
            if (! $response->successful()) {
                $response = Http::timeout(5)->get($url);
            }

            $contentType = strtolower(trim((string) $response->header('Content-Type')));

            return $response->successful() && str_contains($contentType, 'image');
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function resolveUrl(string $filename, string $pathPrefix, string $configKey): string
    {
        if ($configKey !== '') {
            $explicit = config($configKey);
            if (is_string($explicit) && trim($explicit) !== '') {
                return trim($explicit);
            }
        }

        $relative = $pathPrefix.'/'.$filename;
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
