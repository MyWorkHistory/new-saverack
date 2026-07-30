<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\LtlShipment;
use App\Models\LtlShipmentComment;
use App\Models\LtlShipmentPallet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LtlShipmentService
{
    /** @var LtlShipmentSlackService */
    private $slack;

    public function __construct(LtlShipmentSlackService $slack)
    {
        $this->slack = $slack;
    }

    public function nextNumber(): string
    {
        $last = LtlShipment::query()
            ->orderByDesc('id')
            ->value('number');
        $seq = 1;
        if (is_string($last) && preg_match('/(\d+)\s*$/', $last, $m)) {
            $seq = ((int) $m[1]) + 1;
        }

        return 'LTL #'.str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(ClientAccount $account, array $data, ?User $actor = null): LtlShipment
    {
        return DB::transaction(function () use ($account, $data, $actor) {
            $shipment = LtlShipment::query()->create([
                'client_account_id' => $account->id,
                'number' => $this->nextNumber(),
                'status' => LtlShipment::STATUS_DRAFT,
                'direction' => (string) $data['direction'],
                'company_name' => $data['company_name'] ?? null,
                'address_line1' => $data['address_line1'] ?? null,
                'address_line2' => $data['address_line2'] ?? null,
                'city' => $data['city'] ?? null,
                'state' => $data['state'] ?? null,
                'zip' => $data['zip'] ?? null,
                'country' => $data['country'] ?? 'United States',
                'time_mode' => LtlShipment::TIME_ASAP,
                'quote_service' => 'standard_ltl',
                'created_by_user_id' => $actor !== null ? $actor->id : null,
            ]);

            return $shipment->fresh(['clientAccount', 'pallets']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateDetails(LtlShipment $shipment, array $data): LtlShipment
    {
        $fillable = [
            'direction',
            'company_name',
            'address_line1',
            'address_line2',
            'city',
            'state',
            'zip',
            'country',
            'contact_name',
            'contact_email',
            'contact_phone',
            'time_mode',
            'time_from',
            'time_to',
            'load_requirement',
            'pickup_type',
            'notes',
            'quote_amount_cents',
            'quote_carrier',
            'quote_transit_time',
            'quote_service',
            'tracking_number',
        ];

        foreach ($fillable as $key) {
            if (array_key_exists($key, $data)) {
                $shipment->{$key} = $data[$key];
            }
        }

        if (($shipment->time_mode ?? '') !== LtlShipment::TIME_SPECIFIC) {
            $shipment->time_from = null;
            $shipment->time_to = null;
        }

        $shipment->save();

        return $shipment->fresh(['clientAccount', 'pallets']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addPallet(LtlShipment $shipment, array $data): LtlShipmentPallet
    {
        $sort = (int) ($shipment->pallets()->max('sort_order') ?? 0) + 1;

        return $shipment->pallets()->create([
            'sort_order' => $sort,
            'commodity' => $data['commodity'] ?? null,
            'length_in' => $data['length_in'] ?? null,
            'width_in' => $data['width_in'] ?? null,
            'height_in' => $data['height_in'] ?? null,
            'weight_lbs' => $data['weight_lbs'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePallet(LtlShipmentPallet $pallet, array $data): LtlShipmentPallet
    {
        foreach (['commodity', 'length_in', 'width_in', 'height_in', 'weight_lbs', 'sort_order'] as $key) {
            if (array_key_exists($key, $data)) {
                $pallet->{$key} = $data[$key];
            }
        }
        $pallet->save();

        return $pallet->fresh();
    }

    public function deletePallet(LtlShipmentPallet $pallet): void
    {
        $pallet->delete();
    }

    /**
     * @return list<string>
     */
    public function quoteValidationErrors(LtlShipment $shipment): array
    {
        $shipment->loadMissing('pallets');
        $errors = [];

        if (! in_array($shipment->direction, LtlShipment::DIRECTIONS, true)) {
            $errors[] = 'Location is required.';
        }
        if (trim((string) $shipment->company_name) === '') {
            $errors[] = 'Company name is required.';
        }
        if (trim((string) $shipment->address_line1) === '') {
            $errors[] = 'Address is required.';
        }
        if (trim((string) $shipment->city) === '') {
            $errors[] = 'City is required.';
        }
        if (trim((string) $shipment->state) === '') {
            $errors[] = 'State is required.';
        }
        if (trim((string) $shipment->zip) === '') {
            $errors[] = 'Zip is required.';
        }
        if (trim((string) $shipment->contact_name) === '') {
            $errors[] = 'Contact name is required.';
        }
        if (trim((string) $shipment->contact_email) === '') {
            $errors[] = 'Contact email is required.';
        }
        if (trim((string) $shipment->contact_phone) === '') {
            $errors[] = 'Contact phone is required.';
        }
        if (trim((string) ($shipment->load_requirement ?? '')) === '') {
            $errors[] = 'Load requirement is required.';
        }
        if (trim((string) ($shipment->pickup_type ?? '')) === '') {
            $errors[] = 'Pickup type is required.';
        }
        if ($shipment->pallets->count() < 1) {
            $errors[] = 'Add at least one pallet.';
        }
        foreach ($shipment->pallets as $pallet) {
            if (trim((string) $pallet->commodity) === ''
                || $pallet->length_in === null
                || $pallet->width_in === null
                || $pallet->height_in === null
                || $pallet->weight_lbs === null
            ) {
                $errors[] = 'Each pallet needs commodity, dimensions, and weight.';
                break;
            }
        }

        $mode = (string) ($shipment->time_mode ?? '');
        if ($mode === '') {
            $errors[] = 'Pick up / delivery time is required.';
        } elseif ($mode === LtlShipment::TIME_SPECIFIC) {
            if ($shipment->time_from === null || $shipment->time_to === null) {
                $errors[] = 'Specific time requires From and To date/time.';
            }
        }

        return array_values(array_unique($errors));
    }

    public function requestQuote(LtlShipment $shipment): LtlShipment
    {
        if ($shipment->status !== LtlShipment::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => 'Get Quote is only available for Draft LTLs.',
            ]);
        }

        $errors = $this->quoteValidationErrors($shipment);
        if ($errors !== []) {
            throw ValidationException::withMessages([
                'quote' => $errors,
            ]);
        }

        $shipment->status = LtlShipment::STATUS_PENDING;
        $shipment->save();
        $shipment = $shipment->fresh(['clientAccount', 'pallets']);
        $this->slack->notifyQuoteRequest($shipment);

        return $shipment;
    }

    public function updateStatus(LtlShipment $shipment, string $status): LtlShipment
    {
        if (! in_array($status, LtlShipment::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Invalid status.']);
        }

        $previous = $shipment->status;
        $shipment->status = $status;
        $shipment->save();
        $shipment = $shipment->fresh(['clientAccount', 'pallets']);

        if ($status === LtlShipment::STATUS_QUOTED && $previous !== LtlShipment::STATUS_QUOTED) {
            $this->slack->notifyQuoteReady($shipment);
        }
        if ($status === LtlShipment::STATUS_SCHEDULED && $previous !== LtlShipment::STATUS_SCHEDULED) {
            $this->slack->notifyScheduled($shipment);
        }

        return $shipment;
    }

    /**
     * @return array<string, mixed>
     */
    public function addComment(LtlShipment $shipment, User $user, string $body): LtlShipmentComment
    {
        $comment = LtlShipmentComment::query()->create([
            'ltl_shipment_id' => $shipment->id,
            'user_id' => $user->id,
            'body' => $body,
        ]);
        $comment->load(['user:id,name,email', 'user.profile:id,user_id,avatar_path']);

        return $comment;
    }

    /**
     * @return array<string, mixed>
     */
    public function commentToApiArray(LtlShipmentComment $comment): array
    {
        $comment->loadMissing(['user:id,name,email', 'user.profile:id,user_id,avatar_path']);
        $u = $comment->user;
        $avatarUrl = null;
        if ($u !== null && $u->relationLoaded('profile') && $u->profile !== null) {
            $avatarUrl = $u->profile->avatar_url;
        }

        return [
            'id' => $comment->id,
            'user_id' => $comment->user_id,
            'body' => $comment->body,
            'created_at' => $comment->created_at !== null ? $comment->created_at->toIso8601String() : null,
            'updated_at' => $comment->updated_at !== null ? $comment->updated_at->toIso8601String() : null,
            'user' => $u !== null
                ? [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'avatar_url' => $avatarUrl,
                ]
                : null,
        ];
    }

    /**
     * @param  bool  $includeComments
     * @return array<string, mixed>
     */
    public function toApiArray(LtlShipment $shipment, bool $includeComments = true): array
    {
        $shipment->loadMissing(['clientAccount', 'pallets']);
        if ($includeComments) {
            $shipment->loadMissing(['comments.user.profile']);
        }
        $account = $shipment->clientAccount;

        return [
            'id' => $shipment->id,
            'number' => $shipment->number,
            'status' => $shipment->status,
            'status_label' => $shipment->statusLabel(),
            'direction' => $shipment->direction,
            'direction_label' => $shipment->directionLabel(),
            'destination_label' => $shipment->destinationLabel(),
            'address_card_title' => $shipment->addressCardTitle(),
            'client_account_id' => (int) $shipment->client_account_id,
            'account_name' => $account !== null ? (string) $account->company_name : '',
            'company_name' => $shipment->company_name,
            'address_line1' => $shipment->address_line1,
            'address_line2' => $shipment->address_line2,
            'city' => $shipment->city,
            'state' => $shipment->state,
            'zip' => $shipment->zip,
            'country' => $shipment->country,
            'contact_name' => $shipment->contact_name,
            'contact_email' => $shipment->contact_email,
            'contact_phone' => $shipment->contact_phone,
            'time_mode' => $shipment->time_mode,
            'time_from' => $shipment->time_from !== null ? $shipment->time_from->toIso8601String() : null,
            'time_to' => $shipment->time_to !== null ? $shipment->time_to->toIso8601String() : null,
            'load_requirement' => $shipment->load_requirement,
            'pickup_type' => $shipment->pickup_type,
            'notes' => $shipment->notes,
            'comments' => $includeComments
                ? $shipment->comments->map(function (LtlShipmentComment $c) {
                    return $this->commentToApiArray($c);
                })->values()->all()
                : [],
            'quote_amount_cents' => $shipment->quote_amount_cents,
            'quote_carrier' => $shipment->quote_carrier,
            'quote_transit_time' => $shipment->quote_transit_time,
            'quote_service' => $shipment->quote_service,
            'tracking_number' => $shipment->tracking_number,
            'pallet_count' => $shipment->pallets->count(),
            'pallets' => $shipment->pallets->map(function (LtlShipmentPallet $p) {
                return [
                    'id' => $p->id,
                    'sort_order' => (int) $p->sort_order,
                    'commodity' => $p->commodity,
                    'length_in' => $p->length_in,
                    'width_in' => $p->width_in,
                    'height_in' => $p->height_in,
                    'weight_lbs' => $p->weight_lbs,
                ];
            })->values()->all(),
            'created_at' => $shipment->created_at !== null ? $shipment->created_at->toIso8601String() : null,
            'updated_at' => $shipment->updated_at !== null ? $shipment->updated_at->toIso8601String() : null,
            'facility' => config('ltl.facility'),
            'meta' => [
                'directions' => config('ltl.directions'),
                'statuses' => config('ltl.statuses'),
                'load_requirements' => config('ltl.load_requirements'),
                'pickup_types' => config('ltl.pickup_types'),
                'services' => config('ltl.services'),
                'time_modes' => config('ltl.time_modes'),
            ],
        ];
    }
}
