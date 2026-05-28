<?php

namespace App\Services;

use App\Models\PricingFeeTemplate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PricingFeeIconService
{
    public function replaceForTemplate(PricingFeeTemplate $template, UploadedFile $file): string
    {
        $disk = Storage::disk('public');
        $dir = 'pricing-fee-icons/'.$template->id;
        if (! $disk->exists($dir)) {
            $disk->makeDirectory($dir);
        }

        $ext = strtolower($file->getClientOriginalExtension() ?: 'png');
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $ext = 'png';
        }
        $filename = Str::uuid()->toString().'.'.$ext;
        $relative = $dir.'/'.$filename;

        $previous = $template->icon_path;
        $disk->putFileAs($dir, $file, $filename);
        $template->icon_path = $relative;
        $template->save();

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
