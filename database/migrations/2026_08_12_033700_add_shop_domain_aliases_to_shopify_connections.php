<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('client_account_shopify_connections')) {
            return;
        }

        Schema::table('client_account_shopify_connections', function (Blueprint $table) {
            if (! Schema::hasColumn('client_account_shopify_connections', 'shop_domain_aliases')) {
                $table->json('shop_domain_aliases')->nullable()->after('shop_domain');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('client_account_shopify_connections')) {
            return;
        }

        Schema::table('client_account_shopify_connections', function (Blueprint $table) {
            if (Schema::hasColumn('client_account_shopify_connections', 'shop_domain_aliases')) {
                $table->dropColumn('shop_domain_aliases');
            }
        });
    }
};
