<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function summary(): JsonResponse
    {
        return response()->json([
            'total_users' => User::count(),
            'active_users' => User::where('status', 'active')->count(),
            'activities_today' => ActivityLog::query()->whereDate('created_at', today())->count(),
            'users_by_status' => [
                'pending' => User::query()->where('status', 'pending')->count(),
                'active' => User::query()->where('status', 'active')->count(),
                'inactive' => User::query()->where('status', 'inactive')->count(),
            ],
            'activity_last_7_days' => $this->activityLast7Days(),
        ]);
    }

    /**
     * @return array{labels: array<int, string>, values: array<int, int>}
     */
    private function activityLast7Days(): array
    {
        $labels = [];
        $values = [];

        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->startOfDay();
            $labels[] = $date->format('D');
            $values[] = ActivityLog::query()
                ->whereDate('created_at', $date->toDateString())
                ->count();
        }

        return [
            'labels' => $labels,
            'values' => $values,
        ];
    }
}
