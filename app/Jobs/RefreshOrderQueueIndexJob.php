<?php

namespace App\Jobs;

use App\Services\ShipHeroOrderQueueIndexService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RefreshOrderQueueIndexJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    public $clientAccountId;

    /** @var string */
    public $tab;

    public $timeout = 3600;

    public $tries = 1;

    public function __construct(int $clientAccountId, string $tab)
    {
        $this->clientAccountId = $clientAccountId;
        $this->tab = trim($tab);
    }

    public function handle(ShipHeroOrderQueueIndexService $index): void
    {
        $index->syncAccountQueue($this->clientAccountId, $this->tab, true);
    }

    public function failed(Throwable $e): void
    {
        // syncAccountQueue marks account failed on exception.
    }
}
