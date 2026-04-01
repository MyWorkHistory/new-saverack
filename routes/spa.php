<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/** Prevent stale CRM shells after deploy (browser/CDN must revalidate). */
$spaHtmlHeaders = [
    'Content-Type' => 'text/html; charset=UTF-8',
    'Cache-Control' => 'no-cache, private, must-revalidate',
];

/*
|--------------------------------------------------------------------------
| SPA shells (no "web" middleware: no session/cookies/CSRF on HTML shells)
|--------------------------------------------------------------------------
| Serving these through the web middleware group caused ERR_TOO_MANY_REDIRECTS
| for some browsers when combined with session + static file responses.
|--------------------------------------------------------------------------
*/

$ticketsIndex = public_path('tickets-app/index.html');
$ticketsPublic = public_path('tickets-app');

Route::redirect('/tickets', '/tickets-app/tickets');

Route::get('/tickets-app/{any?}', function (?string $any = null) use ($ticketsIndex, $ticketsPublic, $spaHtmlHeaders) {
    if ($any !== null && $any !== '') {
        $relative = trim(str_replace(["\0", '..'], '', $any), '/');
        if ($relative !== '') {
            $candidate = public_path('tickets-app/'.$relative);
            $realBase = realpath($ticketsPublic);
            $realFile = is_file($candidate) ? realpath($candidate) : false;
            if ($realBase && $realFile && str_starts_with($realFile, $realBase)) {
                return response()->file($realFile);
            }
        }
    }

    if (! File::exists($ticketsIndex)) {
        return response(
            'Tickets SPA not built. Run: npm run build:crm',
            503
        );
    }

    return response()->file($ticketsIndex, $spaHtmlHeaders);
})->where('any', '.*');

Route::get('/{any?}', function () use ($spaHtmlHeaders) {
    $index = public_path('index.html');
    if (! File::exists($index)) {
        return response(
            'SPA not built. Run npm run build in the TailAdmin project (output to public).',
            503
        );
    }

    return response()->file($index, $spaHtmlHeaders);
})->where('any', '^(?!(?:api|sanctum)(?:/|$)).*$');
