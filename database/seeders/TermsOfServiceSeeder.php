<?php

namespace Database\Seeders;

use App\Models\TermsOfService;
use App\Support\HtmlSanitizer;
use Illuminate\Database\Seeder;

class TermsOfServiceSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/terms_of_service.html');
        $body = '';
        if (is_readable($path)) {
            $body = HtmlSanitizer::sanitize((string) file_get_contents($path));
        }

        $existing = TermsOfService::query()->orderBy('id')->first();
        if ($existing instanceof TermsOfService) {
            if (trim((string) $existing->body) === '' && $body !== '') {
                $existing->body = $body;
                $existing->save();
            }

            return;
        }

        TermsOfService::query()->create([
            'body' => $body,
        ]);
    }
}
