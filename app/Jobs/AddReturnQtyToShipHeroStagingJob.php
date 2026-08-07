<?php

namespace App\Jobs;

use App\Models\ClientAccountReturn;
use App\Models\User;
use App\Services\ReturnBinService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class AddReturnQtyToShipHeroStagingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;

    public $tries = 3;

    /** @var int */
    public $returnId;

    /** @var int|null */
    public $actorUserId;

    public function __construct(int $returnId, ?int $actorUserId = null)
    {
        $this->returnId = $returnId;
        $this->actorUserId = $actorUserId;
    }

    public function handle(ReturnBinService $bins): void
    {
        $return = ClientAccountReturn::query()->find($this->returnId);
        if (! $return instanceof ClientAccountReturn) {
            Log::warning('returns.shiphero_staging.missing_return', [
                'return_id' => $this->returnId,
            ]);

            return;
        }

        $actor = null;
        if ($this->actorUserId !== null && $this->actorUserId > 0) {
            $actor = User::query()->find($this->actorUserId);
        }

        $bins->addProcessedQtyToShipHeroStaging(
            $return->fresh(['lines', 'clientAccount']) ?? $return,
            $actor instanceof User ? $actor : null
        );
    }

    public function failed(Throwable $e): void
    {
        Log::error('returns.shiphero_staging.failed', [
            'return_id' => $this->returnId,
            'actor_user_id' => $this->actorUserId,
            'message' => $e->getMessage(),
        ]);
    }
}
