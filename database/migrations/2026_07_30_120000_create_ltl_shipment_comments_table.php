<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ltl_shipment_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ltl_shipment_id')->constrained('ltl_shipments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index('ltl_shipment_id');
        });

        // Promote legacy free-text notes into the first comment when present.
        $rows = DB::table('ltl_shipments')
            ->whereNotNull('notes')
            ->where('notes', '!=', '')
            ->get(['id', 'notes', 'created_by_user_id', 'created_at']);

        foreach ($rows as $row) {
            $userId = $row->created_by_user_id;
            if ($userId === null) {
                $userId = DB::table('users')->orderBy('id')->value('id');
            }
            if ($userId === null) {
                continue;
            }
            DB::table('ltl_shipment_comments')->insert([
                'ltl_shipment_id' => $row->id,
                'user_id' => $userId,
                'body' => $row->notes,
                'created_at' => $row->created_at ?? now(),
                'updated_at' => $row->created_at ?? now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ltl_shipment_comments');
    }
};
