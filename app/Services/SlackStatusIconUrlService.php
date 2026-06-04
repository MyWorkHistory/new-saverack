<?php

namespace App\Services;

/**
 * Public HTTPS URLs for Slack status truck icons (must be fetchable by Slack).
 *
 * Icons are served as static files under public/images/slack/ (no storage:link required).
 */
class SlackStatusIconUrlService
{
    private const LIVE_BASENAME = 'shipping-status-live.png';

    private const PAUSED_BASENAME = 'shipping-status-paused.png';

    /** @var string Relative to site root. */
    private const PUBLIC_PATH = '/images/slack';

    private const AVATAR_PATH = '/images/slack/avatars';

    /**
     * Best URL for the small avatar beside "Shipping Status Update".
     * Prefers dedicated avatar PNGs, then full icon, then thumb.
     */
    public function slackAvatarUrl(bool $isLive): string
    {
        if ($isLive) {
            return $this->resolveIconUrl(
                'billing.slack.status_icon_live_url',
                [
                    self::AVATAR_PATH.'/'.self::LIVE_BASENAME,
                    self::PUBLIC_PATH.'/'.self::LIVE_BASENAME,
                    self::PUBLIC_PATH.'/shipping-status-live-thumb.png',
                ]
            );
        }

        return $this->resolveIconUrl(
            'billing.slack.status_icon_paused_url',
            [
                self::AVATAR_PATH.'/'.self::PAUSED_BASENAME,
                self::PUBLIC_PATH.'/'.self::PAUSED_BASENAME,
                self::PUBLIC_PATH.'/shipping-status-paused-thumb.png',
            ]
        );
    }

    public function liveUrl(): string
    {
        return $this->resolveIconUrl('billing.slack.status_icon_live_url', [
            self::PUBLIC_PATH.'/'.self::LIVE_BASENAME,
        ]);
    }

    public function pausedUrl(): string
    {
        return $this->resolveIconUrl('billing.slack.status_icon_paused_url', [
            self::PUBLIC_PATH.'/'.self::PAUSED_BASENAME,
        ]);
    }

    public function liveThumbUrl(): string
    {
        return $this->resolveIconUrl('billing.slack.status_icon_live_thumb_url', [
            self::PUBLIC_PATH.'/shipping-status-live-thumb.png',
            self::AVATAR_PATH.'/'.self::LIVE_BASENAME,
            self::PUBLIC_PATH.'/'.self::LIVE_BASENAME,
        ]);
    }

    public function pausedThumbUrl(): string
    {
        return $this->resolveIconUrl('billing.slack.status_icon_paused_thumb_url', [
            self::PUBLIC_PATH.'/shipping-status-paused-thumb.png',
            self::AVATAR_PATH.'/'.self::PAUSED_BASENAME,
            self::PUBLIC_PATH.'/'.self::PAUSED_BASENAME,
        ]);
    }

    /**
     * @param  list<string>  $relativePaths
     */
    private function resolveIconUrl(string $configKey, array $relativePaths): string
    {
        $explicit = config($configKey);
        if (is_string($explicit) && trim($explicit) !== '') {
            return trim($explicit);
        }

        $base = $this->publicBaseUrl();

        foreach ($relativePaths as $relative) {
            if ($this->iconFileExists($relative)) {
                return ($base !== '' ? $base : '').$relative;
            }
        }

        $fallback = $relativePaths[0] ?? '';

        return $base !== '' ? $base.$fallback : url($fallback);
    }

    private function iconFileExists(string $relativePath): bool
    {
        $relativePath = ltrim($relativePath, '/');
        if (str_starts_with($relativePath, 'images/slack/')) {
            $relativePath = substr($relativePath, strlen('images/slack/'));
        }

        return is_file(public_path('images/slack/'.$relativePath));
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
