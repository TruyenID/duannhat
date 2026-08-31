<?php

namespace App\Services\Inventory;

use App\Models\DisposalRecord;
use App\Models\MaterialLot;
use App\Models\StockTransactionItem;
use App\Support\BusinessClock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DisposalService
{
    public function __construct(
        private readonly StockTransactionService $stockTransactionService,
    ) {}

    // =========================================================================
    //  Query
    // =========================================================================

    /**
     * @param  array{organization_id?: string, stock_transaction_item_id?: string, disposal_reason?: string, date_from?: string, date_to?: string, sort?: string, per_page?: int}  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = DisposalRecord::query()
            ->with(['stockTransactionItem']);

        if (! empty($filters['organization_id'])) {
            $query->whereHas('stockTransactionItem.stockTransaction', function ($q) use ($filters) {
                $q->where('organization_id', $filters['organization_id']);
            });
        }

        $query->when($filters['stock_transaction_item_id'] ?? null, fn ($q, $id) => $q->where('stock_transaction_item_id', $id));
        $query->when($filters['disposal_reason'] ?? null, fn ($q, $reason) => $q->where('disposal_reason', $reason));

        // #1091 — filter dates are BRANCH-local; convert to UTC instant
        // bounds instead of whereDate (which compares in the DB's UTC day).
        [$dateFrom, $dateUntil] = BusinessClock::utcRangeForBusinessDates(
            $filters['branch_id'] ?? null,
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null,
        );
        $query->when($dateFrom, fn ($q) => $q->where('created_at', '>=', $dateFrom));
        $query->when($dateUntil, fn ($q) => $q->where('created_at', '<', $dateUntil));

        $sort = $filters['sort'] ?? '-created_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $query->orderBy($column, $direction);

        return $query->paginate($filters['per_page'] ?? 25);
    }

    public function findById(string $id): DisposalRecord
    {
        return DisposalRecord::with(['stockTransactionItem'])->findOrFail($id);
    }

    // =========================================================================
    //  Create
    // =========================================================================

    public function create(array $data): DisposalRecord
    {
        return DB::transaction(function () use ($data) {
            $item = StockTransactionItem::with('stockTransaction')
                ->findOrFail($data['stock_transaction_item_id']);

            // plan-040 M12: a disposal of a lot-tracked item MUST actually move
            // stock. Previously create() wrote a DisposalRecord while the
            // physical lot stayed full — blinding FEFO, low-stock alerts and
            // recall blast-radius. Guard qty ≤ on-hand, deduct qty_on_hand under
            // a row lock, and write a balanced StockMovement through the
            // stock-transaction ledger (an adjustment_out backing transaction).
            if ($item->material_lot_id !== null) {
                $lot = MaterialLot::lockForUpdate()->findOrFail($item->material_lot_id);

                $disposeQty = (float) $item->base_quantity;
                $onHand = (float) $lot->qty_on_hand;

                if ($disposeQty > $onHand) {
                    throw ValidationException::withMessages([
                        'quantity' => "Disposal quantity ({$disposeQty}) exceeds lot on-hand ({$onHand}).",
                    ]);
                }

                $newQty = $onHand - $disposeQty;
                $lot->update(['qty_on_hand' => $newQty]);

                $this->stockTransactionService->recordSplitMovements([
                    'organization_id' => (string) ($item->stockTransaction?->organization_id ?? $lot->organization_id),
                    'warehouse_id' => (string) ($item->stockTransaction?->warehouse_id ?? $lot->warehouse_id),
                    'reference_type' => 'disposal_record',
                    'reference_id' => (string) $item->id,
                    'created_by_id' => $item->stockTransaction?->created_by_id,
                    'note' => "Disposal of lot {$lot->lot_code}",
                    'movements' => [[
                        'material_id' => (string) $lot->material_id,
                        'material_lot_id' => (string) $lot->id,
                        'warehouse_id' => (string) $lot->warehouse_id,
                        'movement_type' => 'out',
                        'quantity' => $disposeQty,
                        'quantity_before' => $onHand,
                        'quantity_after' => $newQty,
                        'unit' => $item->unit ?? $lot->unit,
                    ]],
                ]);
            }

            $record = DisposalRecord::create($data);

            return $record->load(['stockTransactionItem']);
        });
    }

    // =========================================================================
    //  Waste Report — aggregates of completed disposal transactions
    // =========================================================================

    /**
     * Build the four aggregations used by the waste-report UI in one
     * round-trip. Each panel (by_reason / top_items / daily_trend /
     * summary) reuses the same base filter: completed disposal
     * transactions in the tenant and (optionally) warehouse / date range.
     *
     * @param  array{organization_id: string, warehouse_id?: string, date_from: string, date_to: string, limit?: int}  $filters
     * @return array{by_reason: array<int, array<string, mixed>>, top_items: array<int, array<string, mixed>>, daily_trend: array<int, array<string, mixed>>, summary: array{total_value: float, total_transactions: int, total_items: int}}
     */
    public function wasteReport(array $filters): array
    {
        $limit = (int) ($filters['limit'] ?? 10);

        // #1091 — report bounds in the branch's business days, not UTC days.
        [$reportFrom, $reportUntil] = BusinessClock::utcRangeForBusinessDates(
            $filters['branch_id'] ?? null,
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null,
        );
        $base = function () use ($filters, $reportFrom, $reportUntil) {
            $q = DB::table('disposal_records as dr')
                ->join('stock_transaction_items as sti', 'dr.stock_transaction_item_id', '=', 'sti.id')
                ->join('stock_transactions as st', 'sti.stock_transaction_id', '=', 'st.id')
                ->where('st.sub_type', 'disposal')
                ->where('st.status', 'completed')
                ->where('st.organization_id', $filters['organization_id'])
                ->when($reportFrom, fn ($q) => $q->where('st.completed_at', '>=', $reportFrom))
                ->when($reportUntil, fn ($q) => $q->where('st.completed_at', '<', $reportUntil));

            if (! empty($filters['warehouse_id'])) {
                $q->where('st.warehouse_id', $filters['warehouse_id']);
            }

            return $q;
        };

        $byReason = $base()
            ->selectRaw('dr.disposal_reason, COUNT(DISTINCT st.id) as transaction_count, COUNT(dr.id) as item_count, COALESCE(SUM(sti.base_quantity * dr.cost_at_disposal), 0) as total_value')
            ->groupBy('dr.disposal_reason')
            ->orderByDesc('total_value')
            ->get()
            ->map(fn ($row) => [
                'disposal_reason' => $row->disposal_reason,
                'transaction_count' => (int) $row->transaction_count,
                'item_count' => (int) $row->item_count,
                'total_value' => (float) $row->total_value,
            ])
            ->all();

        $topItems = $base()
            ->leftJoin('product_skus as ps', 'sti.product_sku_id', '=', 'ps.id')
            ->leftJoin('materials as m', 'sti.material_id', '=', 'm.id')
            ->selectRaw(
                'COALESCE(ps.name, m.name) as item_name,
                 COALESCE(ps.sku, m.sku) as item_sku,
                 COALESCE(sti.product_sku_id, sti.material_id) as item_key,
                 sti.unit,
                 dr.disposal_reason as main_reason,
                 COALESCE(SUM(sti.base_quantity), 0) as total_quantity,
                 COALESCE(SUM(sti.base_quantity * dr.cost_at_disposal), 0) as total_value'
            )
            ->groupBy('item_key', 'item_name', 'item_sku', 'sti.unit', 'dr.disposal_reason')
            ->orderByDesc('total_value')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'item_name' => $row->item_name,
                'item_sku' => $row->item_sku,
                'total_quantity' => (float) $row->total_quantity,
                'unit' => $row->unit,
                'total_value' => (float) $row->total_value,
                'main_reason' => $row->main_reason,
            ])
            ->all();

        $dailyTrend = $base()
            ->selectRaw('DATE(st.completed_at) as date, COALESCE(SUM(sti.base_quantity * dr.cost_at_disposal), 0) as total_value')
            ->groupByRaw('DATE(st.completed_at)')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => [
                'date' => (string) $row->date,
                'total_value' => (float) $row->total_value,
            ])
            ->all();

        $summaryRow = $base()
            ->selectRaw('COALESCE(SUM(sti.base_quantity * dr.cost_at_disposal), 0) as total_value, COUNT(DISTINCT st.id) as total_transactions, COUNT(dr.id) as total_items')
            ->first();

        return [
            'by_reason' => $byReason,
            'top_items' => $topItems,
            'daily_trend' => $dailyTrend,
            'summary' => [
                'total_value' => (float) ($summaryRow->total_value ?? 0),
                'total_transactions' => (int) ($summaryRow->total_transactions ?? 0),
                'total_items' => (int) ($summaryRow->total_items ?? 0),
            ],
        ];
    }
}
