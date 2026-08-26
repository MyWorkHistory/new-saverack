<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddReferenceNumberToClientAccountReturns extends Migration
{
    public function up()
    {
        Schema::table('client_account_returns', function (Blueprint $table) {
            $table->string('reference_number', 255)->nullable()->after('customer_name');
        });
    }

    public function down()
    {
        Schema::table('client_account_returns', function (Blueprint $table) {
            $table->dropColumn('reference_number');
        });
    }
}
