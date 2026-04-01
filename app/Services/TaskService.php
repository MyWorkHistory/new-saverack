<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class TaskService
{
    public function paginate(array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 15);
        $perPage = $perPage > 0 && $perPage <= 500 ? $perPage : 15;

        $sortBy = $filters['sort_by'] ?? 'created_at';
        $allowedSort = ['created_at', 'title', 'status', 'priority', 'due_date'];
        if (! in_array($sortBy, $allowedSort, true)) {
            $sortBy = 'created_at';
        }

        $sortDir = strtolower((string) ($filters['sort_dir'] ?? 'desc'));
        $sortDir = $sortDir === 'asc' ? 'asc' : 'desc';

        $query = Task::query()->with([
            'creator:id,name,email',
            'assignee:id,name,email',
        ]);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (isset($filters['assigned_to']) && $filters['assigned_to'] !== '' && $filters['assigned_to'] !== null) {
            $query->where('assigned_to', (int) $filters['assigned_to']);
        }

        if (! empty($filters['account_name'])) {
            $term = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $filters['account_name']) . '%';
            $query->where('account_name', 'like', $term);
        }

        if (! empty($filters['search'])) {
            $term = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $filters['search']) . '%';
            $query->where(function (Builder $q) use ($term) {
                $q->where('title', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhere('account_name', 'like', $term);
            });
        }

        $query->orderBy($sortBy, $sortDir);
        if ($sortBy !== 'id') {
            $query->orderBy('id', 'desc');
        }

        $page = (int) ($filters['page'] ?? 1);

        return $query->paginate($perPage, ['*'], 'page', $page);
    }
}
