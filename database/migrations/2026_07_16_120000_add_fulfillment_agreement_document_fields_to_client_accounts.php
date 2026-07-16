<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFulfillmentAgreementDocumentFieldsToClientAccounts extends Migration
{
    public function up(): void
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->string('fulfillment_agreement_path')->nullable()->after('fulfillment_agreement_accepted_at');
            $table->string('fulfillment_agreement_original_name')->nullable()->after('fulfillment_agreement_path');
            $table->string('fulfillment_agreement_mime', 128)->nullable()->after('fulfillment_agreement_original_name');
            $table->string('fulfillment_agreement_method', 16)->nullable()->after('fulfillment_agreement_mime');
            $table->string('fulfillment_agreement_company')->nullable()->after('fulfillment_agreement_method');
            $table->string('fulfillment_agreement_rep_name')->nullable()->after('fulfillment_agreement_company');
            $table->timestamp('fulfillment_agreement_client_signed_at')->nullable()->after('fulfillment_agreement_rep_name');
            $table->text('fulfillment_agreement_client_signature')->nullable()->after('fulfillment_agreement_client_signed_at');
            $table->string('fulfillment_agreement_staff_rep_name')->nullable()->after('fulfillment_agreement_client_signature');
            $table->timestamp('fulfillment_agreement_staff_signed_at')->nullable()->after('fulfillment_agreement_staff_rep_name');
            $table->text('fulfillment_agreement_staff_signature')->nullable()->after('fulfillment_agreement_staff_signed_at');
        });
    }

    public function down(): void
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'fulfillment_agreement_path',
                'fulfillment_agreement_original_name',
                'fulfillment_agreement_mime',
                'fulfillment_agreement_method',
                'fulfillment_agreement_company',
                'fulfillment_agreement_rep_name',
                'fulfillment_agreement_client_signed_at',
                'fulfillment_agreement_client_signature',
                'fulfillment_agreement_staff_rep_name',
                'fulfillment_agreement_staff_signed_at',
                'fulfillment_agreement_staff_signature',
            ]);
        });
    }
}
