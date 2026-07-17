<?php

use App\Http\Controllers\Api\AdminAsnController;
use App\Http\Controllers\Api\AdminReturnController;
use App\Http\Controllers\Api\AsnController;
use App\Http\Controllers\Api\ReturnController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingSummaryController;
use App\Http\Controllers\Api\BillingBillsController;
use App\Http\Controllers\Api\CustomBillController;
use App\Http\Controllers\Api\ClientAccountController;
use App\Http\Controllers\Api\ClientAccountOnboardingController;
use App\Http\Controllers\Api\ClientAccountTermsOfServiceController;
use App\Http\Controllers\Api\ClientAccountUserController;
use App\Http\Controllers\Api\ClientAccountShipHeroStoreController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\HomeDashboardController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\InvoiceImportController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\InventoryBetaController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\OrderDraftController;
use App\Http\Controllers\Api\CrmLookupController;
use App\Http\Controllers\Api\PortalLookupController;
use App\Http\Controllers\Api\PortalOnboardingController;
use App\Http\Controllers\Api\PortalProfileController;
use App\Http\Controllers\Api\PricingFeeTemplateController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\TermsOfServiceController;
use App\Http\Controllers\Api\ClientAccountPaymentMethodController;
use App\Http\Controllers\Api\ReturnBillController;
use App\Http\Controllers\Api\AsnBillController;
use App\Http\Controllers\Api\PutAwayController;
use App\Http\Controllers\Api\UserPersonalTaskController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WebmasterTaskController;
use App\Http\Controllers\Api\TutorialController;
use App\Http\Controllers\Api\TutorialPhotoController;
use App\Http\Controllers\Api\ResourceCalendarEventController;
use App\Http\Controllers\Api\ResourcePhotoController;
use App\Http\Controllers\Api\WholesaleOrderController;
use App\Http\Controllers\ShipHeroWebhookController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\PublicPaymentMethodController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:10,1');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

Route::post('stripe/webhook', [StripeWebhookController::class, 'handle']);
Route::match(['post', 'head'], 'shiphero/webhook', [ShipHeroWebhookController::class, 'handle']);

Route::middleware('throttle:public-payment-method')->prefix('public/payment-method')->group(function () {
    Route::post('{token}/setup-intent', [PublicPaymentMethodController::class, 'setupIntent']);
    Route::post('{token}/complete', [PublicPaymentMethodController::class, 'complete']);
});

Route::get('/slack/status-icons/{icon}', [\App\Http\Controllers\SlackStatusIconController::class, 'show'])
    ->where('icon', 'shipping-status-(?:live|paused)(?:-thumb)?\.png')
    ->name('slack.status-icon');

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });

    Route::prefix('me/personal-tasks')->group(function () {
        Route::get('/', [UserPersonalTaskController::class, 'index']);
        Route::post('/', [UserPersonalTaskController::class, 'store']);
        Route::patch('/{userPersonalTask}', [UserPersonalTaskController::class, 'update']);
        Route::delete('/{userPersonalTask}', [UserPersonalTaskController::class, 'destroy']);
    });

    Route::get('/roles', [RoleController::class, 'index']);

    Route::get('/dashboard/summary', [DashboardController::class, 'summary'])
        ->middleware('can:view-dashboard');

    Route::get('/home-dashboard', [HomeDashboardController::class, 'show']);
    Route::get('/home-dashboard/revision', [HomeDashboardController::class, 'revision']);
    Route::post('/home-dashboard/refresh', [HomeDashboardController::class, 'refresh']);

    Route::get('/billing/summary', BillingSummaryController::class)
        ->name('billing.summary');

    Route::get('/billing/bills', [BillingBillsController::class, 'index'])
        ->name('billing.bills');

    Route::prefix('settings/pricing-fees')->group(function () {
        Route::get('/', [PricingFeeTemplateController::class, 'index']);
        Route::post('/', [PricingFeeTemplateController::class, 'store']);
        Route::get('/{pricingFeeTemplate}', [PricingFeeTemplateController::class, 'show']);
        Route::patch('/{pricingFeeTemplate}', [PricingFeeTemplateController::class, 'update']);
        Route::delete('/{pricingFeeTemplate}', [PricingFeeTemplateController::class, 'destroy']);
    });

    Route::prefix('settings/terms-of-service')->group(function () {
        Route::get('/', [TermsOfServiceController::class, 'show']);
        Route::put('/', [TermsOfServiceController::class, 'update']);
    });

        Route::prefix('return-bills')->group(function () {
            Route::get('/', [ReturnBillController::class, 'index']);
            Route::get('/charge-options', [ReturnBillController::class, 'chargeOptions']);
            Route::get('/{returnBill}', [ReturnBillController::class, 'show']);
            Route::patch('/{returnBill}', [ReturnBillController::class, 'update']);
            Route::delete('/{returnBill}', [ReturnBillController::class, 'destroy']);
            Route::get('/{returnBill}/draft-invoices', [ReturnBillController::class, 'draftInvoices']);
            Route::post('/{returnBill}/add-to-invoice', [ReturnBillController::class, 'addToInvoice']);
            Route::post('/{returnBill}/items', [ReturnBillController::class, 'storeItem']);
            Route::put('/{returnBill}/items/{item}', [ReturnBillController::class, 'updateItem']);
            Route::delete('/{returnBill}/items/{item}', [ReturnBillController::class, 'destroyItem']);
        });

        Route::prefix('asn-bills')->group(function () {
            Route::get('/', [AsnBillController::class, 'index']);
            Route::get('/lines', [AsnBillController::class, 'lines']);
            Route::get('/{asnBill}', [AsnBillController::class, 'show']);
            Route::patch('/{asnBill}', [AsnBillController::class, 'update']);
            Route::delete('/{asnBill}', [AsnBillController::class, 'destroy']);
            Route::get('/{asnBill}/draft-invoices', [AsnBillController::class, 'draftInvoices']);
            Route::post('/{asnBill}/add-to-invoice', [AsnBillController::class, 'addToInvoice']);
            Route::post('/{asnBill}/items', [AsnBillController::class, 'storeItem']);
            Route::put('/{asnBill}/items/{item}', [AsnBillController::class, 'updateItem']);
            Route::delete('/{asnBill}/items/{item}', [AsnBillController::class, 'destroyItem']);
        });

        Route::prefix('custom-bills')->group(function () {
        Route::get('/', [CustomBillController::class, 'index']);
        Route::post('/', [CustomBillController::class, 'store']);
        Route::get('/{customBill}', [CustomBillController::class, 'show']);
        Route::patch('/{customBill}', [CustomBillController::class, 'update']);
        Route::delete('/{customBill}', [CustomBillController::class, 'destroy']);
        Route::patch('/{customBill}/status', [CustomBillController::class, 'updateStatus']);
        Route::get('/{customBill}/draft-invoices', [CustomBillController::class, 'draftInvoices']);
        Route::post('/{customBill}/add-to-invoice', [CustomBillController::class, 'addToInvoice']);
        Route::post('/{customBill}/items', [CustomBillController::class, 'storeItem']);
        Route::put('/{customBill}/items/{item}', [CustomBillController::class, 'updateItem']);
        Route::delete('/{customBill}/items/{item}', [CustomBillController::class, 'destroyItem']);
        });

        Route::prefix('projects')->group(function () {
            Route::get('/', [ProjectController::class, 'index']);
            Route::get('/summary', [ProjectController::class, 'summary']);
            Route::post('/', [ProjectController::class, 'store']);
            Route::get('/{project}', [ProjectController::class, 'show']);
            Route::patch('/{project}', [ProjectController::class, 'update']);
            Route::patch('/{project}/status', [ProjectController::class, 'updateStatus']);
            Route::delete('/{project}', [ProjectController::class, 'destroy']);
            Route::post('/{project}/notes', [ProjectController::class, 'storeNote']);
            Route::patch('/{project}/notes/{note}', [ProjectController::class, 'updateNote']);
            Route::delete('/{project}/notes/{note}', [ProjectController::class, 'destroyNote']);
            Route::post('/{project}/quote-items', [ProjectController::class, 'storeQuoteItem']);
            Route::put('/{project}/quote-items/{item}', [ProjectController::class, 'updateQuoteItem']);
            Route::delete('/{project}/quote-items/{item}', [ProjectController::class, 'destroyQuoteItem']);
            Route::post('/{project}/create-bill', [ProjectController::class, 'createBill']);
        });

    Route::prefix('inventory')->group(function () {
        Route::get('/client-account-options', [InventoryController::class, 'clientAccountOptions'])
            ->middleware('can:crm.client-account-options');
        Route::get('/adjustment-reasons', [InventoryController::class, 'adjustmentReasons'])
            ->middleware('can:inventory.view');
        Route::get('/warehouses', [InventoryController::class, 'warehouses'])
            ->middleware('can:inventory.view');
        Route::get('/diagnostic', [InventoryController::class, 'diagnostic'])
            ->middleware('can:inventory.view');
        Route::get('/search', [InventoryController::class, 'search'])
            ->middleware('can:inventory.view');
        Route::get('/list', [InventoryController::class, 'list'])
            ->middleware('can:inventory.view');
        Route::get('/restock', [InventoryController::class, 'restockReport'])
            ->middleware('can:inventory.view');
        Route::get('/restock/preview', [InventoryController::class, 'previewRestockReport'])
            ->middleware('can:inventory.view');
        Route::post('/restock/refresh', [InventoryController::class, 'refreshRestockReport'])
            ->middleware('can:inventory.view');
        Route::post('/restock/load-more', [InventoryController::class, 'loadMoreRestockReport'])
            ->middleware('can:inventory.view');
        Route::get('/restock-beta', [InventoryController::class, 'restockBetaSnapshot'])
            ->middleware('can:inventory.view');
        Route::post('/restock-beta/import', [InventoryController::class, 'importRestockBetaCsv'])
            ->middleware('can:inventory.view');
        Route::post('/restock-beta/complete', [InventoryController::class, 'completeRestockBetaRow'])
            ->middleware('can:inventory.view');
        Route::post('/warehouse-products/bulk-active', [InventoryController::class, 'bulkWarehouseProductActive'])
            ->middleware('can:inventory.update');
        Route::patch('/products/bulk-crm-active', [InventoryController::class, 'bulkCrmActive'])
            ->middleware('can:inventory.crm-status.update');
        Route::get('/asn-product-catalog', [InventoryController::class, 'asnProductCatalog'])
            ->middleware('can:inventory.view');
        Route::post('/catalog-products', [InventoryController::class, 'storeCatalogProduct'])
            ->middleware('can:inventory.view');
        Route::get('/products/{sku}', [InventoryController::class, 'productDetail'])
            ->middleware('can:inventory.view');
        Route::get('/products/{sku}/parent-kits', [InventoryController::class, 'productParentKits'])
            ->middleware('can:inventory.view');
        Route::get('/products/{sku}/kit-components', [InventoryController::class, 'productKitComponents'])
            ->middleware('can:inventory.view');
        Route::get('/products/{sku}/allocated-orders', [InventoryController::class, 'productAllocatedOrders'])
            ->middleware('can:inventory.view');
        Route::get('/products/{sku}/backorder-orders', [InventoryController::class, 'productBackorderOrders'])
            ->middleware('can:inventory.view');
        Route::get('/products/{sku}/barcode-label.pdf', [InventoryController::class, 'productBarcodeLabelPdf'])
            ->middleware('can:inventory.view');
        Route::post('/products/{sku}/image', [InventoryController::class, 'uploadProductImage'])
            ->middleware('can:inventory.view');
        Route::post('/replace', [InventoryController::class, 'replaceQuantity'])
            ->middleware('can:inventory.update');
        Route::post('/transfer', [InventoryController::class, 'transferQuantity'])
            ->middleware('can:inventory.update');
        Route::post('/locations/pickable', [InventoryController::class, 'updateLocationPickable'])
            ->middleware('can:inventory.update');
        Route::post('/locations/add-qty', [InventoryController::class, 'addLocationQuantity'])
            ->middleware('can:inventory.update');
        Route::post('/locations/delete', [InventoryController::class, 'deleteLocation'])
            ->middleware('can:inventory.update');
        Route::get('/on-demand-products', [InventoryController::class, 'onDemandProducts'])
            ->middleware('can:inventory.view');
        Route::post('/on-demand-products', [InventoryController::class, 'storeOnDemandProduct'])
            ->middleware('can:inventory.update');
        Route::patch('/on-demand-products/{onDemandProduct}', [InventoryController::class, 'updateOnDemandProduct'])
            ->middleware('can:inventory.update');
        Route::delete('/on-demand-products/{onDemandProduct}', [InventoryController::class, 'destroyOnDemandProduct'])
            ->middleware('can:inventory.update');
    });

    Route::prefix('inventory-beta')->group(function () {
        Route::get('/catalog-sync', [InventoryBetaController::class, 'catalogSync'])
            ->middleware('can:inventory.view');
        Route::get('/revision', [InventoryBetaController::class, 'revision'])
            ->middleware('can:inventory.view');
        Route::get('/list', [InventoryBetaController::class, 'list'])
            ->middleware('can:inventory.view');
        Route::get('/out-of-stock-by-account', [InventoryBetaController::class, 'outOfStockByAccount'])
            ->middleware('can:inventory.view');
        Route::post('/products/{sku}/sync', [InventoryBetaController::class, 'syncCatalogProduct'])
            ->middleware('can:inventory.view');
    });

    Route::get('/crm/lookup', [CrmLookupController::class, 'lookup']);

    Route::prefix('portal')->group(function () {
        Route::get('/lookup', [PortalLookupController::class, 'lookup'])->middleware('can:inventory.view');
        Route::get('/profile', [PortalProfileController::class, 'show'])->middleware('can:inventory.view');
        Route::patch('/profile', [PortalProfileController::class, 'update'])->middleware('can:inventory.view');
        Route::patch('/profile/packaging', [PortalProfileController::class, 'updatePackaging'])->middleware('can:inventory.view');
        Route::get('/onboarding', [PortalOnboardingController::class, 'show'])->middleware('can:inventory.view');
        Route::patch('/onboarding/preferences/{section}', [PortalOnboardingController::class, 'savePreferences'])->middleware('can:inventory.view');
        Route::post('/onboarding/branding/logo', [PortalOnboardingController::class, 'uploadBrandLogo'])->middleware('can:inventory.view');
        Route::post('/onboarding/billing/manual', [PortalOnboardingController::class, 'saveManualBilling'])->middleware('can:inventory.view');
        Route::post('/onboarding/billing/stripe-checkout', [PortalOnboardingController::class, 'startStripeCheckout'])->middleware('can:inventory.view');
        Route::post('/onboarding/billing/payment-method-link', [PortalOnboardingController::class, 'createPaymentMethodLink'])->middleware('can:inventory.view');
        Route::post('/onboarding/fulfillment-agreement/accept', [PortalOnboardingController::class, 'acceptFulfillmentAgreement'])->middleware('can:inventory.view');
        Route::get('/onboarding/fulfillment-agreement.pdf', [PortalOnboardingController::class, 'downloadFulfillmentAgreementPdf'])->middleware('can:inventory.view');
        Route::post('/onboarding/fulfillment-agreement/upload', [PortalOnboardingController::class, 'uploadFulfillmentAgreement'])->middleware('can:inventory.view');
        Route::post('/onboarding/fulfillment-agreement/esign', [PortalOnboardingController::class, 'esignFulfillmentAgreement'])->middleware('can:inventory.view');
        Route::get('/onboarding/fulfillment-agreement/signed.pdf', [PortalOnboardingController::class, 'downloadSignedFulfillmentAgreement'])->middleware('can:inventory.view');
        Route::get('/onboarding/fulfillment-pricing.pdf', [PortalOnboardingController::class, 'downloadFulfillmentPricingPdf'])->middleware('can:inventory.view');
        Route::post('/onboarding/fulfillment-pricing/accept', [PortalOnboardingController::class, 'acceptFulfillmentPricing'])->middleware('can:inventory.view');
    });

    Route::prefix('returns')->group(function () {
        Route::get('/items', [ReturnController::class, 'itemsIndex'])->middleware('can:returns.view');
        Route::post('/draft', [ReturnController::class, 'storeDraft'])->middleware('can:returns.create');
        Route::get('/', [ReturnController::class, 'index'])->middleware('can:returns.view');
        Route::get('/{clientAccountReturn}/packing-slip.pdf', [ReturnController::class, 'packingSlipPdf'])->middleware('can:returns.view');
        Route::get('/{clientAccountReturn}/shipping-label.pdf', [ReturnController::class, 'shippingLabelPdf'])->middleware('can:returns.view');
        Route::get('/{clientAccountReturn}/rma-barcode.pdf', [ReturnController::class, 'rmaBarcodePdf'])->middleware('can:returns.view');
        Route::put('/{clientAccountReturn}/submit', [ReturnController::class, 'submit'])->middleware('can:returns.update');
        Route::patch('/{clientAccountReturn}/warehouse-note', [ReturnController::class, 'updateWarehouseNote'])->middleware('can:returns.update');
        Route::get('/{clientAccountReturn}', [ReturnController::class, 'show'])->middleware('can:returns.view');
        Route::patch('/{clientAccountReturn}', [ReturnController::class, 'update'])->middleware('can:returns.update');
        Route::delete('/{clientAccountReturn}', [ReturnController::class, 'destroy'])->middleware('can:returns.delete');
    });

    Route::prefix('admin/returns')->group(function () {
        Route::post('/non-compliant', [AdminReturnController::class, 'storeNonCompliant'])->middleware('can:returns.create');
        Route::post('/third-party', [AdminReturnController::class, 'storeThirdParty'])->middleware('can:returns.create');
        Route::get('/bins', [AdminReturnController::class, 'listReturnBins'])->middleware('can:returns.view');
        Route::get('/bins/{binNumber}/items', [AdminReturnController::class, 'listReturnBinItems'])->middleware('can:returns.view');
        Route::post('/bins/{binNumber}/transfer', [AdminReturnController::class, 'transferReturnBinItem'])->middleware('can:returns.update');
        Route::post('/{clientAccountReturn}/lines', [AdminReturnController::class, 'storeLine'])->middleware('can:returns.create');
        Route::patch('/{clientAccountReturn}/lines/{line}', [AdminReturnController::class, 'updateLine'])->middleware('can:returns.update');
        Route::delete('/{clientAccountReturn}/lines/{line}', [AdminReturnController::class, 'destroyLine'])->middleware('can:returns.delete');
        Route::get('/{clientAccountReturn}/lines/{line}/barcode.pdf', [AdminReturnController::class, 'lineBarcodePdf'])->middleware('can:returns.view');
        Route::get('/fee-defaults', [AdminReturnController::class, 'feeDefaults'])->middleware('can:returns.view');
        Route::patch('/{clientAccountReturn}/return-bin', [AdminReturnController::class, 'assignReturnBin'])->middleware('can:returns.update');
        Route::patch('/{clientAccountReturn}/fees', [AdminReturnController::class, 'updateFees'])->middleware('can:returns.update');
        Route::post('/{clientAccountReturn}/process-from-draft', [AdminReturnController::class, 'processFromDraft'])->middleware('can:returns.update');
        Route::get('/pending', [AdminReturnController::class, 'pending'])->middleware('can:returns.view');
        Route::get('/order-lookup', [AdminReturnController::class, 'orderLookup'])->middleware('can:returns.view');
        Route::get('/rma-lookup', [AdminReturnController::class, 'rmaLookup'])->middleware('can:returns.view');
        Route::get('/orders', [AdminReturnController::class, 'returnedOrders'])->middleware('can:returns.view');
        Route::get('/items', [AdminReturnController::class, 'returnedItems'])->middleware('can:returns.view');
        Route::post('/{clientAccountReturn}/process', [AdminReturnController::class, 'process'])->middleware('can:returns.update');
        Route::get('/process-lookup', [AdminReturnController::class, 'processLookup'])->middleware('can:returns.view');
    });

    Route::prefix('admin/wholesale-orders')->group(function () {
        Route::get('/', [WholesaleOrderController::class, 'index'])->middleware('can:orders.view');
        Route::get('/pick-list', [WholesaleOrderController::class, 'pickList'])->middleware('can:orders.view');
        Route::post('/', [WholesaleOrderController::class, 'store'])->middleware('can:orders.create');
        Route::get('/{wholesaleOrder}/product-catalog', [WholesaleOrderController::class, 'productCatalog'])->middleware('can:orders.view');
        Route::get('/{wholesaleOrder}', [WholesaleOrderController::class, 'show'])->middleware('can:orders.view');
        Route::patch('/{wholesaleOrder}', [WholesaleOrderController::class, 'update'])->middleware('can:orders.update');
        Route::post('/{wholesaleOrder}/ready-to-ship', [WholesaleOrderController::class, 'readyToShip'])->middleware('can:orders.update');
        Route::post('/{wholesaleOrder}/mark-picked', [WholesaleOrderController::class, 'markPicked'])->middleware('can:orders.update');
        Route::post('/{wholesaleOrder}/lines', [WholesaleOrderController::class, 'storeLine'])->middleware('can:orders.create');
        Route::patch('/{wholesaleOrder}/lines/{line}', [WholesaleOrderController::class, 'updateLine'])->middleware('can:orders.update');
        Route::patch('/{wholesaleOrder}/lines/{line}/pick', [WholesaleOrderController::class, 'updateLinePick'])->middleware('can:orders.update');
        Route::delete('/{wholesaleOrder}/lines/{line}', [WholesaleOrderController::class, 'destroyLine'])->middleware('can:orders.delete');
        Route::post('/{wholesaleOrder}/lines/{line}/barcode', [WholesaleOrderController::class, 'uploadLineBarcode'])->middleware('can:orders.update');
        Route::get('/{wholesaleOrder}/lines/{line}/barcode.pdf', [WholesaleOrderController::class, 'lineBarcodePdf'])->middleware('can:orders.view');
        Route::post('/{wholesaleOrder}/shipping-label', [WholesaleOrderController::class, 'uploadShippingLabel'])->middleware('can:orders.update');
        Route::get('/{wholesaleOrder}/shipping-label.pdf', [WholesaleOrderController::class, 'shippingLabelDownload'])->middleware('can:orders.view');
        Route::delete('/{wholesaleOrder}/shipping-labels/{shippingLabel}', [WholesaleOrderController::class, 'destroyShippingLabel'])->middleware('can:orders.delete');
        Route::put('/{wholesaleOrder}/packages', [WholesaleOrderController::class, 'syncPackages'])->middleware('can:orders.update');
        Route::post('/{wholesaleOrder}/packages/send-slack', [WholesaleOrderController::class, 'sendPackagesSlack'])->middleware('can:orders.update');
        Route::post('/{wholesaleOrder}/comments', [WholesaleOrderController::class, 'storeComment'])->middleware('can:orders.view');
        Route::get('/{wholesaleOrder}/comments/{comment}/attachment', [WholesaleOrderController::class, 'downloadCommentAttachment'])->middleware('can:orders.view');
    });

        Route::prefix('admin/asns')->group(function () {
        Route::get('/summary', [AdminAsnController::class, 'summary'])->middleware('can:receiving_asn.view');
        Route::get('/charge-options', [AdminAsnController::class, 'chargeOptions'])->middleware('can:receiving_asn.view');
        Route::get('/', [AdminAsnController::class, 'index'])->middleware('can:receiving_asn.view');
        Route::post('/non-compliant', [AdminAsnController::class, 'storeNonCompliant'])->middleware('can:receiving_asn.create');
        Route::get('/{asn}/product-catalog', [AsnController::class, 'productCatalog'])->middleware('can:receiving_asn.view');
        Route::post('/{asn}/catalog-products', [AsnController::class, 'storeCatalogProduct'])->middleware('can:receiving_asn.update');
        Route::get('/{asn}', [AdminAsnController::class, 'show'])->middleware('can:receiving_asn.view');
        Route::patch('/{asn}/status', [AdminAsnController::class, 'updateStatus'])->middleware('can:receiving_asn.update');
        Route::post('/{asn}/enrich-specs', [AdminAsnController::class, 'enrichSpecs'])->middleware('can:receiving_asn.update');
        Route::post('/{asn}/scan-barcodes', [AdminAsnController::class, 'scanBarcodes'])->middleware('can:receiving_asn.update');
        Route::get('/{asn}/lines/{line}/receiving-on-hand', [AdminAsnController::class, 'receivingOnHand'])->middleware('can:receiving_asn.view');
        Route::post('/{asn}/lines/{line}/receive', [AdminAsnController::class, 'receiveLine'])->middleware('can:receiving_asn.update');
        Route::post('/{asn}/lines/{line}/receive-override', [AdminAsnController::class, 'receiveOverride'])->middleware('can:receiving_asn.update');
        Route::post('/{asn}/lines/{line}/reject-override', [AdminAsnController::class, 'rejectOverride'])->middleware('can:receiving_asn.update');
        Route::patch('/{asn}/lines/{line}/specs', [AdminAsnController::class, 'updateLineSpecs'])->middleware('can:receiving_asn.update');
        Route::post('/{asn}/bill-items', [AdminAsnController::class, 'storeBillItem'])->middleware('can:receiving_asn.create');
        Route::put('/{asn}/bill-items/{item}', [AdminAsnController::class, 'updateBillItem'])->middleware('can:receiving_asn.update');
        Route::delete('/{asn}/bill-items/{item}', [AdminAsnController::class, 'destroyBillItem'])->middleware('can:receiving_asn.delete');
    });

    Route::prefix('admin/put-away')->group(function () {
        Route::get('/', [PutAwayController::class, 'index'])->middleware('can:receiving_put_away.view');
        Route::get('/products/{sku}', [PutAwayController::class, 'show'])->middleware('can:receiving_put_away.view');
        Route::post('/refresh', [PutAwayController::class, 'refresh'])->middleware('can:receiving_put_away.update');
    });

    Route::prefix('asns')->group(function () {
        Route::get('/', [AsnController::class, 'index'])->middleware('can:asns.view');
        Route::post('/', [AsnController::class, 'store'])->middleware('can:asns.create');
        Route::post('/bulk-delete', [AsnController::class, 'bulkDestroy'])->middleware('can:asns.delete');
        Route::get('/{asn}/packing-slip.pdf', [AsnController::class, 'packingSlipPdf'])->middleware('can:asns.view');
        Route::get('/{asn}/identification-label.pdf', [AsnController::class, 'identificationLabelPdf'])->middleware('can:asns.view');
        Route::get('/{asn}/lines/{line}/barcode.pdf', [AsnController::class, 'barcodePdf'])->middleware('can:asns.view');
        Route::get('/{asn}/product-catalog', [AsnController::class, 'productCatalog'])->middleware('can:asns.view');
        Route::post('/{asn}/catalog-products', [AsnController::class, 'storeCatalogProduct'])->middleware('can:asns.update');
        Route::get('/{asn}', [AsnController::class, 'show'])->middleware('can:asns.view');
        Route::patch('/{asn}', [AsnController::class, 'update'])->middleware('can:asns.update');
        Route::post('/{asn}/mark-ready', [AsnController::class, 'markReady'])->middleware('can:asns.update');
        Route::post('/{asn}/reopen-for-edit', [AsnController::class, 'reopenForEdit'])->middleware('can:asns.update');
        Route::delete('/{asn}', [AsnController::class, 'destroy'])->middleware('can:asns.delete');
        Route::patch('/{asn}/warehouse-notes', [AsnController::class, 'updateWarehouseNotes'])->middleware('can:asns.update');
        Route::post('/{asn}/lines', [AsnController::class, 'storeLine'])->middleware('can:asns.create');
        Route::patch('/{asn}/lines/{line}', [AsnController::class, 'updateLine'])->middleware('can:asns.update');
        Route::delete('/{asn}/lines/{line}', [AsnController::class, 'destroyLine'])->middleware('can:asns.delete');
        Route::put('/{asn}/trackings', [AsnController::class, 'syncTrackings'])->middleware('can:asns.update');
        Route::put('/{asn}/vendor-lines', [AsnController::class, 'syncVendorLines'])->middleware('can:asns.update');
    });

    Route::prefix('order-drafts')->group(function () {
        Route::get('/', [OrderDraftController::class, 'index'])
            ->middleware('can:orders.view');
        Route::post('/', [OrderDraftController::class, 'store'])
            ->middleware('can:shiphero.orders.write');
        Route::post('/{orderDraft}/ready-to-ship', [OrderDraftController::class, 'readyToShip'])
            ->middleware('can:shiphero.orders.write');
        Route::delete('/{orderDraft}', [OrderDraftController::class, 'destroy'])
            ->middleware('can:shiphero.orders.write');
    });

    Route::prefix('orders')->group(function () {
        Route::get('/summary', [OrderController::class, 'summary'])
            ->middleware('can:orders.view');
        Route::get('/queue-counts/snapshot', [OrderController::class, 'queueCountsSnapshot'])
            ->middleware('can:orders.view');
        Route::get('/queue-counts/revision', [OrderController::class, 'queueCountsRevision'])
            ->middleware('can:orders.view');
        Route::get('/queue-counts', [OrderController::class, 'queueCounts'])
            ->middleware('can:orders.view');
        Route::get('/', [OrderController::class, 'index'])
            ->middleware('can:orders.view');
        Route::post('/', [OrderController::class, 'store'])
            ->middleware('can:shiphero.orders.write');
        Route::post('/bulk/mark-fulfilled', [OrderController::class, 'bulkMarkFulfilled'])
            ->middleware('can:shiphero.orders.write');
        Route::post('/bulk/cancel', [OrderController::class, 'bulkCancelOrders'])
            ->middleware('can:shiphero.orders.write');
        Route::post('/bulk/allow-partial', [OrderController::class, 'bulkAllowPartial'])
            ->middleware('can:shiphero.orders.write');
        Route::post('/bulk/set-holds', [OrderController::class, 'bulkSetHolds'])
            ->middleware('can:shiphero.orders.write');
        Route::post('/bulk/clear-holds', [OrderController::class, 'bulkClearHolds'])
            ->middleware('can:shiphero.orders.write');
        Route::post('/{orderId}/mark-fulfilled', [OrderController::class, 'markFulfilled'])
            ->middleware('can:shiphero.orders.write');
        Route::post('/{orderId}/cancel', [OrderController::class, 'cancelOrder'])
            ->middleware('can:shiphero.orders.write');
        Route::post('/{orderId}/set-holds', [OrderController::class, 'setHolds'])
            ->middleware('can:shiphero.orders.write');
        Route::post('/{orderId}/remove-holds', [OrderController::class, 'removeHolds'])
            ->middleware('can:shiphero.orders.write');
        Route::post('/{orderId}/signature-gift-note', [OrderController::class, 'updateSignatureGiftNote'])
            ->middleware('can:shiphero.orders.write');
        Route::post('/{orderId}/shipping-address', [OrderController::class, 'updateShippingAddress'])
            ->middleware('can:shiphero.orders.write');
        Route::post('/{orderId}/shipping-lines', [OrderController::class, 'updateShippingLines'])
            ->middleware('can:shiphero.orders.write');
        Route::post('/{orderId}/allow-partial', [OrderController::class, 'updateAllowPartial'])
            ->middleware('can:shiphero.orders.write');
        Route::post('/{orderId}/tags', [OrderController::class, 'updateTags'])
            ->middleware('can:shiphero.orders.write');
        Route::post('/{orderId}/line-items', [OrderController::class, 'addLineItems'])
            ->middleware('can:shiphero.orders.write');
        Route::post('/{orderId}/line-items/update', [OrderController::class, 'updateLineItemPending'])
            ->middleware('can:shiphero.orders.write');
        Route::post('/{orderId}/line-items/remove', [OrderController::class, 'removeLineItem'])
            ->middleware('can:shiphero.orders.write');
        Route::post('/{orderId}/packing-note', [OrderController::class, 'updatePackingNote'])
            ->middleware('can:shiphero.orders.write');
        Route::post('/{orderId}/attachments', [OrderController::class, 'uploadAttachment'])
            ->middleware('can:shiphero.orders.write');
        Route::get('/{orderId}', [OrderController::class, 'show'])
            ->middleware('can:orders.view');
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
    Route::post('invoices/{invoice}/invoice-review', [InvoiceController::class, 'sendInvoiceReview'])
        ->name('invoices.invoice-review');
    Route::get('invoices/{invoice}/pay-context', [InvoiceController::class, 'payContext'])
        ->name('invoices.pay-context');
    Route::post('invoices/{invoice}/add-available-funds', [InvoiceController::class, 'addAvailableFunds'])
        ->name('invoices.add-available-funds');
    Route::patch('invoices/{invoice}/available-funds', [InvoiceController::class, 'setAvailableFunds'])
        ->name('invoices.set-available-funds');
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
    Route::patch('invoices/{invoice}/dates', [InvoiceController::class, 'updateDates'])
        ->name('invoices.dates');
    Route::patch('invoices/{invoice}/number', [InvoiceController::class, 'updateNumber'])
        ->name('invoices.number');
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
        Route::get('/tasks/summary', [WebmasterTaskController::class, 'summary']);
        Route::patch('/tasks/bulk', [WebmasterTaskController::class, 'bulkUpdate']);
        Route::delete('/tasks/bulk', [WebmasterTaskController::class, 'bulkDestroy']);
        Route::post('/tasks/{task}/comments', [WebmasterTaskController::class, 'storeComment']);
        Route::get('/tasks/{task}/comments/{comment}/attachment', [WebmasterTaskController::class, 'downloadCommentAttachment']);
        Route::apiResource('tasks', WebmasterTaskController::class);
    });

    Route::prefix('resources')->group(function () {
        Route::get('/tutorials/meta', [TutorialController::class, 'meta']);
        Route::post('/tutorials/{tutorial}/comments', [TutorialController::class, 'storeComment']);
        Route::get('/tutorials/{tutorial}/comments/{comment}/attachment', [TutorialController::class, 'downloadCommentAttachment']);
        Route::get('/tutorials/{tutorial}/photos', [TutorialPhotoController::class, 'index']);
        Route::post('/tutorials/{tutorial}/photos', [TutorialPhotoController::class, 'store']);
        Route::delete('/tutorials/{tutorial}/photos/{photo}', [TutorialPhotoController::class, 'destroy']);
        Route::get('/tutorials/{tutorial}/photos/{photo}/file', [TutorialPhotoController::class, 'file']);
        Route::apiResource('tutorials', TutorialController::class);
        Route::get('/photos', [ResourcePhotoController::class, 'index']);
        Route::post('/photos', [ResourcePhotoController::class, 'store']);
        Route::delete('/photos/{photo}', [ResourcePhotoController::class, 'destroy']);
        Route::get('/photos/{photo}/file', [ResourcePhotoController::class, 'file']);
        Route::get('/calendar-events/meta', [ResourceCalendarEventController::class, 'meta']);
        Route::get('/calendar-events/list', [ResourceCalendarEventController::class, 'listEvents']);
        Route::get('/calendar-events', [ResourceCalendarEventController::class, 'index']);
        Route::post('/calendar-events', [ResourceCalendarEventController::class, 'store']);
        Route::patch('/calendar-events/{calendarEvent}', [ResourceCalendarEventController::class, 'update']);
        Route::delete('/calendar-events/bulk', [ResourceCalendarEventController::class, 'bulkDestroy']);
        Route::delete('/calendar-events/{calendarEvent}', [ResourceCalendarEventController::class, 'destroy']);
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
    Route::post('client-accounts/{client_account}/account-users/{user}/avatar', [ClientAccountUserController::class, 'uploadAvatar'])
        ->name('client-accounts.account-users.avatar');
    Route::get('client-accounts/{client_account}/account-users/{user}/notes', [ClientAccountUserController::class, 'indexNotes'])
        ->name('client-accounts.account-users.notes.index');
    Route::post('client-accounts/{client_account}/account-users/{user}/notes', [ClientAccountUserController::class, 'storeNote'])
        ->name('client-accounts.account-users.notes.store');
    Route::delete('client-accounts/{client_account}/account-users/{user}/notes/{note}', [ClientAccountUserController::class, 'destroyNote'])
        ->name('client-accounts.account-users.notes.destroy');
    Route::post('client-accounts/{client_account}/account-users', [ClientAccountUserController::class, 'store'])
        ->name('client-accounts.account-users.store');
    Route::patch('client-accounts/{client_account}/account-users/{user}', [ClientAccountUserController::class, 'update'])
        ->name('client-accounts.account-users.update');
    Route::post('client-accounts/{client_account}/account-users/{user}/make-primary', [ClientAccountUserController::class, 'makePrimary'])
        ->name('client-accounts.account-users.make-primary');
    Route::delete('client-accounts/{client_account}/account-users/{user}', [ClientAccountUserController::class, 'destroy'])
        ->name('client-accounts.account-users.destroy');

    Route::get('client-accounts/meta', [ClientAccountController::class, 'meta'])
        ->name('client-accounts.meta');
    Route::get('client-accounts/export-csv', [ClientAccountController::class, 'exportCsv'])
        ->name('client-accounts.export-csv');
    Route::get('client-accounts/{client_account}/terms-of-service', [ClientAccountTermsOfServiceController::class, 'show'])
        ->name('client-accounts.terms-of-service.show');
    Route::put('client-accounts/{client_account}/terms-of-service', [ClientAccountTermsOfServiceController::class, 'update'])
        ->name('client-accounts.terms-of-service.update');
    Route::get('client-accounts/{client_account}/history', [ClientAccountController::class, 'history'])
        ->name('client-accounts.history');
    Route::get('client-accounts/{client_account}/stripe-payment-methods', [ClientAccountController::class, 'stripePaymentMethods'])
        ->name('client-accounts.stripe-payment-methods');
    Route::post('client-accounts/{client_account}/payment-method-links', [ClientAccountPaymentMethodController::class, 'createLink'])
        ->name('client-accounts.payment-method-links.store');
    Route::delete('client-accounts/{client_account}/stripe-payment-methods/{paymentMethodId}', [ClientAccountPaymentMethodController::class, 'destroy'])
        ->name('client-accounts.stripe-payment-methods.destroy');
    Route::post('client-accounts/{client_account}/stripe-payment-methods/{paymentMethodId}/unlock', [ClientAccountPaymentMethodController::class, 'unlock'])
        ->middleware('throttle:payment-method-pin')
        ->name('client-accounts.stripe-payment-methods.unlock');
    Route::get('client-accounts/{client_account}/onboarding', [ClientAccountOnboardingController::class, 'show'])
        ->name('client-accounts.onboarding.show');
    Route::patch('client-accounts/{client_account}/onboarding/profile', [ClientAccountOnboardingController::class, 'updateProfile'])
        ->name('client-accounts.onboarding.profile');
    Route::patch('client-accounts/{client_account}/onboarding/preferences/{section}', [ClientAccountOnboardingController::class, 'savePreferences'])
        ->name('client-accounts.onboarding.preferences');
    Route::post('client-accounts/{client_account}/onboarding/branding/logo', [ClientAccountOnboardingController::class, 'uploadBrandLogo'])
        ->name('client-accounts.onboarding.brand-logo');
    Route::post('client-accounts/{client_account}/onboarding/billing', [ClientAccountOnboardingController::class, 'saveBilling'])
        ->name('client-accounts.onboarding.billing');
    Route::post('client-accounts/{client_account}/onboarding/billing/payment-method-link', [ClientAccountOnboardingController::class, 'createPaymentMethodLink'])
        ->name('client-accounts.onboarding.billing.payment-method-link');
    Route::patch('client-accounts/{client_account}/onboarding/tasks/{task}/verification', [ClientAccountOnboardingController::class, 'updateTaskVerification'])
        ->name('client-accounts.onboarding.tasks.verification');
    Route::patch('client-accounts/{client_account}/onboarding/tasks/{task}/verification/fields/{field}', [ClientAccountOnboardingController::class, 'updateTaskFieldVerification'])
        ->name('client-accounts.onboarding.tasks.verification.fields');
    Route::get('client-accounts/{client_account}/onboarding/fulfillment-agreement.pdf', [ClientAccountOnboardingController::class, 'downloadFulfillmentAgreementPdf'])
        ->name('client-accounts.onboarding.fulfillment-agreement.pdf');
    Route::post('client-accounts/{client_account}/onboarding/fulfillment-agreement/upload', [ClientAccountOnboardingController::class, 'uploadFulfillmentAgreement'])
        ->name('client-accounts.onboarding.fulfillment-agreement.upload');
    Route::post('client-accounts/{client_account}/onboarding/fulfillment-agreement/esign', [ClientAccountOnboardingController::class, 'esignFulfillmentAgreement'])
        ->name('client-accounts.onboarding.fulfillment-agreement.esign');
    Route::post('client-accounts/{client_account}/onboarding/fulfillment-agreement/verify', [ClientAccountOnboardingController::class, 'verifyFulfillmentAgreement'])
        ->name('client-accounts.onboarding.fulfillment-agreement.verify');
    Route::delete('client-accounts/{client_account}/onboarding/fulfillment-agreement/verify', [ClientAccountOnboardingController::class, 'removeFulfillmentVerification'])
        ->name('client-accounts.onboarding.fulfillment-agreement.verify.destroy');
    Route::get('client-accounts/{client_account}/onboarding/fulfillment-agreement/signed.pdf', [ClientAccountOnboardingController::class, 'downloadSignedFulfillmentAgreement'])
        ->name('client-accounts.onboarding.fulfillment-agreement.signed');
    Route::delete('client-accounts/{client_account}/onboarding/fulfillment-agreement', [ClientAccountOnboardingController::class, 'clearFulfillmentAgreement'])
        ->name('client-accounts.onboarding.fulfillment-agreement.destroy');
    Route::get('client-accounts/{client_account}/onboarding/fulfillment-pricing.pdf', [ClientAccountOnboardingController::class, 'downloadFulfillmentPricingPdf'])
        ->name('client-accounts.onboarding.fulfillment-pricing.pdf');
    Route::patch('client-accounts/{client_account}/fulfillment-pricing/status', [ClientAccountController::class, 'updateFulfillmentPricingStatus'])
        ->name('client-accounts.fulfillment-pricing.status');
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
    Route::post('client-accounts/{client_account}/invoice-imports/duties-taxes-asendia', [InvoiceImportController::class, 'importAsendiaDutiesTaxes'])
        ->name('client-accounts.invoice-imports.duties-taxes-asendia');
    Route::post('client-accounts/{client_account}/invoice-imports/duties-taxes-ups', [InvoiceImportController::class, 'importUpsDutiesTaxes'])
        ->name('client-accounts.invoice-imports.duties-taxes-ups');
    Route::put('client-accounts/{client_account}/fees', [ClientAccountController::class, 'syncFees'])
        ->name('client-accounts.fees.sync');
    Route::patch('client-accounts/{client_account}/fees/{fee}', [ClientAccountController::class, 'updateFeeAmount'])
        ->name('client-accounts.fees.update');
    Route::delete('client-accounts/{client_account}/fees/{fee}', [ClientAccountController::class, 'destroyFeeItem'])
        ->name('client-accounts.fees.destroy');
    Route::patch('client-accounts/bulk', [ClientAccountController::class, 'bulkUpdate'])
        ->name('client-accounts.bulk-update');
    Route::delete('client-accounts/bulk', [ClientAccountController::class, 'bulkDestroy'])
        ->name('client-accounts.bulk-destroy');
    Route::get('client-accounts/{client_account}/shiphero-stores', [ClientAccountShipHeroStoreController::class, 'index'])
        ->name('client-accounts.shiphero-stores.index');
    Route::post('client-accounts/{client_account}/shiphero-stores/import', [ClientAccountShipHeroStoreController::class, 'import'])
        ->name('client-accounts.shiphero-stores.import');
    Route::patch('client-accounts/{client_account}/shiphero-stores/{store_key}', [ClientAccountShipHeroStoreController::class, 'update'])
        ->where('store_key', '[^/]+')
        ->name('client-accounts.shiphero-stores.update');
    Route::delete('client-accounts/{client_account}/shiphero-stores/{store_key}', [ClientAccountShipHeroStoreController::class, 'destroy'])
        ->where('store_key', '[^/]+')
        ->name('client-accounts.shiphero-stores.destroy');
    Route::apiResource('client-accounts', ClientAccountController::class);
    Route::match(['put', 'patch'], 'users/{user}/permissions', [UserController::class, 'updatePermissions'])
        ->name('users.permissions.update');
    Route::get('users/{user}/history', [UserController::class, 'history'])
        ->name('users.history');
    Route::apiResource('users', UserController::class);
});
