<?php

use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Services\LeadService;
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

        // Tests start from an empty leads table. Production / local migrate loads the CSV.
        if (app()->runningUnitTests()) {
            return;
        }

        app(LeadService::class)->importIfEmailMissing(
            OldListLeadCatalog::rows(),
            Lead::STATUS_OLD_LIST
        );
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
