<?php

namespace App\Jobs;

use App\Models\BillingWeekEarning;
use App\Services\BillingWeekEarningsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateBillingWeekEarningsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    public $earningId;

    public $timeout = 900;

    public $tries = 1;

    public function __construct(int $earningId)
    {
        $this->earningId = $earningId;
    }

    public function handle(BillingWeekEarningsService $earnings): void
    {
        $row = BillingWeekEarning::query()->find($this->earningId);
        if ($row === null) {
            return;
        }

        try {
            $earnings->generateWeek($row);
        } catch (Throwable $e) {
            Log::error('billing.week_earnings.generate_failed', [
                'earning_id' => $this->earningId,
                'message' => $e->getMessage(),
            ]);
            report($e);
        }
    }
}
