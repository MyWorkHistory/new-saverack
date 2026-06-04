<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class SlackStatusIconController extends Controller
{
    private const ALLOWED = [
        'shipping-status-live.png',
        'shipping-status-paused.png',
        'shipping-status-live-thumb.png',
        'shipping-status-paused-thumb.png',
    ];

    public function show(string $icon): Response|SymfonyResponse
    {
        if (! in_array($icon, self::ALLOWED, true)) {
            abort(404);
        }

        $path = $this->resolveIconPath($icon);
        if ($path === null) {
            abort(404);
        }

        return response()->file($path, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    public function showAvatar(string $icon): Response|SymfonyResponse
    {
        if (! in_array($icon, self::ALLOWED, true)) {
            abort(404);
        }

        $path = public_path('images/slack/avatars/'.$icon);
        if (! is_file($path)) {
            $path = $this->resolveIconPath($icon);
        }

        if ($path === null) {
            abort(404);
        }

        return response()->file($path, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    private function resolveIconPath(string $icon): ?string
    {
        $candidates = [
            public_path('images/slack/avatars/'.$icon),
            public_path('images/slack/'.$icon),
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
