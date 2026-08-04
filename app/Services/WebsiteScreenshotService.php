<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class WebsiteScreenshotService
{
    /**
     * Capture a website screenshot via Microlink and return PNG/JPEG bytes.
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

        $attempts = [
            $this->defaultCaptureQuery($url),
            $this->retryCaptureQuery($url),
            $this->minimalCaptureQuery($url),
        ];

        $lastError = 'Could not capture website screenshot.';
        foreach ($attempts as $index => $query) {
            try {
                $bytes = $this->requestScreenshotBytes($query);
                if ($this->isMostlyDarkImage($bytes)) {
                    Log::warning('website_screenshot.mostly_dark', [
                        'url' => $url,
                        'attempt' => $index + 1,
                        'bytes' => strlen($bytes),
                    ]);
                    $lastError = 'Website screenshot came back blank or too dark. Try again, or upload a logo manually.';
                    continue;
                }

                return $bytes;
            } catch (RuntimeException $e) {
                $lastError = $e->getMessage();
                Log::warning('website_screenshot.attempt_failed', [
                    'url' => $url,
                    'attempt' => $index + 1,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        throw new RuntimeException($lastError);
    }

    /**
     * @return array<string, string|int>
     */
    private function defaultCaptureQuery(string $url): array
    {
        return [
            'url' => $url,
            'screenshot' => 'true',
            'meta' => 'false',
            // Bust stale black/blank cached captures from prior failed loads.
            'force' => 'true',
            'colorScheme' => 'light',
            'waitUntil' => 'networkidle2',
            'waitForTimeout' => 3000,
            'styles' => 'html,body{background:#ffffff!important;color-scheme:light!important;min-height:100%;}',
            // Desktop viewport; LeadLogoService crops the top for the avatar.
            'screenshot.type' => 'png',
            'screenshot.viewport.width' => 1440,
            'screenshot.viewport.height' => 900,
            'screenshot.viewport.deviceScaleFactor' => 1,
        ];
    }

    /**
     * @return array<string, string|int>
     */
    private function retryCaptureQuery(string $url): array
    {
        $query = $this->defaultCaptureQuery($url);
        $query['waitUntil'] = 'load';
        $query['waitForTimeout'] = 5000;
        $query['screenshot.viewport.width'] = 1280;
        $query['screenshot.viewport.height'] = 800;

        return $query;
    }

    /**
     * Lean query for free-tier / strict parameter sets.
     *
     * @return array<string, string|int>
     */
    private function minimalCaptureQuery(string $url): array
    {
        return [
            'url' => $url,
            'screenshot' => 'true',
            'meta' => 'false',
            'force' => 'true',
            'colorScheme' => 'light',
            'waitUntil' => 'load',
            'waitForTimeout' => 4000,
            'screenshot.type' => 'png',
            'screenshot.viewport.width' => 1280,
            'screenshot.viewport.height' => 720,
        ];
    }

    /**
     * @param  array<string, string|int>  $query
     */
    private function requestScreenshotBytes(array $query): string
    {
        $apiKey = trim((string) config('services.microlink.api_key', ''));
        $configuredUrl = trim((string) config('services.microlink.api_url', ''));
        $baseUrl = $configuredUrl !== ''
            ? rtrim($configuredUrl, '/')
            : ($apiKey !== '' ? 'https://pro.microlink.io' : 'https://api.microlink.io');

        $request = Http::timeout(90)->acceptJson();
        if ($apiKey !== '') {
            $request = $request->withHeaders(['x-api-key' => $apiKey]);
        }

        $response = $request->get($baseUrl, $query);

        if ($response->status() === 429) {
            throw new RuntimeException('Screenshot service rate limit reached. Try again later.');
        }

        if (! $response->successful()) {
            $message = (string) (data_get($response->json(), 'message')
                ?: data_get($response->json(), 'status')
                ?: 'Could not capture website screenshot.');
            throw new RuntimeException($message);
        }

        $screenshotUrl = data_get($response->json(), 'data.screenshot.url');
        if (! is_string($screenshotUrl) || trim($screenshotUrl) === '') {
            throw new RuntimeException('Screenshot service did not return an image.');
        }

        $imageResponse = Http::timeout(90)->get($screenshotUrl);
        if (! $imageResponse->successful()) {
            throw new RuntimeException('Could not download website screenshot.');
        }

        $bytes = $imageResponse->body();
        if ($bytes === '' || strlen($bytes) < 32) {
            throw new RuntimeException('Website screenshot was empty.');
        }

        return $bytes;
    }

    /**
     * Detect blank/black captures (bot walls, unloaded SPAs, dark empty shells).
     */
    private function isMostlyDarkImage(string $bytes): bool
    {
        if (! function_exists('imagecreatefromstring')) {
            return false;
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return false;
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
            $dark = 0;
            $lumaSum = 0.0;

            for ($y = 0; $y < $height; $y += $stepY) {
                for ($x = 0; $x < $width; $x += $stepX) {
                    $rgb = imagecolorat($image, $x, $y);
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;
                    $luma = (0.2126 * $r) + (0.7152 * $g) + (0.0722 * $b);
                    $lumaSum += $luma;
                    $samples++;
                    if ($luma < 28) {
                        $dark++;
                    }
                }
            }

            if ($samples < 1) {
                return true;
            }

            $darkRatio = $dark / $samples;
            $avgLuma = $lumaSum / $samples;

            return $darkRatio >= 0.90 || $avgLuma < 18.0;
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
