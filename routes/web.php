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
use App\Http\Controllers\PublicPaymentMethodController;
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

Route::middleware(['throttle:public-payment-method'])->group(function () {
    Route::get('/payment-method/{token}', [PublicPaymentMethodController::class, 'show'])
        ->where('token', '[A-Za-z0-9]+')
        ->name('public.payment-method.show');
    Route::get('/payment-method/{token}/thanks', [PublicPaymentMethodController::class, 'thanks'])
        ->where('token', '[A-Za-z0-9]+')
        ->name('public.payment-method.thanks');
});
