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
            if (! Schema::hasColumn('client_account_shopify_connections', 'refresh_token')) {
                $table->text('refresh_token')->nullable()->after('admin_api_access_token');
            }
            if (! Schema::hasColumn('client_account_shopify_connections', 'access_token_expires_at')) {
                $table->timestamp('access_token_expires_at')->nullable()->after('refresh_token');
            }
            if (! Schema::hasColumn('client_account_shopify_connections', 'refresh_token_expires_at')) {
                $table->timestamp('refresh_token_expires_at')->nullable()->after('access_token_expires_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('client_account_shopify_connections')) {
            return;
        }

        Schema::table('client_account_shopify_connections', function (Blueprint $table) {
            $drops = [];
            foreach (['refresh_token', 'access_token_expires_at', 'refresh_token_expires_at'] as $column) {
                if (Schema::hasColumn('client_account_shopify_connections', $column)) {
                    $drops[] = $column;
                }
            }
            if ($drops !== []) {
                $table->dropColumn($drops);
            }
        });
    }
};
