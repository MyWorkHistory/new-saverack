<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LeadLogoService
{
    private const CANVAS_SIZE = 256;

    public function replaceForLead(Lead $lead, UploadedFile $file): string
    {
        $ext = $this->normalizeExtension($file);
        $contents = (string) file_get_contents($file->getRealPath());

        return $this->replaceFromBytes($lead, $contents, $ext);
    }

    public function replaceFromBytes(Lead $lead, string $bytes, string $ext = 'png', array $options = []): string
    {
        $ext = $this->normalizeExtString($ext);
        $disk = Storage::disk('public');
        $dir = 'lead-logos/'.$lead->id;
        if (! $disk->exists($dir)) {
            $disk->makeDirectory($dir);
        }

        $filename = Str::uuid()->toString().'.'.$ext;
        $relative = $dir.'/'.$filename;

        $previous = $lead->logo_path;
        $imageBytes = $this->processRawBytes($bytes, $ext, $options);
        $disk->put($relative, $imageBytes);
        $lead->logo_path = $relative;
        $lead->save();

        if ($previous && $previous !== $relative && $disk->exists($previous)) {
            $disk->delete($previous);
        }

        return $relative;
    }

    public function publicUrl(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        $path = ltrim(str_replace('\\', '/', trim($path)), '/');
        if (strpos($path, 'storage/') === 0) {
            return '/'.$path;
        }

        return '/storage/'.$path;
    }

    private function normalizeExtension(UploadedFile $file): string
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: 'png');

        return $this->normalizeExtString($ext);
    }

    private function normalizeExtString(string $ext): string
    {
        $ext = strtolower(trim($ext));
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }
        if (! in_array($ext, ['jpg', 'png', 'webp'], true)) {
            return 'png';
        }

        return $ext;
    }

    /**
     * @param  array{fit?: string, background?: string}  $options
     *   fit: contain (default) | cover
     *   background: transparent (default) | white
     */
    private function processRawBytes(string $contents, string $ext, array $options = []): string
    {
        if (! function_exists('imagecreatefromstring') || $contents === '') {
            return $contents;
        }

        $source = @imagecreatefromstring($contents);
        if ($source === false) {
            return $contents;
        }

        try {
            $srcW = imagesx($source);
            $srcH = imagesy($source);
            if ($srcW < 1 || $srcH < 1) {
                return $contents;
            }

            $size = self::CANVAS_SIZE;
            $canvas = imagecreatetruecolor($size, $size);
            if ($canvas === false) {
                return $contents;
            }

            $fit = strtolower((string) ($options['fit'] ?? 'contain'));
            $background = strtolower((string) ($options['background'] ?? 'transparent'));
            $useWhite = $background === 'white' || $fit === 'cover' || $ext === 'jpg';

            if ($useWhite) {
                imagealphablending($canvas, true);
                $white = imagecolorallocate($canvas, 255, 255, 255);
                imagefilledrectangle($canvas, 0, 0, $size, $size, $white);
            } else {
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
                $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
                imagefilledrectangle($canvas, 0, 0, $size, $size, $transparent);
                imagealphablending($canvas, true);
            }

            if ($fit === 'cover') {
                $scale = max($size / $srcW, $size / $srcH);
                $cropW = (int) max(1, round($size / $scale));
                $cropH = (int) max(1, round($size / $scale));
                $srcX = (int) max(0, floor(($srcW - $cropW) / 2));
                $srcY = (int) max(0, floor(($srcH - $cropH) / 2));
                // Prefer top of page for website screenshots (hero/header), not vertical center.
                if (! empty($options['prefer_top'])) {
                    $srcY = 0;
                }
                imagecopyresampled(
                    $canvas,
                    $source,
                    0,
                    0,
                    $srcX,
                    $srcY,
                    $size,
                    $size,
                    $cropW,
                    $cropH
                );
            } else {
                $scale = min($size / $srcW, $size / $srcH);
                $dstW = (int) max(1, round($srcW * $scale));
                $dstH = (int) max(1, round($srcH * $scale));
                $dstX = (int) floor(($size - $dstW) / 2);
                $dstY = (int) floor(($size - $dstH) / 2);
                imagecopyresampled($canvas, $source, $dstX, $dstY, 0, 0, $dstW, $dstH, $srcW, $srcH);
            }

            ob_start();
            if ($ext === 'jpg') {
                imagejpeg($canvas, null, 90);
            } elseif ($ext === 'webp' && function_exists('imagewebp')) {
                imagewebp($canvas, null, 90);
            } else {
                if ($useWhite) {
                    imagesavealpha($canvas, false);
                }
                imagepng($canvas);
            }
            $out = (string) ob_get_clean();
            imagedestroy($canvas);

            return $out !== '' ? $out : $contents;
        } finally {
            imagedestroy($source);
        }
    }
}