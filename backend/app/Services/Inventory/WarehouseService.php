<?php

namespace App\Services\Inventory;

use App\Models\StockTransaction;
use App\Models\Warehouse;
use App\Models\WarehouseMember;
use App\Traits\GeneratesSku;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class WarehouseService
{
    use GeneratesSku;

    protected string $skuPrefix = 'WH';

    protected string $skuModel = Warehouse::class;

    // =========================================================================
    //  CRUD
    // =========================================================================

    /**
     * @param  array{organization_id?: string, search?: string, type?: string, branch_id?: string, is_active?: bool, with_trashed?: bool, sort?: string, per_page?: int}  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Warehouse::query()
            ->withCount('members');

        if (! empty($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }

        $query->when($filters['search'] ?? null, function ($q, $search) {
            $q->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        });

        $query->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', $filters['is_active']));
        $query->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type));
        $query->when($filters['branch_id'] ?? null, fn ($q, $branchId) => $q->where('branch_id', $branchId));

        if (! empty($filters['with_trashed'])) {
            $query->withTrashed();
        }

        $sort = $filters['sort'] ?? '-created_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $query->orderBy($column, $direction);

        return $query->paginate($filters['per_page'] ?? 25);
    }

    public function findById(string $id): Warehouse
    {
        return Warehouse::withCount('members')->findOrFail($id);
    }

    public function create(array $data): Warehouse
    {
        if (empty($data['code'])) {
            $data['code'] = $this->generateUniqueSku(
                column: 'code',
                additionalWhere: ['organization_id' => $data['organization_id']]
            );
        }

        $data['is_active'] = $data['is_active'] ?? true;
        $data['auto_approve_stock_in'] = $data['auto_approve_stock_in'] ?? false;
        $data['auto_approve_stock_out'] = $data['auto_approve_stock_out'] ?? false;
        $data['auto_approve_batch'] = $data['auto_approve_batch'] ?? false;
        $data['auto_approve_disposal'] = $data['auto_approve_disposal'] ?? false;

        return Warehouse::create($data)->loadCount('members');
    }

    public function update(Warehouse $warehouse, array $data): Warehouse
    {
        unset($data['code']);

        $warehouse->update($data);

        return $warehouse->loadCount('members');
    }

    public function delete(Warehouse $warehouse): bool
    {
        if ($this->hasPendingTransactions($warehouse)) {
            throw new \InvalidArgumentException(
                'Cannot delete warehouse that has pending transactions. Complete or cancel all pending transactions first.'
            );
        }

        $warehouse->members()->delete();

        return $warehouse->delete();
    }

    public function restore(Warehouse $warehouse): Warehouse
    {
        $warehouse->restore();

        return $warehouse->loadCount('members');
    }

    public function toggleActive(Warehouse $warehouse): Warehouse
    {
        if ($warehouse->is_active && $this->hasPendingTransactions($warehouse)) {
            throw new \InvalidArgumentException(
                'Cannot deactivate warehouse that has pending transactions. Complete or cancel all pending transactions first.'
            );
        }

        $warehouse->update(['is_active' => ! $warehouse->is_active]);

        return $warehouse->loadCount('members');
    }

    /**
     * @param  array{auto_approve_stock_in?: bool, auto_approve_stock_out?: bool, auto_approve_batch?: bool, auto_approve_disposal?: bool, disposal_approval_threshold?: float|null, allow_negative_sales?: bool}  $data
     */
    public function updateSettings(Warehouse $warehouse, array $data): Warehouse
    {
        $allowed = [
            'auto_approve_stock_in',
            'auto_approve_stock_out',
            'auto_approve_batch',
            'auto_approve_disposal',
            'disposal_approval_threshold',
            // Plan-024 — sales-flow allow-negative policy.
            'allow_negative_sales',
        ];

        $warehouse->update(array_intersect_key($data, array_flip($allowed)));

        return $warehouse->loadCount('members');
    }

    /**
     * @return array<int, array{id: string, code: string, name: string, type: string}>
     */
    public function lookup(string $organizationId, ?string $branchId = null): array
    {
        return Warehouse::where('organization_id', $organizationId)
            ->where('is_active', true)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->select(['id', 'code', 'name', 'type'])
            ->orderBy('name')
            ->get()
            ->toArray();
    }

    // =========================================================================
    //  Members
    // =========================================================================

    /**
     * @return Collection<int, WarehouseMember>
     */
    public function listMembers(Warehouse $warehouse): Collection
    {
        return $warehouse->members()->with('user')->get();
    }

    public function addMember(Warehouse $warehouse, string $userId, string $role): WarehouseMember
    {
        $exists = $warehouse->members()->where('user_id', $userId)->exists();

        if ($exists) {
            throw new \InvalidArgumentException('User is already a member of this warehouse.');
        }

        return $warehouse->members()->create([
            'user_id' => $userId,
            'role' => $role,
        ]);
    }

    public function updateMemberRole(WarehouseMember $member, string $role): WarehouseMember
    {
        $member->update(['role' => $role]);

        return $member;
    }

    public function removeMember(WarehouseMember $member): bool
    {
        return $member->delete();
    }

    // =========================================================================
    //  Helpers
    // =========================================================================

    protected function hasPendingTransactions(Warehouse $warehouse): bool
    {
        return StockTransaction::where('warehouse_id', $warehouse->id)
            ->whereIn('status', ['draft', 'pending'])
            ->exists();
    }
}
