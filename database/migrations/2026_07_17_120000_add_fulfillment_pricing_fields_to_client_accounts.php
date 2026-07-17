<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFulfillmentPricingFieldsToClientAccounts extends Migration
{
    public function up()
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->string('fulfillment_pricing_status', 32)->default('pending')->after('fulfillment_agreement_staff_signature');
            $table->timestamp('fulfillment_pricing_approved_at')->nullable()->after('fulfillment_pricing_status');
            $table->timestamp('fulfillment_pricing_accepted_at')->nullable()->after('fulfillment_pricing_approved_at');
        });
    }

    public function down()
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'fulfillment_pricing_status',
                'fulfillment_pricing_approved_at',
                'fulfillment_pricing_accepted_at',
            ]);
        });
    }
}
