<?php

namespace App\Services;

use App\Models\ClientAccount;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ClientBrandLogoService
{
    /** Square canvas size for normalized brand logos (list + detail). */
    private const CANVAS_SIZE = 256;

    public function replaceForAccount(ClientAccount $account, UploadedFile $file): string
    {
        $disk = Storage::disk('public');
        $dir = 'client-brand-logos/'.$account->id;
        if (! $disk->exists($dir)) {
            $disk->makeDirectory($dir);
        }

        $ext = $this->normalizeExtension($file);
        $filename = Str::uuid()->toString().'.'.$ext;
        $relative = $dir.'/'.$filename;

        $previous = $account->brand_logo_path;
        $imageBytes = $this->processUpload($file, $ext);
        $disk->put($relative, $imageBytes);
        $account->brand_logo_path = $relative;
        $account->save();

        if ($previous && $previous !== $relative && $disk->exists($previous)) {
            $disk->delete($previous);
        }

        return $relative;
    }

    /**
     * Relative URL for CRM/portal <img src> (resolved via /storage proxy in dev).
     */
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
            $width = imagesx($source);
            $height = imagesy($source);
            if ($width <= 0 || $height <= 0) {
                return $contents;
            }

            $canvas = $this->normalizeToSquareCanvas($source, $width, $height, $ext);

            return $this->encodeImage($canvas, $ext);
        } finally {
            if (isset($canvas) && $this->isGdImage($canvas)) {
                imagedestroy($canvas);
            }
            if (isset($source) && $this->isGdImage($source) && (! isset($canvas) || $canvas !== $source)) {
                imagedestroy($source);
            }
        }
    }

    /**
     * Fit any aspect ratio inside a square canvas (contain), upscaling small logos and downscaling large ones.
     *
     * @param  resource|\GdImage  $source
     * @return resource|\GdImage
     */
    private function normalizeToSquareCanvas($source, int $width, int $height, string $ext)
    {
        $canvasSize = self::CANVAS_SIZE;
        $scale = min($canvasSize / $width, $canvasSize / $height);
        $fitWidth = max(1, (int) round($width * $scale));
        $fitHeight = max(1, (int) round($height * $scale));
        $offsetX = (int) floor(($canvasSize - $fitWidth) / 2);
        $offsetY = (int) floor(($canvasSize - $fitHeight) / 2);

        $canvas = imagecreatetruecolor($canvasSize, $canvasSize);
        if ($canvas === false) {
            return $source;
        }

        if ($ext === 'png' || $ext === 'webp') {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefilledrectangle($canvas, 0, 0, $canvasSize, $canvasSize, $transparent);
        } else {
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefilledrectangle($canvas, 0, 0, $canvasSize, $canvasSize, $white);
        }

        imagecopyresampled(
            $canvas,
            $source,
            $offsetX,
            $offsetY,
            0,
            0,
            $fitWidth,
            $fitHeight,
            $width,
            $height
        );

        return $canvas;
    }

    /**
     * @param  mixed  $value
     */
    private function isGdImage($value): bool
    {
        if (is_resource($value)) {
            return true;
        }

        return class_exists(\GdImage::class, false) && $value instanceof \GdImage;
    }

    private function encodeImage($image, string $ext): string
    {
        ob_start();
        try {
            if ($ext === 'jpg') {
                imagejpeg($image, null, 90);
            } elseif ($ext === 'webp' && function_exists('imagewebp')) {
                imagewebp($image, null, 90);
            } else {
                imagepng($image, null, 6);
            }

            $bytes = ob_get_contents();
            if ($bytes === false || $bytes === '') {
                throw new RuntimeException('Could not encode brand logo image.');
            }

            return $bytes;
        } finally {
            ob_end_clean();
        }
    }
}
