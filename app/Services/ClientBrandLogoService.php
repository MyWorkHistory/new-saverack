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
        if (! in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
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

    public function publicUrl(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }
        if (! Storage::disk('public')->exists($path)) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }
}
