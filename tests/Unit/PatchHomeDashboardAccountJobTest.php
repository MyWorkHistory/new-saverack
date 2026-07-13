<?php

namespace Tests\Unit;

use App\Jobs\PatchHomeDashboardAccountJob;
use App\Models\ShipHeroOrderQueueIndex;
use App\Services\OrderDashboardSnapshotService;
use App\Services\PortalQueueCountsService;
use App\Services\ShipHeroOrderQueueIndexService;
use Mockery;
use Tests\TestCase;

class PatchHomeDashboardAccountJobTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_refreshes_portal_cache_and_bumps_revision_after_patch(): void
    {
        $accountId = 42;
        $tab = ShipHeroOrderQueueIndex::KIND_AWAITING;

        $index = Mockery::mock(ShipHeroOrderQueueIndexService::class);
        $index->shouldReceive('isQueueTab')->with($tab)->andReturn(true);
        $index->shouldReceive('syncAccountQueue')->once()->with($accountId, $tab);
        $index->shouldReceive('supplementAwaitingFromRecentUpdates')->once()->with($accountId);
        $index->shouldReceive('pruneNonAwaitingRows')->once()->with($accountId);

        $snapshots = Mockery::mock(OrderDashboardSnapshotService::class);
        $snapshots->shouldReceive('patchAccountFromQueueTab')->once()->with($accountId, $tab);

        $queueCounts = Mockery::mock(PortalQueueCountsService::class);
        $queueCounts->shouldReceive('refreshQueueCacheFromIndex')
            ->once()
            ->with($accountId, [$tab]);
        $queueCounts->shouldReceive('bumpCountsRevision')->once()->with($accountId);

        $job = new PatchHomeDashboardAccountJob($accountId, $tab);
        $job->handle($index, $snapshots, $queueCounts);

        $this->addToAssertionCount(1);
    }

    public function test_skips_awaiting_supplement_for_non_awaiting_tab(): void
    {
        $accountId = 7;
        $tab = ShipHeroOrderQueueIndex::KIND_SHIPPED;

        $index = Mockery::mock(ShipHeroOrderQueueIndexService::class);
        $index->shouldReceive('isQueueTab')->with($tab)->andReturn(true);
        $index->shouldReceive('syncAccountQueue')->once()->with($accountId, $tab);
        $index->shouldNotReceive('supplementAwaitingFromRecentUpdates');
        $index->shouldNotReceive('pruneNonAwaitingRows');

        $snapshots = Mockery::mock(OrderDashboardSnapshotService::class);
        $snapshots->shouldReceive('patchAccountFromQueueTab')->once()->with($accountId, $tab);

        $queueCounts = Mockery::mock(PortalQueueCountsService::class);
        $queueCounts->shouldReceive('refreshQueueCacheFromIndex')
            ->once()
            ->with($accountId, [$tab]);
        $queueCounts->shouldReceive('bumpCountsRevision')->once()->with($accountId);

        $job = new PatchHomeDashboardAccountJob($accountId, $tab);
        $job->handle($index, $snapshots, $queueCounts);

        $this->addToAssertionCount(1);
    }
}
