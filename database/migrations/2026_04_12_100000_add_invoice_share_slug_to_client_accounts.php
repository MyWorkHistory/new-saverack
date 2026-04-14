<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->string('invoice_share_slug', 160)->nullable()->unique()->after('company_name');
        });

        $rows = DB::table('client_accounts')->select('id', 'company_name')->orderBy('id')->get();
        foreach ($rows as $r) {
            $base = Str::slug((string) $r->company_name);
            if ($base === '') {
                $base = 'account';
            }
            DB::table('client_accounts')->where('id', $r->id)->update([
                'invoice_share_slug' => $base.'-'.$r->id,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->dropColumn('invoice_share_slug');
        });
    }
};
