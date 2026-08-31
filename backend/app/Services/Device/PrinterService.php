<?php

namespace App\Services\Device;

use App\Models\Printer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Physical ESC/POS printer registry, per shop.
 *
 * Cloud is the source of truth for printer CONFIGURATION (which printer holds
 * which role, and at which LAN address). Cloud is NOT on the path of the bytes
 * that reach the printer — the workstation pulls this config down, caches it in
 * its own SQLite, and drives the socket itself. That is deliberate: a Cloud
 * outage must never stop a shop from printing.
 *
 * @see workstation/internal/store/migrations/002_printers.sql
 * @see issue #2210 (plan-cloud-first-workstation đã xoá #2188 — git history)
 */
class PrinterService
{
    /**
     * @param  array{organization_id?: string, branch_id?: string, search?: string, role?: string, connection_type?: string, is_active?: bool, with_trashed?: bool, sort?: string, per_page?: int}  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Printer::query()->with('branch');

        if (! empty($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }

        $query->when($filters['branch_id'] ?? null, fn ($q, $branchId) => $q->where('branch_id', $branchId));
        $query->when($filters['connection_type'] ?? null, fn ($q, $type) => $q->where('connection_type', $type));

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        // `roles` is a JSON array column — match membership, not equality, so
        // filtering by "receipt_printer" also finds a multi-role device that
        // serves kitchen + receipt.
        $query->when(
            $filters['role'] ?? null,
            fn ($q, $role) => $q->whereJsonContains('roles', $role)
        );

        $query->when($filters['search'] ?? null, function ($q, $search) {
            $q->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%");
            });
        });

        if (! empty($filters['with_trashed'])) {
            $query->withTrashed();
        }

        $sort = $filters['sort'] ?? '-created_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $query->orderBy($column, $direction);

        return $query->paginate($filters['per_page'] ?? 25);
    }

    public function findById(string $id): Printer
    {
        return Printer::with('branch')->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Printer
    {
        if (empty($data['branch_id'])) {
            throw ValidationException::withMessages([
                'branch_id' => __('Branch is required.'),
            ]);
        }

        // Guard (branch_id, name) here as well as in the request: the
        // request-level unique rule scopes on the request's branch_id, which is
        // resolved from the shop context only AFTER validation. Without this we
        // get a raw 1062 as a 500 instead of a friendly 422. Same reasoning as
        // DeviceService::create().
        $this->assertNameAvailable($data['branch_id'], $data['name'] ?? null);

        return Printer::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Printer $printer, array $data): Printer
    {
        if (array_key_exists('name', $data)) {
            $this->assertNameAvailable(
                $data['branch_id'] ?? $printer->branch_id,
                $data['name'],
                $printer->id,
            );
        }

        $printer->update($data);

        return $printer->refresh()->load('branch');
    }

    public function delete(Printer $printer): void
    {
        $printer->delete();
    }

    public function restore(Printer $printer): Printer
    {
        $printer->restore();

        return $printer->refresh()->load('branch');
    }

    private function assertNameAvailable(string $branchId, ?string $name, ?string $ignoreId = null): void
    {
        if ($name === null || $name === '') {
            return;
        }

        // withTrashed(): the (branch_id, name) unique index has no soft-delete
        // component, so a trashed printer still reserves its name. Without this
        // the check passes and the insert dies on a raw constraint violation.
        $exists = Printer::withTrashed()
            ->where('branch_id', $branchId)
            ->where('name', $name)
            ->when($ignoreId, fn ($q, $id) => $q->whereKeyNot($id))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => __('A printer with this name already exists in this shop.'),
            ]);
        }
    }
}
