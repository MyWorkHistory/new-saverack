<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Lead website thumbnails via thum.io URL API (no API key).
 *
 * Keep this path fast: Cloudflare will 502 if the origin holds the request too long.
 *
 * @see https://www.thum.io/documentation/api/url
 */
class WebsiteScreenshotService
{
    /** Stay under typical Cloudflare / proxy origin timeouts. */
    private const HTTP_TIMEOUT_SECONDS = 45;

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

        // One request only — no prefetch / multi-pass (those cause Cloudflare 502 timeouts).
        $endpoint = $this->buildThumIoUrl($url);
        $bytes = $this->downloadThumImage($endpoint);

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
     * https://image.thum.io/get/width/800/crop/800/png/noanimate/https://example.com
     *
     * @param  array{prefetch?: bool}  $options
     */
    public function buildThumIoUrl(string $websiteUrl, array $options = []): string
    {
        $base = rtrim((string) config('services.thum_io.base_url', 'https://image.thum.io'), '/');
        $width = max(100, (int) config('services.thum_io.width', 800));
        $crop = max(100, (int) config('services.thum_io.crop', 800));
        // Short maxAge so regenerates refresh soon, but cache hits after prefetch are fast.
        $maxAgeHours = max(1, (int) config('services.thum_io.max_age_hours', 1));
        $prefetch = ! empty($options['prefetch']);

        // Docs: modifiers are path segments placed BEFORE the target URL.
        $parts = ['get'];
        if ($prefetch) {
            $parts[] = 'prefetch';
        }

        $parts[] = 'maxAge';
        $parts[] = (string) $maxAgeHours;
        $parts[] = 'width';
        $parts[] = (string) $width;
        $parts[] = 'crop';
        $parts[] = (string) $crop;
        // Final static PNG — required for downloads (docs: batch jobs / noanimate).
        $parts[] = 'png';
        $parts[] = 'noanimate';

        return $base.'/'.implode('/', $parts).'/'.$websiteUrl;
    }

    private function downloadThumImage(string $endpoint): string
    {
        try {
            $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                ->connectTimeout(10)
                ->withHeaders([
                    'User-Agent' => 'SaveRackCRM/1.0',
                    'Accept' => 'image/png,image/jpeg,image/*,*/*',
                ])
                ->get($endpoint);
        } catch (\Throwable $e) {
            Log::warning('website_screenshot.thum_request_failed', [
                'endpoint' => $endpoint,
                'message' => $e->getMessage(),
            ]);
            throw new RuntimeException(
                'Screenshot service timed out. Wait a minute and try again.'
            );
        }

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

        // thum.io may return plain text while rendering — never save that as a logo.
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
