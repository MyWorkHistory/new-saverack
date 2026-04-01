<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class UserAvatarService
{
    private const SIZE = 192;

    private const JPEG_QUALITY = 82;

    public function replaceForUser(User $user, UploadedFile $file): void
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('PHP GD extension is required for avatars.');
        }

        $disk = Storage::disk('public');
        $userDir = 'avatars/'.$user->id;
        if (! $disk->exists($userDir)) {
            $disk->makeDirectory($userDir);
        }

        $tmpKey = $userDir.'/'.Str::uuid()->toString().'.src';
        $disk->putFileAs($userDir, $file, basename($tmpKey));

        $absoluteSrc = $disk->path($tmpKey);
        $jpegBasename = Str::uuid()->toString().'.jpg';
        $relativeJpeg = $userDir.'/'.$jpegBasename;
        $absoluteJpeg = $disk->path($relativeJpeg);

        try {
            $this->writeSquareJpeg($absoluteSrc, $absoluteJpeg, self::SIZE, self::JPEG_QUALITY);
        } finally {
            $disk->delete($tmpKey);
        }

        $profile = $user->profile ?: $user->profile()->create(['user_id' => $user->id]);
        $previous = $profile->avatar_path;

        $profile->avatar_path = $relativeJpeg;
        $profile->save();

        $this->deleteIfUserAvatar($disk, $user->id, $previous, $relativeJpeg);
    }

    public function deleteForUser(User $user): void
    {
        $profile = $user->profile;
        if (! $profile || empty($profile->avatar_path)) {
            return;
        }

        $path = $profile->avatar_path;
        $disk = Storage::disk('public');
        $profile->avatar_path = null;
        $profile->save();
        $this->deleteIfUserAvatar($disk, $user->id, $path, null);
    }

    /**
     * @param  \Illuminate\Contracts\Filesystem\Filesystem  $disk
     */
    private function deleteIfUserAvatar($disk, int $userId, ?string $path, ?string $exceptPath): void
    {
        if ($path === null || $path === '' || $path === $exceptPath) {
            return;
        }
        $prefix = 'avatars/'.$userId.'/';
        if (strpos($path, $prefix) === 0 && $disk->exists($path)) {
            $disk->delete($path);
        }
    }

    private function writeSquareJpeg(string $sourcePath, string $destPath, int $size, int $quality): void
    {
        $info = @getimagesize($sourcePath);
        if ($info === false) {
            throw new RuntimeException('Could not read image.');
        }

        $src = $this->createImageResource($sourcePath, $info[2]);
        if ($src === false) {
            throw new RuntimeException('Unsupported or corrupt image.');
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $min = min($w, $h);
        $x = (int) (($w - $min) / 2);
        $y = (int) (($h - $min) / 2);

        $dst = imagecreatetruecolor($size, $size);
        if ($dst === false) {
            imagedestroy($src);
            throw new RuntimeException('Could not allocate image.');
        }

        imagecopyresampled($dst, $src, 0, 0, $x, $y, $size, $size, $min, $min);
        imagedestroy($src);

        if (! imagejpeg($dst, $destPath, $quality)) {
            imagedestroy($dst);
            throw new RuntimeException('Could not write avatar.');
        }
        imagedestroy($dst);
    }

    /**
     * @return resource|false
     */
    private function createImageResource(string $path, int $type)
    {
        if ($type === IMAGETYPE_JPEG) {
            return imagecreatefromjpeg($path);
        }
        if ($type === IMAGETYPE_PNG) {
            return imagecreatefrompng($path);
        }
        if ($type === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) {
            return imagecreatefromwebp($path);
        }

        return false;
    }
}
