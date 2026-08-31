<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_comments', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        DB::statement('ALTER TABLE lead_comments MODIFY user_id BIGINT UNSIGNED NULL');

        Schema::table('lead_comments', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        $leads = DB::table('leads')
            ->whereNotNull('comment')
            ->where('comment', '!=', '')
            ->orderBy('id')
            ->get(['id', 'comment', 'created_at']);

        foreach ($leads as $lead) {
            $hasComment = DB::table('lead_comments')
                ->where('lead_id', $lead->id)
                ->exists();
            if ($hasComment) {
                continue;
            }

            DB::table('lead_comments')->insert([
                'lead_id' => $lead->id,
                'user_id' => null,
                'body' => (string) $lead->comment,
                'created_at' => $lead->created_at ?? now(),
                'updated_at' => $lead->created_at ?? now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('lead_comments')->whereNull('user_id')->delete();

        Schema::table('lead_comments', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        DB::statement('ALTER TABLE lead_comments MODIFY user_id BIGINT UNSIGNED NOT NULL');

        Schema::table('lead_comments', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
