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
use Illuminate\Support\Facades\Route;

Route::middleware(['throttle:public-invoice'])->group(function () {
    Route::get('/billing-invoice/{slug}/{token}', [PublicInvoiceController::class, 'show'])
        ->name('public.invoice.show');
    Route::get('/billing-invoice/{slug}/{token}/pdf', [PublicInvoiceController::class, 'pdf'])
        ->name('public.invoice.pdf');
    Route::get('/billing-invoice/{slug}/{token}/pay', [PublicInvoiceController::class, 'pay'])
        ->name('public.invoice.pay');
});
