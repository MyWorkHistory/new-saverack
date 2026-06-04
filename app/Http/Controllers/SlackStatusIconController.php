<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class SlackStatusIconController extends Controller
{
    /** Slack incoming-webhook avatars display at 36×36. */
    private const AVATAR_SIZE = 36;

    private const ALLOWED = [
        'shipping-status-live.png',
        'shipping-status-paused.png',
    ];

    public function show(string $icon): Response|SymfonyResponse
    {
        if (! in_array($icon, self::ALLOWED, true)) {
            abort(404);
        }

        $avatarPath = public_path('images/slack/avatars/'.$icon);
        if (is_file($avatarPath)) {
            return response()->file($avatarPath, $this->pngHeaders());
        }

        $path = public_path('images/slack/'.$icon);
        if (! is_file($path)) {
            abort(404);
        }

        $png = $this->squareAvatarPng($path);
        if ($png !== null) {
            return response($png, 200, $this->pngHeaders());
        }

        return response()->file($path, $this->pngHeaders());
    }

    /**
     * @return array<string, string>
     */
    private function pngHeaders(): array
    {
        return [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ];
    }

    private function squareAvatarPng(string $path): ?string
    {
        if (! function_exists('imagecreatefrompng')) {
            return null;
        }

        $src = @imagecreatefrompng($path);
        if ($src === false) {
            return null;
        }

        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw < 1 || $sh < 1) {
            imagedestroy($src);

            return null;
        }

        $size = self::AVATAR_SIZE;
        $dst = imagecreatetruecolor($size, $size);
        if ($dst === false) {
            imagedestroy($src);

            return null;
        }

        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $white);

        $scale = min($size / $sw, $size / $sh);
        $nw = max(1, (int) round($sw * $scale));
        $nh = max(1, (int) round($sh * $scale));
        $left = (int) floor(($size - $nw) / 2);
        $top = (int) floor(($size - $nh) / 2);

        imagealphablending($dst, true);
        imagecopyresampled($dst, $src, $left, $top, 0, 0, $nw, $nh, $sw, $sh);
        imagedestroy($src);

        ob_start();
        imagepng($dst);
        $png = ob_get_clean();
        imagedestroy($dst);

        return is_string($png) && $png !== '' ? $png : null;
    }
}
