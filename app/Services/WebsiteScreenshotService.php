<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Lead website thumbnails via thum.io URL API (no API key).
 *
 * @see https://www.thum.io/documentation/api/url
 */
class WebsiteScreenshotService
{
    /**
     * Capture a website screenshot and return image bytes.
     *
     * @throws ValidationException
     * @throws RuntimeException
     */
    public function captureImageBytes(string $website): string
    {
        $url = $this->normalizeWebsiteUrl($website);
        if ($url === null) {
            throw ValidationException::withMessages([
                'website' => ['A valid website URL is required to generate a thumbnail.'],
            ]);
        }

        // Prefer Open Graph image when thum.io can resolve one (usually a real brand image).
        try {
            $ogBytes = $this->downloadThumImage($this->buildThumIoUrl($url, ['ogImage' => true]));
            if (! $this->isUnusableThumbnail($ogBytes)) {
                return $ogBytes;
            }
        } catch (RuntimeException $e) {
            Log::info('website_screenshot.ogimage_skipped', [
                'url' => $url,
                'message' => $e->getMessage(),
            ]);
        }

        // Warm cache, then fetch the final PNG (avoids saving the animated loader frame).
        $screenshotUrl = $this->buildThumIoUrl($url, ['ogImage' => false]);
        $this->prefetchThumImage($screenshotUrl);

        $bytes = $this->downloadThumImage($screenshotUrl);
        if ($this->isUnusableThumbnail($bytes)) {
            throw new RuntimeException(
                'Website screenshot came back blank. Try again, or upload a logo manually.'
            );
        }

        return $bytes;
    }

    /**
     * Build a thum.io URL per https://www.thum.io/documentation/api/url
     *
     * Example:
     * https://image.thum.io/get/width/1200/crop/1200/png/noanimate/https://example.com
     *
     * @param  array{ogImage?: bool}  $options
     */
    public function buildThumIoUrl(string $websiteUrl, array $options = []): string
    {
        $base = rtrim((string) config('services.thum_io.base_url', 'https://image.thum.io'), '/');
        $width = max(100, (int) config('services.thum_io.width', 1200));
        $crop = max(100, (int) config('services.thum_io.crop', 1200));
        $maxAgeHours = max(0, (int) config('services.thum_io.max_age_hours', 0));
        $useOgImage = ! empty($options['ogImage']);

        // Docs: modifiers are path segments placed BEFORE the target URL.
        $parts = ['get'];

        if ($useOgImage) {
            $parts[] = 'ogImage';
        }

        // Bust stale cache when regenerating thumbnails from the CRM.
        if ($maxAgeHours > 0) {
            $parts[] = 'maxAge';
            $parts[] = (string) $maxAgeHours;
        } else {
            $parts[] = 'refresh';
        }

        $parts[] = 'width';
        $parts[] = (string) $width;
        $parts[] = 'crop';
        $parts[] = (string) $crop;
        // Final static PNG — required for server-side downloads (docs: batch jobs).
        $parts[] = 'png';
        $parts[] = 'noanimate';

        // Append the raw website URL (docs primary format).
        return $base.'/'.implode('/', $parts).'/'.$websiteUrl;
    }

    private function prefetchThumImage(string $endpoint): void
    {
        // Docs: /prefetch/ queues render; follow-up request returns the final image.
        $prefetchEndpoint = preg_replace('#/get/#', '/get/prefetch/', $endpoint, 1);
        if (! is_string($prefetchEndpoint) || $prefetchEndpoint === $endpoint) {
            return;
        }

        try {
            Http::timeout(90)
                ->withHeaders([
                    'User-Agent' => 'SaveRackCRM/1.0',
                    'Accept' => '*/*',
                ])
                ->get($prefetchEndpoint);
        } catch (\Throwable $e) {
            Log::info('website_screenshot.prefetch_failed', [
                'message' => $e->getMessage(),
            ]);
        }

        // Brief settle so the final PNG is ready before we download.
        usleep(750000);
    }

    private function downloadThumImage(string $endpoint): string
    {
        $response = Http::timeout(120)
            ->withHeaders([
                'User-Agent' => 'SaveRackCRM/1.0',
                'Accept' => 'image/png,image/jpeg,image/*,*/*',
            ])
            ->get($endpoint);

        if ($response->status() === 429) {
            throw new RuntimeException('Screenshot service rate limit reached. Try again later.');
        }

        if (! $response->successful()) {
            Log::warning('website_screenshot.thum_http_error', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 300),
            ]);
            throw new RuntimeException('Could not capture website screenshot (HTTP '.$response->status().').');
        }

        $bytes = $response->body();
        if ($bytes === '' || strlen($bytes) < 32) {
            throw new RuntimeException('Screenshot service returned an empty response.');
        }

        // thum.io may return a plain-text status for prefetch/errors — never save that as a logo.
        $contentType = strtolower((string) $response->header('Content-Type'));
        if (str_contains($contentType, 'text/') || @imagecreatefromstring($bytes) === false) {
            throw new RuntimeException(
                'Screenshot was not ready yet. Wait a moment and try again.'
            );
        }

        return $bytes;
    }

    /**
     * Reject blank loaders / empty white frames / solid black shells.
     */
    private function isUnusableThumbnail(string $bytes): bool
    {
        if (! function_exists('imagecreatefromstring')) {
            return false;
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return true;
        }

        try {
            $width = imagesx($image);
            $height = imagesy($image);
            if ($width < 32 || $height < 32) {
                return true;
            }

            $stepX = max(1, (int) floor($width / 24));
            $stepY = max(1, (int) floor($height / 24));
            $samples = 0;
            $nearBlack = 0;
            $nearWhite = 0;
            $lumaSum = 0.0;
            $uniqueBuckets = [];

            for ($y = 0; $y < $height; $y += $stepY) {
                for ($x = 0; $x < $width; $x += $stepX) {
                    $rgb = imagecolorat($image, $x, $y);
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;
                    $luma = (0.2126 * $r) + (0.7152 * $g) + (0.0722 * $b);
                    $lumaSum += $luma;
                    $samples++;
                    if ($luma < 12) {
                        $nearBlack++;
                    }
                    if ($luma > 245) {
                        $nearWhite++;
                    }
                    $uniqueBuckets[((int) ($r / 32)).'-'.((int) ($g / 32)).'-'.((int) ($b / 32))] = true;
                }
            }

            if ($samples < 1) {
                return true;
            }

            $avgLuma = $lumaSum / $samples;
            $blackRatio = $nearBlack / $samples;
            $whiteRatio = $nearWhite / $samples;
            $bucketCount = count($uniqueBuckets);

            // Solid black, solid white/loader, or almost no color variety.
            return ($blackRatio >= 0.97 && $avgLuma < 10.0)
                || ($whiteRatio >= 0.97 && $avgLuma > 245.0)
                || ($bucketCount <= 2 && ($avgLuma < 18.0 || $avgLuma > 240.0));
        } finally {
            imagedestroy($image);
        }
    }

    public function normalizeWebsiteUrl(?string $website): ?string
    {
        $raw = trim((string) $website);
        if ($raw === '') {
            return null;
        }

        if (! preg_match('#^https?://#i', $raw)) {
            $raw = 'https://'.$raw;
        }

        if (filter_var($raw, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($raw);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        return $raw;
    }
}
