<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
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

        $apiKey = trim((string) config('services.microlink.api_key', ''));
        $configuredUrl = trim((string) config('services.microlink.api_url', ''));
        $baseUrl = $configuredUrl !== ''
            ? rtrim($configuredUrl, '/')
            : ($apiKey !== '' ? 'https://pro.microlink.io' : 'https://api.microlink.io');

        $request = Http::timeout(90)->acceptJson();
        if ($apiKey !== '') {
            $request = $request->withHeaders(['x-api-key' => $apiKey]);
        }

        $response = $request->get($baseUrl, [
            'url' => $url,
            'screenshot' => 'true',
            'meta' => 'false',
            // Square above-the-fold capture so the avatar is not a tiny strip on black.
            'screenshot.type' => 'png',
            'screenshot.viewport.width' => 1200,
            'screenshot.viewport.height' => 1200,
            'screenshot.viewport.deviceScaleFactor' => 1,
        ]);

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
