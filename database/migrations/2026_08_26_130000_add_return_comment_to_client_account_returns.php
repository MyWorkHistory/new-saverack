<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddReturnCommentToClientAccountReturns extends Migration
{
    public function up()
    {
        Schema::table('client_account_returns', function (Blueprint $table) {
            $table->text('return_comment')->nullable()->after('warehouse_private_note');
        });
    }

    public function down()
    {
        Schema::table('client_account_returns', function (Blueprint $table) {
            $table->dropColumn('return_comment');
        });
    }
}
