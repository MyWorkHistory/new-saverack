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
            $table->string('logo_path')->nullable()->after('comment');
        });

        // Allow Off follow-up (null days). MySQL without doctrine/dbal.
        DB::statement('ALTER TABLE leads MODIFY follow_up_days SMALLINT UNSIGNED NULL');

        Schema::create('lead_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->string('attachment_path')->nullable();
            $table->string('attachment_original_name')->nullable();
            $table->string('attachment_mime')->nullable();
            $table->unsignedInteger('attachment_size')->nullable();
            $table->timestamps();
            $table->index('lead_id');
        });

        Schema::create('lead_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->string('status', 64);
            $table->unsignedInteger('follow_up_days')->nullable();
            $table->foreignId('email_template_id')->nullable()->constrained('email_templates')->nullOnDelete();
            $table->string('template_name')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();
            $table->index(['lead_id', 'status']);
        });

        $leads = DB::table('leads')->orderBy('id')->get();
        foreach ($leads as $lead) {
            DB::table('lead_status_events')->insert([
                'lead_id' => $lead->id,
                'status' => $lead->status ?: 'open',
                'follow_up_days' => $lead->follow_up_days,
                'email_template_id' => null,
                'template_name' => null,
                'user_id' => null,
                'note' => 'Lead created',
                'created_at' => $lead->created_at ?? now(),
                'updated_at' => $lead->created_at ?? now(),
            ]);

            $comment = is_string($lead->comment ?? null) ? trim($lead->comment) : '';
            if ($comment !== '') {
                $userId = DB::table('users')->orderBy('id')->value('id');
                if ($userId) {
                    DB::table('lead_comments')->insert([
                        'lead_id' => $lead->id,
                        'user_id' => $userId,
                        'body' => $comment,
                        'created_at' => $lead->created_at ?? now(),
                        'updated_at' => $lead->created_at ?? now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_status_events');
        Schema::dropIfExists('lead_comments');

        if (Schema::hasColumn('leads', 'logo_path')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->dropColumn('logo_path');
            });
        }

        DB::statement('ALTER TABLE leads MODIFY follow_up_days SMALLINT UNSIGNED NOT NULL DEFAULT 1');
    }
};
