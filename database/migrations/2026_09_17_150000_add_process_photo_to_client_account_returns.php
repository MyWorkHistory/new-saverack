<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_account_returns', function (Blueprint $table) {
            $table->string('process_photo_path', 512)->nullable()->after('processed_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('client_account_returns', function (Blueprint $table) {
            $table->dropColumn('process_photo_path');
        });
    }
};
