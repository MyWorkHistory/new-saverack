<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class DashboardController extends Controller
{
    public function summary(): JsonResponse
    {
        $today = today();
        $activitiesToday = ActivityLog::query()->whereDate('created_at', $today)->count();
        $activitiesYesterday = ActivityLog::query()->whereDate('created_at', $today->copy()->subDay())->count();

        return response()->json([
            'total_users' => User::count(),
            'active_users' => User::where('status', 'active')->count(),
            'activities_today' => $activitiesToday,
            'users_by_status' => [
                'pending' => User::query()->where('status', 'pending')->count(),
                'active' => User::query()->where('status', 'active')->count(),
                'inactive' => User::query()->where('status', 'inactive')->count(),
            ],
            'activity_last_7_days' => $this->activityLast7Days(),
            'chart' => $this->activityLast12Months(),
            'metrics' => [
                'total_users' => [
                    'value' => User::count(),
                    'change_pct' => $this->monthOverMonthTotalUsersPct(),
                ],
                'active_users' => [
                    'value' => User::where('status', 'active')->count(),
                    'change_pct' => $this->activeUsersNewThisMonthVsLast(),
                ],
                'activities_today' => [
                    'value' => $activitiesToday,
                    'change_pct' => $this->pctChange($activitiesToday, $activitiesYesterday),
                ],
            ],
            'year_totals' => [
                'activity_this_year' => ActivityLog::query()->whereYear('created_at', now()->year)->count(),
                'activity_last_year' => ActivityLog::query()->whereYear('created_at', now()->year - 1)->count(),
                'users_this_year' => User::query()->whereYear('created_at', now()->year)->count(),
                'users_last_year' => User::query()->whereYear('created_at', now()->year - 1)->count(),
            ],
            'recent_users' => User::query()
                ->with([
                    'roles:id,name,label',
                    'profile' => static function ($q) {
                        $q->select('id', 'user_id', 'job_position', 'birthday', 'hire_date');
                    },
                ])
                ->latest()
                ->limit(12)
                ->get(['id', 'name', 'email', 'status', 'created_at'])
                ->map(static function (User $u) {
                    $p = $u->profile;

                    return [
                        'id' => $u->id,
                        'name' => $u->name,
                        'email' => $u->email,
                        'status' => $u->status,
                        'created_at' => optional($u->created_at)->toIso8601String(),
                        'roles' => $u->roles->map(static fn ($r) => [
                            'id' => $r->id,
                            'name' => $r->name,
                            'label' => $r->label,
                        ])->values()->all(),
                        'job_position' => $p?->job_position,
                        'birthday' => $p && $p->birthday ? $p->birthday->toDateString() : null,
                        'hire_date' => $p && $p->hire_date ? $p->hire_date->toDateString() : null,
                    ];
                }),
            'recent_activity' => ActivityLog::query()
                ->with('user:id,name')
                ->latest()
                ->limit(6)
                ->get()
                ->map(fn (ActivityLog $log) => [
                    'id' => $log->id,
                    'title' => Str::title(str_replace('_', ' ', $log->action ?? 'activity')),
                    'description' => Str::limit((string) ($log->description ?? ''), 72),
                    'at' => $log->created_at->toIso8601String(),
                ]),
            'engagement_score' => $this->engagementScore(),
        ]);
    }

    private function engagementScore(): int
    {
        $active = User::where('status', 'active')->count();
        $today = ActivityLog::query()->whereDate('created_at', today())->count();

        if ($active === 0) {
            return 0;
        }

        return (int) min(100, round(($today / max($active, 1)) * 100));
    }

    private function pctChange(int $current, int $previous): float
    {
        if ($previous === 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function monthOverMonthTotalUsersPct(): float
    {
        $endLastMonth = now()->subMonthNoOverflow()->endOfMonth();
        $totalThen = User::query()->where('created_at', '<=', $endLastMonth)->count();
        $totalNow = User::query()->count();

        if ($totalThen === 0) {
            return $totalNow > 0 ? 100.0 : 0.0;
        }

        return round((($totalNow - $totalThen) / $totalThen) * 100, 1);
    }

    /** New active accounts registered this month vs prior calendar month. */
    private function activeUsersNewThisMonthVsLast(): float
    {
        $startThis = now()->startOfMonth();
        $thisMonthNewActive = User::query()
            ->where('status', 'active')
            ->where('created_at', '>=', $startThis)
            ->count();

        $startLast = now()->subMonthNoOverflow()->startOfMonth();
        $endLast = now()->subMonthNoOverflow()->endOfMonth();
        $lastMonthNewActive = User::query()
            ->where('status', 'active')
            ->whereBetween('created_at', [$startLast, $endLast])
            ->count();

        return $this->pctChange($thisMonthNewActive, $lastMonthNewActive);
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

    /**
     * @return array{labels: array<int, string>, activity: array<int, int>, new_users: array<int, int>}
     */
    private function activityLast12Months(): array
    {
        $labels = [];
        $activity = [];
        $newUsers = [];

        for ($i = 11; $i >= 0; $i--) {
            $start = now()->subMonths($i)->startOfMonth();
            $end = (clone $start)->endOfMonth();
            $labels[] = $start->format('M');
            $activity[] = ActivityLog::query()
                ->whereBetween('created_at', [$start, $end])
                ->count();
            $newUsers[] = User::query()
                ->whereBetween('created_at', [$start, $end])
                ->count();
        }

        return [
            'labels' => $labels,
            'activity' => $activity,
            'new_users' => $newUsers,
        ];
    }
}
