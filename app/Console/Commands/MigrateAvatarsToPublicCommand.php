<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * One-time (or repeat-safe) copy from storage/app/public/avatars to public/avatars
 * so avatars are served as static files without relying on the public/storage symlink.
 */
class MigrateAvatarsToPublicCommand extends Command
{
    protected $signature = 'avatars:migrate-to-public';

    protected $description = 'Copy avatar files from storage/app/public/avatars to public/avatars';

    public function handle(): int
    {
        $src = storage_path('app/public/avatars');
        $dest = public_path('avatars');

        if (! File::isDirectory($src)) {
            $this->info('No legacy avatars directory at storage/app/public/avatars.');

            return self::SUCCESS;
        }

        File::ensureDirectoryExists($dest);
        File::copyDirectory($src, $dest);
        $this->info('Copied avatars to public/avatars. API URLs will use /avatars/… when those files exist.');

        return self::SUCCESS;
    }
}
