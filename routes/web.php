<?php

/*
|--------------------------------------------------------------------------
| Web routes (session, CSRF, cookies)
|--------------------------------------------------------------------------
| SPA HTML is served from routes/spa.php without this middleware stack.
| Add Blade routes or server-rendered pages here if needed.
|--------------------------------------------------------------------------
*/

use App\Http\Controllers\PublicInvoiceController;
use App\Http\Controllers\PublicTermsController;
use App\Http\Controllers\SlackStatusIconController;
use Illuminate\Support\Facades\Route;

Route::get('/images/slack/{icon}', [SlackStatusIconController::class, 'show'])
    ->where('icon', 'shipping-status-(?:live|paused)(?:-thumb)?\.png')
    ->name('slack.status-icon.images');

Route::get('/slack-icons/{icon}', [SlackStatusIconController::class, 'show'])
    ->where('icon', 'shipping-status-(?:live|paused)(?:-thumb)?\.png')
    ->name('slack.status-icon.web');

Route::middleware(['throttle:public-invoice'])->group(function () {
    Route::get('/billing-invoice/{slug}/{token}', [PublicInvoiceController::class, 'show'])
        ->name('public.invoice.show');
    Route::get('/billing-invoice/{slug}/{token}/pdf', [PublicInvoiceController::class, 'pdf'])
        ->name('public.invoice.pdf');
    Route::get('/billing-invoice/{slug}/{token}/pay', [PublicInvoiceController::class, 'pay'])
        ->name('public.invoice.pay');
});

Route::get('/terms', [PublicTermsController::class, 'global'])
    ->name('public.terms');
Route::get('/terms/accounts/{client_account}', [PublicTermsController::class, 'account'])
    ->name('public.terms.account');
