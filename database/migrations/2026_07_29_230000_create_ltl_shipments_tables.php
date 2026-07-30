<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ltl_shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_account_id')->constrained('client_accounts')->cascadeOnDelete();
            $table->string('number', 32)->unique();
            $table->string('status', 32)->default('draft');
            $table->string('direction', 32);
            $table->string('company_name')->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 64)->nullable();
            $table->string('zip', 32)->nullable();
            $table->string('country', 64)->nullable()->default('United States');
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 64)->nullable();
            $table->string('time_mode', 32)->nullable()->default('asap');
            $table->timestamp('time_from')->nullable();
            $table->timestamp('time_to')->nullable();
            $table->string('load_requirement', 64)->nullable();
            $table->string('pickup_type', 64)->nullable();
            $table->text('notes')->nullable();
            $table->bigInteger('quote_amount_cents')->nullable();
            $table->string('quote_carrier')->nullable();
            $table->string('quote_transit_time')->nullable();
            $table->string('quote_service', 64)->nullable()->default('standard_ltl');
            $table->string('tracking_number')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('client_account_id');
        });

        Schema::create('ltl_shipment_pallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ltl_shipment_id')->constrained('ltl_shipments')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('commodity')->nullable();
            $table->decimal('length_in', 10, 2)->nullable();
            $table->decimal('width_in', 10, 2)->nullable();
            $table->decimal('height_in', 10, 2)->nullable();
            $table->decimal('weight_lbs', 12, 2)->nullable();
            $table->timestamps();

            $table->index('ltl_shipment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ltl_shipment_pallets');
        Schema::dropIfExists('ltl_shipments');
    }
};
