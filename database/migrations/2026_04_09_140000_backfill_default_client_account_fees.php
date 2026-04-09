<?php

use App\Models\ClientAccount;
use App\Services\ClientAccountService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        /** @var ClientAccountService $svc */
        $svc = app(ClientAccountService::class);

        ClientAccount::query()->orderBy('id')->chunkById(100, function ($accounts) use ($svc) {
            foreach ($accounts as $account) {
                $svc->ensureDefaultFeeItems($account);
            }
        });
    }

    public function down(): void
    {
        // Non-destructive: do not remove fee rows that may have been edited.
    }
};
