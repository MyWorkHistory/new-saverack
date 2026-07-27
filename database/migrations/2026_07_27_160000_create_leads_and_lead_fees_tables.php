<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('status', 32)->default('open')->index();
            $table->string('company_name');
            $table->string('email');
            $table->string('website')->nullable();
            $table->string('name')->nullable();
            $table->text('comment')->nullable();
            $table->unsignedSmallInteger('follow_up_days')->default(1);
            $table->date('follow_up_at')->nullable()->index();
            $table->timestamps();

            $table->index('created_at');
            $table->index('company_name');
        });

        Schema::create('lead_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('pricing_template_id')
                ->nullable()
                ->constrained('pricing_fee_templates')
                ->nullOnDelete();
            $table->string('fee_group', 32);
            $table->string('line_code', 64)->nullable();
            $table->string('label', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('icon_path', 512)->nullable();
            $table->decimal('amount', 12, 4)->nullable();
            $table->decimal('cost', 12, 4)->nullable();
            $table->char('currency', 3)->default('USD');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['lead_id', 'fee_group']);
            $table->unique(
                ['lead_id', 'fee_group', 'line_code'],
                'lead_fees_lead_group_line_unique'
            );
        });

        Permission::ensureRowsForKeys([
            'leads.view',
            'leads.create',
            'leads.update',
            'leads.delete',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_fees');
        Schema::dropIfExists('leads');

        Permission::query()
            ->whereIn('key', [
                'leads.view',
                'leads.create',
                'leads.update',
                'leads.delete',
            ])
            ->delete();
    }
};
