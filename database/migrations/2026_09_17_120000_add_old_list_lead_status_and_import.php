<?php

use App\Models\EmailTemplate;
use App\Support\OldListLeadCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_templates') || ! Schema::hasTable('leads')) {
            return;
        }

        $exists = EmailTemplate::query()
            ->where('category', EmailTemplate::CATEGORY_OLD_LIST)
            ->where('name', OldListLeadCatalog::TEMPLATE_NAME)
            ->exists();

        if (! $exists) {
            EmailTemplate::query()->create([
                'category' => EmailTemplate::CATEGORY_OLD_LIST,
                'name' => OldListLeadCatalog::TEMPLATE_NAME,
                'subject' => OldListLeadCatalog::templateSubject(),
                'body' => OldListLeadCatalog::templateBody(),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('email_templates')) {
            return;
        }

        EmailTemplate::query()
            ->where('category', EmailTemplate::CATEGORY_OLD_LIST)
            ->where('name', OldListLeadCatalog::TEMPLATE_NAME)
            ->delete();
    }
};
