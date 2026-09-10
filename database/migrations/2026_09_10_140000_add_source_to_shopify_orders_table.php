<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSourceToShopifyOrdersTable extends Migration
{
    public function up()
    {
        Schema::table('shopify_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('shopify_orders', 'source')) {
                $table->string('source', 32)->default('shopify')->after('connection_id')->index();
            }
        });
    }

    public function down()
    {
        Schema::table('shopify_orders', function (Blueprint $table) {
            if (Schema::hasColumn('shopify_orders', 'source')) {
                $table->dropColumn('source');
            }
        });
    }
}
