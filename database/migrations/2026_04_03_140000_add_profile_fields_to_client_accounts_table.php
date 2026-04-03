<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->string('brand_name', 190)->nullable();
            $table->string('website', 512)->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('street', 190)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 64)->nullable();
            $table->string('zip', 32)->nullable();
            $table->string('country', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'brand_name',
                'website',
                'phone',
                'street',
                'city',
                'state',
                'zip',
                'country',
            ]);
        });
    }
};
