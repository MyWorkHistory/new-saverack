<?php

use App\Models\ClientAccount;
use App\Models\ClientAccountFee;
use App\Models\ClientAccountReturn;
use App\Models\ClientAccountReturnAttachment;
use App\Models\PricingFeeTemplate;
use App\Services\PricingFeeTemplateService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('client_account_returns')
            && ! Schema::hasColumn('client_account_returns', 'return_fee_photo')) {
            Schema::table('client_account_returns', function (Blueprint $table) {
                $table->decimal('return_fee_photo', 12, 4)->nullable()->after('return_fee_non_compliant');
            });
        }

        if (! Schema::hasTable('client_account_return_attachments')) {
            Schema::create('client_account_return_attachments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('client_account_return_id');
                $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
                $table->string('original_name', 255);
                $table->string('path', 512);
                $table->string('mime', 128)->nullable();
                $table->unsignedInteger('size')->nullable();
                $table->timestamps();

                $table->index('client_account_return_id', 'car_attachments_return_id_idx');
            });
        }

        if (Schema::hasTable('client_account_returns')
            && Schema::hasColumn('client_account_returns', 'process_photo_path')
            && Schema::hasTable('client_account_return_attachments')) {
            ClientAccountReturn::query()
                ->whereNotNull('process_photo_path')
                ->where('process_photo_path', '!=', '')
                ->orderBy('id')
                ->chunkById(100, function ($returns) {
                    foreach ($returns as $return) {
                        $path = trim((string) $return->process_photo_path);
                        if ($path === '') {
                            continue;
                        }
                        $exists = ClientAccountReturnAttachment::query()
                            ->where('client_account_return_id', $return->id)
                            ->where('path', $path)
                            ->exists();
                        if ($exists) {
                            continue;
                        }
                        ClientAccountReturnAttachment::query()->create([
                            'client_account_return_id' => $return->id,
                            'uploaded_by_user_id' => $return->processed_by_user_id,
                            'original_name' => basename($path),
                            'path' => $path,
                            'mime' => null,
                            'size' => Storage::disk('public')->exists($path)
                                ? (int) Storage::disk('public')->size($path)
                                : null,
                        ]);
                    }
                });
        }

        if (! Schema::hasTable('pricing_fee_templates')) {
            return;
        }

        $template = PricingFeeTemplate::query()->firstOrCreate(
            [
                'name' => 'Return Photo',
                'category' => PricingFeeTemplate::CATEGORY_RETURNS,
            ],
            [
                'description' => 'Fee when a return photo is uploaded.',
                'amount' => '0.0000',
                'sort_order' => 8,
            ]
        );

        if (Schema::hasColumn('client_account_fees', 'pricing_template_id')) {
            ClientAccountFee::query()
                ->where('fee_group', PricingFeeTemplate::CATEGORY_RETURNS)
                ->where('line_code', ClientAccountFee::LINE_RETURNS_PHOTO)
                ->whereNull('pricing_template_id')
                ->update(['pricing_template_id' => $template->id]);

            ClientAccountFee::query()
                ->where('pricing_template_id', $template->id)
                ->where(function ($q) {
                    $q->whereNull('label')->orWhere('label', '');
                })
                ->update(['label' => $template->name]);
        }

        /** @var PricingFeeTemplateService $provisioner */
        $provisioner = app(PricingFeeTemplateService::class);
        $provisioner->provisionTemplateToAllAccounts($template);

        ClientAccount::query()
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($accounts) use ($provisioner) {
                foreach ($accounts as $account) {
                    $provisioner->provisionAllTemplatesForAccount($account);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_account_return_attachments');

        if (Schema::hasTable('client_account_returns')
            && Schema::hasColumn('client_account_returns', 'return_fee_photo')) {
            Schema::table('client_account_returns', function (Blueprint $table) {
                $table->dropColumn('return_fee_photo');
            });
        }
    }
};
