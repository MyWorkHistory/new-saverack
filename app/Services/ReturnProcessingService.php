<?php

namespace App\Services;

use App\Models\ClientAccountReturn;
use App\Models\ClientAccountReturnLine;
use App\Models\User;
use App\Support\Returns\ReturnReasonOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReturnProcessingService
{
    /** @var ReturnFeeService */
    private $fees;

    /** @var ReturnBillService */
    private $bills;

    /** @var ReturnBinService */
    private $bins;

    public function __construct(ReturnFeeService $fees, ReturnBillService $bills, ReturnBinService $bins)
    {
        $this->fees = $fees;
        $this->bills = $bills;
        $this->bins = $bins;
    }

    /**
     * @param  list<int>  $lineIds
     * @param  array<int, bool>  $restockByLineId
     */
    public function processPendingReturn(
        ClientAccountReturn $return,
        array $lineIds,
        array $restockByLineId,
        ?User $actor,
        int $binNumber
    ): ClientAccountReturn {
        if ($return->status !== ClientAccountReturn::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => ['Only pending returns can be processed.'],
            ]);
        }

        $lines = ClientAccountReturnLine::query()
            ->where('client_account_return_id', $return->id)
            ->get();

        $this->assertValidLineIds($lines, $lineIds);
        if ($return->isNonCompliant() || $return->isThirdParty()) {
            $this->assertStaffManagedLinesReady($lines, $lineIds);
        }

        return DB::transaction(function () use ($return, $lines, $lineIds, $restockByLineId, $actor, $binNumber) {
            $this->applyLineSelection($lines, $lineIds, $restockByLineId, true, $return);
            $this->finalizeProcessedReturn($return, $actor);
            $this->bins->assignReturnToBin($return->fresh(['lines']), $binNumber);

            return $return->fresh(['lines', 'clientAccount', 'returnBill']);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $normalizedLines
     */
    public function processFromDraft(
        ClientAccountReturn $return,
        array $normalizedLines,
        ?string $returnType,
        ?string $warehouseNote,
        ?float $firstItemFee,
        ?float $additionalItemFee,
        ?User $actor,
        int $binNumber
    ): ClientAccountReturn {
        if ($return->status !== ClientAccountReturn::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => ['Only draft returns can be processed from the create screen.'],
            ]);
        }
        if (! $return->isAdminCreated()) {
            throw ValidationException::withMessages([
                'created_source' => ['Only admin-created returns can use process-from-draft.'],
            ]);
        }

        return DB::transaction(function () use ($return, $normalizedLines, $returnType, $warehouseNote, $firstItemFee, $additionalItemFee, $actor, $binNumber) {
            if ($returnType !== null && $returnType !== '') {
                $return->return_type = $returnType;
            }
            if ($warehouseNote !== null) {
                $return->warehouse_private_note = $warehouseNote !== '' ? $warehouseNote : null;
            }
            if ($firstItemFee !== null) {
                $return->return_fee_first_item = round($firstItemFee, 4);
            }
            if ($additionalItemFee !== null) {
                $return->return_fee_additional_item = round($additionalItemFee, 4);
            }
            $return->save();

            $this->persistAdminLines($return, $normalizedLines);
            $this->finalizeProcessedReturn($return, $actor);
            $this->bins->assignReturnToBin($return->fresh(['lines']), $binNumber);

            return $return->fresh(['lines', 'clientAccount', 'returnBill']);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function validateAndNormalizeAdminLines(array $rows): array
    {
        $normalized = [];
        $hasPositive = false;
        $order = 0;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $orderQty = (int) ($row['order_qty'] ?? 0);
            $returnQty = (int) ($row['return_qty'] ?? 0);
            if ($returnQty < 0) {
                throw ValidationException::withMessages(['lines' => ['Return quantity cannot be negative.']]);
            }
            if ($returnQty > $orderQty) {
                throw ValidationException::withMessages(['lines' => ['Return quantity cannot exceed order quantity.']]);
            }
            $reason = isset($row['return_reason']) ? trim((string) $row['return_reason']) : null;
            if ($returnQty > 0) {
                $hasPositive = true;
                if ($reason === null || $reason === '') {
                    $reason = ReturnReasonOptions::adminDefaultKey();
                }
                if (! ReturnReasonOptions::isValidAdminKey($reason)) {
                    throw ValidationException::withMessages(['lines' => ['Invalid return reason selected.']]);
                }
            } else {
                $reason = null;
            }
            $sku = trim((string) ($row['sku'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            if ($sku === '' || $name === '') {
                continue;
            }
            $restock = array_key_exists('restock', $row) ? (bool) $row['restock'] : true;
            $normalized[] = [
                'shiphero_line_item_id' => isset($row['shiphero_line_item_id']) ? trim((string) $row['shiphero_line_item_id']) : null,
                'sku' => $sku,
                'name' => $name,
                'image_url' => isset($row['image_url']) ? trim((string) $row['image_url']) : null,
                'order_qty' => $orderQty,
                'return_qty' => $returnQty,
                'return_reason' => $reason,
                'restock' => $restock,
                'sort_order' => $order++,
            ];
        }
        if (! $hasPositive) {
            throw ValidationException::withMessages([
                'lines' => ['Select at least one item with a return quantity greater than zero.'],
            ]);
        }

        return $normalized;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ClientAccountReturnLine>  $lines
     * @param  list<int>  $lineIds
     */
    private function assertStaffManagedLinesReady($lines, array $lineIds): void
    {
        foreach ($lines as $line) {
            if (! in_array((int) $line->id, $lineIds, true)) {
                continue;
            }
            if ((int) $line->return_qty <= 0) {
                throw ValidationException::withMessages([
                    'line_ids' => ['Each selected item must have a return quantity greater than zero.'],
                ]);
            }
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ClientAccountReturnLine>  $lines
     * @param  list<int>  $lineIds
     */
    private function assertValidLineIds($lines, array $lineIds): void
    {
        if ($lineIds === []) {
            throw ValidationException::withMessages([
                'line_ids' => ['Select at least one item to process.'],
            ]);
        }
        $validIds = $lines->pluck('id')->map(fn ($id) => (int) $id)->all();
        foreach ($lineIds as $id) {
            if (! in_array($id, $validIds, true)) {
                throw ValidationException::withMessages([
                    'line_ids' => ['Invalid line selected.'],
                ]);
            }
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ClientAccountReturnLine>  $lines
     * @param  list<int>  $lineIds
     * @param  array<int, bool>  $restockByLineId
     */
    private function applyLineSelection($lines, array $lineIds, array $restockByLineId, bool $clearUnselected, ?ClientAccountReturn $return = null): void
    {
        foreach ($lines as $line) {
            $id = (int) $line->id;
            if (! in_array($id, $lineIds, true)) {
                if ($clearUnselected) {
                    $line->return_qty = 0;
                    $line->return_reason = null;
                    $line->restock = false;
                    $line->save();
                }
                continue;
            }
            if ($return !== null && $return->isNonCompliant() && $return->non_compliant_reason) {
                $line->return_reason = $return->non_compliant_reason;
            }
            if (array_key_exists($id, $restockByLineId)) {
                $line->restock = (bool) $restockByLineId[$id];
            }
            $line->save();
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $normalized
     */
    private function persistAdminLines(ClientAccountReturn $return, array $normalized): void
    {
        ClientAccountReturnLine::query()->where('client_account_return_id', $return->id)->delete();
        foreach ($normalized as $row) {
            $line = new ClientAccountReturnLine;
            $line->client_account_return_id = $return->id;
            $line->shiphero_line_item_id = $row['shiphero_line_item_id'] ?? null;
            $line->sku = $row['sku'];
            $line->name = $row['name'];
            $line->image_url = $row['image_url'] ?? null;
            $line->order_qty = (int) $row['order_qty'];
            $line->return_qty = (int) $row['return_qty'];
            $line->return_reason = $row['return_reason'] ?? null;
            $line->restock = (bool) ($row['restock'] ?? true);
            $line->sort_order = (int) $row['sort_order'];
            $line->save();
        }
        $sum = (int) ClientAccountReturnLine::query()
            ->where('client_account_return_id', $return->id)
            ->sum('return_qty');
        $return->items_count = $sum;
        $return->saveQuietly();
    }

    private function finalizeProcessedReturn(ClientAccountReturn $return, ?User $actor): void
    {
        $this->fees->seedReturnFees($return->fresh());
        $return->refresh();
        $this->fees->lockFees($return);
        $return->status = ClientAccountReturn::STATUS_RECEIVED;
        if ($return->processed_at === null) {
            $return->processed_at = now();
        }
        if ($actor !== null) {
            $return->processed_by_user_id = $actor->id;
        }
        $return->save();

        $this->bills->createFromProcessedReturn($return->fresh(['lines']), $actor);
    }
}
