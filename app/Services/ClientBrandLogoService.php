<?php

namespace App\Services;

use App\Models\ClientAccount;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ClientBrandLogoService
{
    public function replaceForAccount(ClientAccount $account, UploadedFile $file): string
    {
        $disk = Storage::disk('public');
        $dir = 'client-brand-logos/'.$account->id;
        if (! $disk->exists($dir)) {
            $disk->makeDirectory($dir);
        }

        $ext = strtolower($file->getClientOriginalExtension() ?: 'png');
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $ext = 'png';
        }
        $filename = Str::uuid()->toString().'.'.$ext;
        $relative = $dir.'/'.$filename;

        $previous = $account->brand_logo_path;
        $disk->putFileAs($dir, $file, $filename);
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
}
