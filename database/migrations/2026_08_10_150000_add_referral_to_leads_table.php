<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('referral', 32)->default('bizy')->after('status');
            $table->index('referral');
        });

        DB::table('leads')->whereNull('referral')->orWhere('referral', '')->update([
            'referral' => 'bizy',
        ]);
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['referral']);
            $table->dropColumn('referral');
        });
    }
};
