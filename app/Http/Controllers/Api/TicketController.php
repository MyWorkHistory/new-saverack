<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TicketCommentStoreRequest;
use App\Http\Requests\TicketStoreRequest;
use App\Http\Requests\TicketUpdateRequest;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Services\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    /** @var TicketService */
    protected $ticketService;

    public function __construct(TicketService $ticketService)
    {
        $this->ticketService = $ticketService;
        $this->authorizeResource(Ticket::class, 'ticket');
    }

    public function meta(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Ticket::class);

        return response()->json([
            'statuses' => [
                ['value' => 'backlog', 'label' => 'Backlog'],
                ['value' => 'todo', 'label' => 'To do'],
                ['value' => 'in_progress', 'label' => 'In progress'],
                ['value' => 'review', 'label' => 'Review'],
                ['value' => 'done', 'label' => 'Done'],
                ['value' => 'cancelled', 'label' => 'Cancelled'],
            ],
            'priorities' => [
                ['value' => 'low', 'label' => 'Low'],
                ['value' => 'medium', 'label' => 'Medium'],
                ['value' => 'high', 'label' => 'High'],
                ['value' => 'urgent', 'label' => 'Urgent'],
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $tickets = $this->ticketService->paginate($request->only([
            'search',
            'per_page',
            'page',
            'sort_by',
            'sort_dir',
            'status',
            'priority',
            'assigned_to',
        ]));

        $tickets->getCollection()->transform(function (Ticket $ticket) {
            return $this->transformTicket($ticket);
        });

        return response()->json($tickets);
    }

    public function store(TicketStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()->id;

        $ticket = Ticket::query()->create($data);

        return response()->json($this->transformTicket($ticket->fresh(['creator', 'assignee'])), 201);
    }

    public function show(Ticket $ticket): JsonResponse
    {
        $ticket->load([
            'creator:id,name,email',
            'assignee:id,name,email',
            'comments' => function ($q) {
                $q->orderBy('created_at')->with('user:id,name,email');
            },
        ]);

        return response()->json($this->transformTicket($ticket));
    }

    public function update(TicketUpdateRequest $request, Ticket $ticket): JsonResponse
    {
        $ticket->update($request->validated());

        return response()->json($this->transformTicket($ticket->fresh(['creator', 'assignee'])));
    }

    public function destroy(Request $request, Ticket $ticket): JsonResponse
    {
        $ticket->delete();

        return response()->json(['message' => 'Ticket deleted.']);
    }

    public function storeComment(TicketCommentStoreRequest $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('comment', $ticket);

        $comment = TicketComment::query()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $request->user()->id,
            'body' => $request->validated()['body'],
        ]);

        $comment->load('user:id,name,email');

        return response()->json($comment, 201);
    }

    /**
     * @param  \App\Models\Ticket  $ticket
     */
    protected function transformTicket($ticket): array
    {
        return [
            'id' => $ticket->id,
            'title' => $ticket->title,
            'description' => $ticket->description,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'due_date' => $ticket->due_date ? $ticket->due_date->format('Y-m-d') : null,
            'position' => $ticket->position,
            'created_by' => $ticket->created_by,
            'assigned_to' => $ticket->assigned_to,
            'created_at' => $ticket->created_at ? $ticket->created_at->toIso8601String() : null,
            'updated_at' => $ticket->updated_at ? $ticket->updated_at->toIso8601String() : null,
            'creator' => $ticket->relationLoaded('creator') ? $ticket->creator : null,
            'assignee' => $ticket->relationLoaded('assignee') ? $ticket->assignee : null,
            'comments' => $ticket->relationLoaded('comments')
                ? $ticket->comments->map(function (TicketComment $c) {
                    return [
                        'id' => $c->id,
                        'body' => $c->body,
                        'created_at' => $c->created_at ? $c->created_at->toIso8601String() : null,
                        'user' => $c->relationLoaded('user') ? $c->user : null,
                    ];
                })->values()->all()
                : null,
        ];
    }
}
