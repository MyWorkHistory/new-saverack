<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Public HTTPS URLs for Slack status truck icons (must be fetchable by Slack).
 */
class SlackStatusIconUrlService
{
    private const LIVE_FILE = 'shipping-status-live.png';

    private const PAUSED_FILE = 'shipping-status-paused.png';

    private const STORAGE_DIR = 'slack-status-icons';

    public function liveUrl(): string
    {
        return $this->resolveUrl(self::LIVE_FILE, 'billing.slack.status_icon_live_url');
    }

    public function pausedUrl(): string
    {
        return $this->resolveUrl(self::PAUSED_FILE, 'billing.slack.status_icon_paused_url');
    }

    private function resolveUrl(string $filename, string $configKey): string
    {
        $explicit = config($configKey);
        if (is_string($explicit) && trim($explicit) !== '') {
            return trim($explicit);
        }

        $this->ensurePublished($filename);

        $relative = '/storage/'.self::STORAGE_DIR.'/'.$filename;
        $base = $this->publicBaseUrl();
        if ($base === '') {
            return url($relative);
        }

        return $base.$relative;
    }

    private function ensurePublished(string $filename): void
    {
        $disk = Storage::disk('public');
        $dest = self::STORAGE_DIR.'/'.$filename;
        if ($disk->exists($dest)) {
            return;
        }

        $source = public_path('images/slack/'.$filename);
        if (! is_file($source)) {
            return;
        }

        $disk->makeDirectory(self::STORAGE_DIR);
        $disk->put($dest, File::get($source));
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
