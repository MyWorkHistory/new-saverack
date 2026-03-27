<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

Route::get('/{any?}', function () {
    $index = public_path('index.html');
    if (! File::exists($index)) {
        return response(
            'SPA not built. Run npm run build in the TailAdmin project (output to public).',
            503
        );
    }

    return response()->file($index);
})->where('any', '^(?!api).*$');
