<?php

namespace App\Services\Shop;

use App\Models\BrandOrderPolicy;
use App\Models\Table;
use App\Services\Order\Contracts\OpenOrderTableUsage;
use App\Support\QrTokenGenerator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class TableService
{
    public function __construct(
        private readonly QrTokenGenerator $qrTokenGenerator,
    ) {}

    // =========================================================================
    //  CRUD
    // =========================================================================

    /**
     * @param  array{branch_id?: string, zone_id?: string, status?: string, is_active?: bool, search?: string, with_trashed?: bool, sort?: string, per_page?: int}  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Table::query()->with(['zone:id,code,name', 'lastStatusChange']);

        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }

        $query->when($filters['zone_id'] ?? null, fn ($q, $id) => $q->where('zone_id', $id));
        $query->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s));
        $query->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', $filters['is_active']));

        $query->when($filters['search'] ?? null, function ($q, $search) {
            $q->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        });

        if (! empty($filters['with_trashed'])) {
            $query->withTrashed();
        }

        $sort = $filters['sort'] ?? 'code';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $query->orderBy($column, $direction);

        return $query->paginate($filters['per_page'] ?? 25);
    }

    public function findById(string $id): Table
    {
        return Table::with(['zone:id,code,name', 'lastStatusChange'])->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Table
    {
        return DB::transaction(function () use ($data) {
            $data['status'] = $data['status'] ?? 'free';
            $data['is_active'] = $data['is_active'] ?? true;
            $data['seat_count'] = $data['seat_count'] ?? 2;
            $data['qr_token'] = $this->qrTokenGenerator->generate(Table::class);

            return Table::create($data)->load(['zone:id,code,name', 'lastStatusChange']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Table $table, array $data): Table
    {
        // issue #890 / BR-T09: tables copied from an HQ template carry the
        // brand's default layout — editable by the shop only when HQ turned on
        // the brand-level allow_shop_edit_hq_tables switch. Toggle-active,
        // runtime status, and QR rotation are always allowed either way.
        if ($table->table_template_id !== null && ! $this->shopMayEditHqTables($table)) {
            abort(response()->json([
                'message' => 'This table came from the HQ default layout and cannot be edited by the shop.',
                'code' => 'TABLE_UPDATE_BLOCKED_HQ_TEMPLATE',
            ], 409));
        }

        // qr_token rotates only via regenerateQr; never let an update body smuggle a new value.
        unset($data['qr_token'], $data['status']);

        $table->update($data);

        return $table->load(['zone:id,code,name', 'lastStatusChange']);
    }

    public function delete(Table $table): bool
    {
        // issue #890 / BR-T09: tables copied from an HQ template belong to the
        // brand's default layout — the shop may deactivate them but never
        // delete them. Shop-created tables (table_template_id = null) delete
        // as usual.
        if ($table->table_template_id !== null) {
            abort(response()->json([
                'message' => 'This table came from the HQ default layout and cannot be deleted by the shop. Deactivate it instead.',
                'code' => 'TABLE_DELETE_BLOCKED_HQ_TEMPLATE',
            ], 409));
        }

        // plan-042: block deleting a table still referenced by an open order —
        // its live occupancy (current_order_id) or an open order's table_id. A
        // soft delete bypasses the DB FK, so this guard must live here.
        //
        // #962 (7b) — vế thứ hai hỏi qua cổng Ordering công bố. Vế thứ nhất
        // (`current_order_id`) ở lại: đó là cột CHIẾM DỤNG trên chính `tables`,
        // do Organization sở hữu, không phải câu hỏi về đơn.
        $hasOpenOrder = $table->current_order_id !== null
            || app(OpenOrderTableUsage::class)->anyOpenOrderUsesTables([(string) $table->id]);

        if ($hasOpenOrder) {
            abort(response()->json([
                'message' => 'Cannot delete a table that has an open order. Close or move the order first.',
                'code' => 'TABLE_DELETE_BLOCKED_OPEN_ORDER',
            ], 409));
        }

        return $table->delete();
    }

    public function restore(Table $table): Table
    {
        $table->restore();

        return $table->load(['zone:id,code,name', 'lastStatusChange']);
    }

    public function toggleActive(Table $table): Table
    {
        $table->update(['is_active' => ! $table->is_active]);

        return $table->load(['zone:id,code,name', 'lastStatusChange']);
    }

    /**
     * #890 — HQ brand-level switch: may the shop edit HQ-origin tables?
     * Resolves table → branch → brand (console_brand_id join) → policy row.
     * Missing brand or policy row ⇒ false (locked, the safe default).
     */
    private function shopMayEditHqTables(Table $table): bool
    {
        $brandId = $table->branch?->brand?->id;

        if ($brandId === null) {
            return false;
        }

        return (bool) BrandOrderPolicy::query()
            ->where('brand_id', $brandId)
            ->value('allow_shop_edit_hq_tables');
    }

    /**
     * Issue a new opaque qr_token, invalidating the previous one.
     */
    public function regenerateQr(Table $table): Table
    {
        $table->update(['qr_token' => $this->qrTokenGenerator->generate(Table::class)]);

        return $table->fresh(['zone:id,code,name', 'lastStatusChange']);
    }
}
