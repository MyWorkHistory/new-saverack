<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingSummaryController;
use App\Http\Controllers\Api\ClientAccountController;
use App\Http\Controllers\Api\ClientAccountUserController;
use App\Http\Controllers\Api\ClientStoreController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\InvoiceImportController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WebmasterTaskController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:10,1');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

Route::post('stripe/webhook', [StripeWebhookController::class, 'handle']);

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });

    Route::get('/roles', [RoleController::class, 'index']);

    Route::get('/dashboard/summary', [DashboardController::class, 'summary'])
        ->middleware('can:view-dashboard');

    Route::get('/billing/summary', BillingSummaryController::class)
        ->name('billing.summary');

    Route::prefix('inventory')->group(function () {
        Route::get('/client-account-options', [InventoryController::class, 'clientAccountOptions'])
            ->middleware('can:inventory.view');
        Route::get('/warehouses', [InventoryController::class, 'warehouses'])
            ->middleware('can:inventory.view');
        Route::get('/diagnostic', [InventoryController::class, 'diagnostic'])
            ->middleware('can:inventory.view');
        Route::get('/search', [InventoryController::class, 'search'])
            ->middleware('can:inventory.view');
        Route::post('/replace', [InventoryController::class, 'replaceQuantity'])
            ->middleware('can:inventory.update');
    });

    Route::get('invoices/meta', [InvoiceController::class, 'meta'])
        ->name('invoices.meta');
    Route::post('invoices/{invoice}/share-link', [InvoiceController::class, 'shareLink'])
        ->name('invoices.share-link');
    Route::delete('invoices/{invoice}/line-groups/{groupKey}', [InvoiceController::class, 'destroyLineGroup'])
        ->where('groupKey', '[A-Za-z0-9_.:\-]+')
        ->name('invoices.line-groups.destroy');
    Route::put('invoices/{invoice}/line-groups/{groupKey}', [InvoiceController::class, 'replaceLineGroup'])
        ->where('groupKey', '[A-Za-z0-9_.:\-]+')
        ->name('invoices.line-groups.replace');
    Route::post('invoices/{invoice}/send', [InvoiceController::class, 'send'])
        ->name('invoices.send');
    Route::post('invoices/{invoice}/email', [InvoiceController::class, 'sendEmail'])
        ->name('invoices.email');
    Route::post('invoices/{invoice}/whatsapp', [InvoiceController::class, 'sendWhatsapp'])
        ->name('invoices.whatsapp');
    Route::get('invoices/{invoice}/pay-context', [InvoiceController::class, 'payContext'])
        ->name('invoices.pay-context');
    Route::post('invoices/{invoice}/pay-allocate', [InvoiceController::class, 'payAllocate'])
        ->name('invoices.pay-allocate');
    Route::post('invoices/{invoice}/pay', [InvoiceController::class, 'pay'])
        ->name('invoices.pay');
    Route::post('invoices/{invoice}/record-payment', [InvoiceController::class, 'recordPayment'])
        ->name('invoices.record-payment');
    Route::get('invoices/{invoice}/stripe-payment-methods', [InvoiceController::class, 'stripePaymentMethods'])
        ->name('invoices.stripe-methods');
    Route::post('invoices/{invoice}/stripe-charge', [InvoiceController::class, 'stripeCharge'])
        ->name('invoices.stripe-charge');
    Route::post('invoices/{invoice}/add-item', [InvoiceController::class, 'addItem'])
        ->name('invoices.add-item');
    Route::post('invoices/{invoice}/add-cc-fee', [InvoiceController::class, 'addCcFee'])
        ->name('invoices.add-cc-fee');
    Route::post('invoices/{invoice}/status', [InvoiceController::class, 'updateStatus'])
        ->name('invoices.status');
    Route::put('invoices/{invoice}/items/{item}', [InvoiceController::class, 'updateItem'])
        ->name('invoices.items.update');
    Route::delete('invoices/{invoice}/items/{item}', [InvoiceController::class, 'destroyItem'])
        ->name('invoices.items.destroy');
    Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void'])
        ->name('invoices.void');
    Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])
        ->name('invoices.pdf');
    Route::apiResource('invoices', InvoiceController::class);

    Route::get('/tickets/meta', [TicketController::class, 'meta']);
    Route::post('/tickets/{ticket}/comments', [TicketController::class, 'storeComment']);
    Route::apiResource('tickets', TicketController::class);

    Route::prefix('webmaster')->group(function () {
        Route::get('/tasks/meta', [WebmasterTaskController::class, 'meta']);
        Route::post('/tasks/{task}/comments', [WebmasterTaskController::class, 'storeComment']);
        Route::get('/tasks/{task}/comments/{comment}/attachment', [WebmasterTaskController::class, 'downloadCommentAttachment']);
        Route::apiResource('tasks', WebmasterTaskController::class);
    });

    Route::post('users/{user}/avatar', [UserController::class, 'uploadAvatar'])
        ->name('users.avatar.store');
    Route::delete('users/{user}/avatar', [UserController::class, 'destroyAvatar'])
        ->name('users.avatar.destroy');
    Route::patch('users/bulk', [UserController::class, 'bulkUpdate'])
        ->name('users.bulk-update');
    Route::delete('users/bulk', [UserController::class, 'bulkDestroy'])
        ->name('users.bulk-destroy');
    Route::get('users/permissions/meta', [UserController::class, 'permissionsMeta'])
        ->name('users.permissions.meta');
    Route::get('users/export-csv', [UserController::class, 'exportCsv'])
        ->name('users.export-csv');

    Route::get('client-account-users', [ClientAccountUserController::class, 'index'])
        ->name('client-account-users.index');
    Route::get('client-account-users/export-csv', [ClientAccountUserController::class, 'exportCsv'])
        ->name('client-account-users.export-csv');
    Route::get('client-accounts/{client_account}/account-users/{user}', [ClientAccountUserController::class, 'show'])
        ->name('client-accounts.account-users.show');
    Route::get('client-accounts/{client_account}/account-users/{user}/history', [ClientAccountUserController::class, 'history'])
        ->name('client-accounts.account-users.history');
    Route::post('client-accounts/{client_account}/account-users', [ClientAccountUserController::class, 'store'])
        ->name('client-accounts.account-users.store');
    Route::patch('client-accounts/{client_account}/account-users/{user}', [ClientAccountUserController::class, 'update'])
        ->name('client-accounts.account-users.update');
    Route::delete('client-accounts/{client_account}/account-users/{user}', [ClientAccountUserController::class, 'destroy'])
        ->name('client-accounts.account-users.destroy');

    Route::get('client-accounts/meta', [ClientAccountController::class, 'meta'])
        ->name('client-accounts.meta');
    Route::get('client-accounts/export-csv', [ClientAccountController::class, 'exportCsv'])
        ->name('client-accounts.export-csv');
    Route::get('client-accounts/{client_account}/history', [ClientAccountController::class, 'history'])
        ->name('client-accounts.history');
    Route::post('client-accounts/{client_account}/comments', [ClientAccountController::class, 'storeComment'])
        ->name('client-accounts.comments.store');
    Route::patch('client-accounts/{client_account}/comments/{comment}', [ClientAccountController::class, 'updateComment'])
        ->name('client-accounts.comments.update');
    Route::delete('client-accounts/{client_account}/comments/{comment}', [ClientAccountController::class, 'destroyComment'])
        ->name('client-accounts.comments.destroy');
    Route::get('client-accounts/{client_account}/comments/{comment}/attachment', [ClientAccountController::class, 'downloadCommentAttachment'])
        ->name('client-accounts.comments.attachment');
    Route::post('client-accounts/{client_account}/invoice-imports/charges', [InvoiceImportController::class, 'importCharges'])
        ->name('client-accounts.invoice-imports.charges');
    Route::post('client-accounts/{client_account}/invoice-imports/storage', [InvoiceImportController::class, 'importStorage'])
        ->name('client-accounts.invoice-imports.storage');
    Route::put('client-accounts/{client_account}/fees', [ClientAccountController::class, 'syncFees'])
        ->name('client-accounts.fees.sync');
    Route::delete('client-accounts/{client_account}/fees/{fee}', [ClientAccountController::class, 'destroyFeeItem'])
        ->name('client-accounts.fees.destroy');
    Route::patch('client-accounts/bulk', [ClientAccountController::class, 'bulkUpdate'])
        ->name('client-accounts.bulk-update');
    Route::delete('client-accounts/bulk', [ClientAccountController::class, 'bulkDestroy'])
        ->name('client-accounts.bulk-destroy');
    Route::patch('client-stores/bulk', [ClientStoreController::class, 'bulkUpdate'])
        ->name('client-stores.bulk-update');
    Route::delete('client-stores/bulk', [ClientStoreController::class, 'bulkDestroy'])
        ->name('client-stores.bulk-destroy');
    Route::get('client-accounts/{client_account}/stores', [ClientStoreController::class, 'index'])
        ->name('client-accounts.stores.index');
    Route::post('client-accounts/{client_account}/stores', [ClientStoreController::class, 'store'])
        ->name('client-accounts.stores.store');
    Route::patch('client-stores/{client_store}', [ClientStoreController::class, 'update'])
        ->name('client-stores.update');
    Route::delete('client-stores/{client_store}', [ClientStoreController::class, 'destroy'])
        ->name('client-stores.destroy');
    Route::apiResource('client-accounts', ClientAccountController::class);
    Route::match(['put', 'patch'], 'users/{user}/permissions', [UserController::class, 'updatePermissions'])
        ->name('users.permissions.update');
    Route::get('users/{user}/history', [UserController::class, 'history'])
        ->name('users.history');
    Route::apiResource('users', UserController::class);
});
