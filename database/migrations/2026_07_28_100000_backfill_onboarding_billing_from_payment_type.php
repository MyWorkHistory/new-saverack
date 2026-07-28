<?php

use App\Services\PortalOnboardingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill onboarding billing from account default_payment_type:
 * Manual → manual + completed; Credit Card → credit_card + completed; ACH → ach + completed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('client_accounts')) {
            return;
        }
        if (! \Illuminate\Support\Facades\Schema::hasColumn('client_accounts', 'onboarding_billing_status')) {
            return;
        }

        $map = [
            'Manual' => [
                'method' => PortalOnboardingService::BILLING_METHOD_MANUAL,
            ],
            'Credit Card' => [
                'method' => PortalOnboardingService::BILLING_METHOD_CREDIT_CARD,
            ],
            'ACH' => [
                'method' => PortalOnboardingService::BILLING_METHOD_ACH,
            ],
        ];

        foreach ($map as $paymentType => $cfg) {
            DB::table('client_accounts')
                ->whereRaw('LOWER(TRIM(COALESCE(default_payment_type, ""))) = ?', [strtolower($paymentType)])
                ->where(function ($q) use ($cfg) {
                    $q->whereNull('onboarding_billing_status')
                        ->orWhere('onboarding_billing_status', '!=', PortalOnboardingService::BILLING_STATUS_COMPLETED)
                        ->orWhereNull('onboarding_billing_method')
                        ->orWhere('onboarding_billing_method', '!=', $cfg['method']);
                })
                ->update([
                    'onboarding_billing_method' => $cfg['method'],
                    'onboarding_billing_status' => PortalOnboardingService::BILLING_STATUS_COMPLETED,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Irreversible data backfill.
    }
};
