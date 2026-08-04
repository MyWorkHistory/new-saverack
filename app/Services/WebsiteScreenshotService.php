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

        $endpoint = $this->buildThumIoUrl($url);

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
                'url' => $url,
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 300),
            ]);
            throw new RuntimeException('Could not capture website screenshot (HTTP '.$response->status().').');
        }

        $bytes = $response->body();
        if ($bytes === '' || strlen($bytes) < 32 || @imagecreatefromstring($bytes) === false) {
            throw new RuntimeException('Screenshot service did not return a usable image.');
        }

        if ($this->isMostlyBlankImage($bytes)) {
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
     */
    public function buildThumIoUrl(string $websiteUrl): string
    {
        $base = rtrim((string) config('services.thum_io.base_url', 'https://image.thum.io'), '/');
        $width = max(100, (int) config('services.thum_io.width', 1200));
        $crop = max(100, (int) config('services.thum_io.crop', 1200));
        $maxAgeHours = max(0, (int) config('services.thum_io.max_age_hours', 1));

        // Modifiers go before the target URL (docs: path segments, then site URL).
        $parts = [
            'get',
            'width',
            (string) $width,
            'crop',
            (string) $crop,
            'png',
            'noanimate',
        ];

        if ($maxAgeHours > 0) {
            // Refresh if cached image is older than N hours.
            array_splice($parts, 1, 0, ['maxAge', (string) $maxAgeHours]);
        }

        // Pass target as ?url= so query strings on the website stay intact.
        return $base.'/'.implode('/', $parts).'/?'.http_build_query(['url' => $websiteUrl]);
    }

    /**
     * True only for near-total blank/black captures — not merely dark brand heroes.
     */
    private function isMostlyBlankImage(string $bytes): bool
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
            if ($width < 8 || $height < 8) {
                return true;
            }

            $stepX = max(1, (int) floor($width / 24));
            $stepY = max(1, (int) floor($height / 24));
            $samples = 0;
            $nearBlack = 0;
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
                    $uniqueBuckets[((int) ($r / 32)).'-'.((int) ($g / 32)).'-'.((int) ($b / 32))] = true;
                }
            }

            if ($samples < 1) {
                return true;
            }

            $blackRatio = $nearBlack / $samples;
            $avgLuma = $lumaSum / $samples;

            return ($blackRatio >= 0.97 && $avgLuma < 10.0)
                || (count($uniqueBuckets) <= 2 && $avgLuma < 18.0);
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
