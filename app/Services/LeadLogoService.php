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
        $disk = Storage::disk('public');
        $dir = 'lead-logos/'.$lead->id;
        if (! $disk->exists($dir)) {
            $disk->makeDirectory($dir);
        }

        $ext = $this->normalizeExtension($file);
        $filename = Str::uuid()->toString().'.'.$ext;
        $relative = $dir.'/'.$filename;

        $previous = $lead->logo_path;
        $imageBytes = $this->processUpload($file, $ext);
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
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return 'png';
        }

        return $ext === 'jpeg' ? 'jpg' : $ext;
    }

    private function processUpload(UploadedFile $file, string $ext): string
    {
        if (! function_exists('imagecreatefromstring')) {
            return (string) file_get_contents($file->getRealPath());
        }

        $contents = (string) file_get_contents($file->getRealPath());
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

            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefilledrectangle($canvas, 0, 0, $size, $size, $transparent);
            imagealphablending($canvas, true);

            $scale = min($size / $srcW, $size / $srcH);
            $dstW = (int) max(1, round($srcW * $scale));
            $dstH = (int) max(1, round($srcH * $scale));
            $dstX = (int) floor(($size - $dstW) / 2);
            $dstY = (int) floor(($size - $dstH) / 2);
            imagecopyresampled($canvas, $source, $dstX, $dstY, 0, 0, $dstW, $dstH, $srcW, $srcH);

            ob_start();
            if ($ext === 'jpg') {
                imagejpeg($canvas, null, 90);
            } elseif ($ext === 'webp' && function_exists('imagewebp')) {
                imagewebp($canvas, null, 90);
            } else {
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
