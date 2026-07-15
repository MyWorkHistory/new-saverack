<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResourceCalendarEventBulkDeleteRequest;
use App\Http\Requests\ResourceCalendarEventStoreRequest;
use App\Http\Requests\ResourceCalendarEventUpdateRequest;
use App\Models\ResourceCalendarEvent;
use App\Models\User;
use App\Services\ResourceCalendarEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResourceCalendarEventController extends Controller
{
    public function __construct(
        private readonly ResourceCalendarEventService $calendarEvents
    ) {}

    public function meta(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ResourceCalendarEvent::class);

        return response()->json([
            'categories' => ResourceCalendarEvent::categoryOptions(),
            'repeats' => [
                ['value' => ResourceCalendarEvent::REPEAT_NONE, 'label' => ResourceCalendarEvent::repeatLabel(ResourceCalendarEvent::REPEAT_NONE)],
                ['value' => ResourceCalendarEvent::REPEAT_MONTHLY, 'label' => ResourceCalendarEvent::repeatLabel(ResourceCalendarEvent::REPEAT_MONTHLY)],
                ['value' => ResourceCalendarEvent::REPEAT_YEARLY, 'label' => ResourceCalendarEvent::repeatLabel(ResourceCalendarEvent::REPEAT_YEARLY)],
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ResourceCalendarEvent::class);

        $user = $this->requireUser($request);

        $query = ResourceCalendarEvent::query()
            ->visibleTo($user)
            ->with('creator:id,name,email');

        if ($request->boolean('list')) {
            $validated = $request->validate([
                'query' => ['sometimes', 'nullable', 'string', 'max:255'],
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
                'page' => ['sometimes', 'integer', 'min:1'],
            ]);
            $search = trim((string) ($validated['query'] ?? ''));
            if ($search !== '') {
                $query->where('title', 'like', '%'.$search.'%');
            }

            $perPage = (int) ($validated['per_page'] ?? 25);
            $paginator = $query
                ->orderByDesc('start_date')
                ->orderByDesc('id')
                ->paginate($perPage);

            return response()->json([
                'data' => collect($paginator->items())
                    ->map(fn (ResourceCalendarEvent $event) => $this->transformEvent($event))
                    ->values()
                    ->all(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ]);
        }

        if ($request->boolean('upcoming')) {
            $limit = max(1, min((int) $request->input('limit', 4), 20));
            $today = now()->toDateString();

            $events = $query
                ->where('end_date', '>=', $today)
                ->orderBy('start_date')
                ->orderBy('id')
                ->limit($limit)
                ->get();

            return response()->json([
                'data' => $events->map(fn (ResourceCalendarEvent $event) => $this->transformEvent($event))->values()->all(),
            ]);
        }

        $validated = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after_or_equal:start'],
        ]);

        $events = $query
            ->where('start_date', '<=', $validated['end'])
            ->where('end_date', '>=', $validated['start'])
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $events->map(fn (ResourceCalendarEvent $event) => $this->transformEvent($event))->values()->all(),
        ]);
    }

    public function store(ResourceCalendarEventStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_by_user_id'] = $request->user()->id;
        $data['is_personal'] = (bool) ($data['is_personal'] ?? false);
        $data['description'] = isset($data['description']) ? trim((string) $data['description']) : null;
        if ($data['description'] === '') {
            $data['description'] = null;
        }

        $created = $this->calendarEvents->createWithRepeat($data);
        $first = $created[0] ?? null;
        if (! $first instanceof ResourceCalendarEvent) {
            return response()->json(['message' => 'Could not create event.'], 500);
        }

        $payload = $this->transformEvent($first);
        $payload['created_count'] = count($created);

        return response()->json($payload, 201);
    }

    public function update(ResourceCalendarEventUpdateRequest $request, ResourceCalendarEvent $calendarEvent): JsonResponse
    {
        $this->authorize('view', $calendarEvent);

        $data = $request->validated();
        unset($data['repeat'], $data['series_id']);

        if (array_key_exists('description', $data)) {
            $data['description'] = $data['description'] !== null ? trim((string) $data['description']) : null;
            if ($data['description'] === '') {
                $data['description'] = null;
            }
        }

        if (array_key_exists('is_personal', $data)) {
            $data['is_personal'] = (bool) $data['is_personal'];
        }

        $calendarEvent->fill($data);
        $calendarEvent->save();

        return response()->json($this->transformEvent($calendarEvent->fresh('creator')));
    }

    public function destroy(Request $request, ResourceCalendarEvent $calendarEvent): JsonResponse
    {
        $this->authorize('delete', $calendarEvent);
        $this->authorize('view', $calendarEvent);

        $calendarEvent->delete();

        return response()->json(['deleted' => true]);
    }

    public function bulkDestroy(ResourceCalendarEventBulkDeleteRequest $request): JsonResponse
    {
        $ids = array_map('intval', $request->validated()['ids']);
        $user = $this->requireUser($request);

        $events = ResourceCalendarEvent::query()
            ->visibleTo($user)
            ->whereIn('id', $ids)
            ->get();

        $deleted = 0;
        foreach ($events as $event) {
            if (! $user->can('delete', $event)) {
                continue;
            }
            $event->delete();
            $deleted++;
        }

        return response()->json([
            'message' => 'Events deleted.',
            'deleted' => $deleted,
        ]);
    }

    private function requireUser(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function transformEvent(ResourceCalendarEvent $event): array
    {
        return [
            'id' => (int) $event->id,
            'title' => (string) $event->title,
            'category' => (string) $event->category,
            'category_label' => ResourceCalendarEvent::categoryLabel($event->category),
            'category_color' => ResourceCalendarEvent::categoryColor($event->category),
            'start_date' => optional($event->start_date)->toDateString(),
            'end_date' => optional($event->end_date)->toDateString(),
            'description' => $event->description,
            'is_personal' => (bool) $event->is_personal,
            'repeat' => ResourceCalendarEvent::normalizeRepeat($event->repeat ?? null),
            'repeat_label' => ResourceCalendarEvent::repeatLabel($event->repeat ?? null),
            'series_id' => $event->series_id,
            'created_by_user_id' => (int) $event->created_by_user_id,
            'creator' => $event->relationLoaded('creator') && $event->creator
                ? [
                    'id' => (int) $event->creator->id,
                    'name' => (string) $event->creator->name,
                    'email' => (string) $event->creator->email,
                ]
                : null,
            'created_at' => optional($event->created_at)->toIso8601String(),
            'updated_at' => optional($event->updated_at)->toIso8601String(),
        ];
    }
}
