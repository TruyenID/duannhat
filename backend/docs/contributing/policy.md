---
title: Policy Rules
category: contributing
tags: [policy, authorization, role, permission, warehouse-context, org-admin, manager, staff]
summary: Defines mandatory rules for writing authorization policies including organization checks, warehouse member/role checks, and role-based access patterns for org admin, manager, and staff.
related: [authorization, controller, service]
---

# Policy Rules

> This document defines **mandatory rules** for writing authorization policies in dxs-product. All contributors (human and AI) must follow these standards.

## Core Principles

1. **Every endpoint MUST have a corresponding Policy method**
2. **Policies only return `bool`** -- no throwing, no returning responses
3. **Organization check is REQUIRED for every single-resource action**
4. **Warehouse resources require an additional member/role check**

---

## Template -- Standard Policy

```php
namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return (bool) $user->console_organization_id;
    }

    public function view(User $user, Product $product): bool
    {
        return $product->organization_id === $user->console_organization_id;
    }

    public function create(User $user): bool
    {
        return (bool) $user->console_organization_id;
    }

    public function update(User $user, Product $product): bool
    {
        return $product->organization_id === $user->console_organization_id;
    }

    public function delete(User $user, Product $product): bool
    {
        if ($product->organization_id !== $user->console_organization_id) {
            return false;
        }

        // Cannot delete active products
        return $product->status !== 'active';
    }

    public function restore(User $user, Product $product): bool
    {
        return $product->organization_id === $user->console_organization_id;
    }

    // =========================================================================
    //  Workflow
    // =========================================================================

    public function submitForApproval(User $user, Product $product): bool
    {
        return $product->organization_id === $user->console_organization_id;
    }

    public function approve(User $user, Product $product): bool
    {
        if ($product->organization_id !== $user->console_organization_id) {
            return false;
        }

        return $this->isManagerOrAdmin($user);
    }

    public function reject(User $user, Product $product): bool
    {
        if ($product->organization_id !== $user->console_organization_id) {
            return false;
        }

        return $this->isManagerOrAdmin($user);
    }

    public function activate(User $user, Product $product): bool
    {
        return $product->organization_id === $user->console_organization_id;
    }

    public function deactivate(User $user, Product $product): bool
    {
        return $product->organization_id === $user->console_organization_id;
    }

    // =========================================================================
    //  Helpers
    // =========================================================================

    private function isManagerOrAdmin(User $user): bool
    {
        return $user->hasAnyRole(['org-admin', 'org-manager']);
    }
}
```

---

## Template -- Warehouse-Scoped Policy

For resources tied to a warehouse (StockTransaction, StockTransfer, etc.):

```php
namespace App\Policies;

use App\Models\StockTransaction;
use App\Models\User;
use App\Policies\Traits\ChecksWarehouseContext;

class StockTransactionPolicy
{
    use ChecksWarehouseContext;

    public function viewAny(User $user): bool
    {
        return (bool) $user->console_organization_id;
    }

    public function view(User $user, StockTransaction $transaction): bool
    {
        if (! $this->belongsToOrganization($user, $transaction)) {
            return false;
        }

        return $this->isOrgAdmin($user)
            || $this->isMemberOf($user, $transaction->warehouse_id);
    }

    public function create(User $user): bool
    {
        return (bool) $user->console_organization_id;
    }

    public function update(User $user, StockTransaction $transaction): bool
    {
        if (! $this->belongsToOrganization($user, $transaction)) {
            return false;
        }

        // Only draft can be updated
        if ($transaction->status !== 'draft') {
            return false;
        }

        return $this->isOrgAdmin($user)
            || $this->isMemberOf($user, $transaction->warehouse_id);
    }

    public function delete(User $user, StockTransaction $transaction): bool
    {
        if (! $this->belongsToOrganization($user, $transaction)) {
            return false;
        }

        // Only draft or cancelled can be deleted
        if (! in_array($transaction->status, ['draft', 'cancelled'], true)) {
            return false;
        }

        return $this->isOrgAdmin($user)
            || $this->isMemberOf($user, $transaction->warehouse_id);
    }

    public function submit(User $user, StockTransaction $transaction): bool
    {
        if (! $this->belongsToOrganization($user, $transaction)) {
            return false;
        }

        return $this->isOrgAdmin($user)
            || $this->isMemberOf($user, $transaction->warehouse_id);
    }

    public function approve(User $user, StockTransaction $transaction): bool
    {
        if (! $this->belongsToOrganization($user, $transaction)) {
            return false;
        }

        // Only manager or admin can approve
        return $this->isOrgAdmin($user)
            || $this->isManagerOf($user, $transaction->warehouse_id);
    }

    public function cancel(User $user, StockTransaction $transaction): bool
    {
        if (! $this->belongsToOrganization($user, $transaction)) {
            return false;
        }

        return $this->isOrgAdmin($user)
            || $this->isManagerOf($user, $transaction->warehouse_id);
    }
}
```

---

## ChecksWarehouseContext Trait

```php
namespace App\Policies\Traits;

use App\Models\User;
use App\Models\WarehouseMember;
use Illuminate\Database\Eloquent\Model;

trait ChecksWarehouseContext
{
    protected function belongsToOrganization(User $user, Model $model): bool
    {
        return $model->organization_id === $user->console_organization_id;
    }

    protected function isOrgAdmin(User $user): bool
    {
        return $user->hasRole('org-admin');
    }

    protected function isMemberOf(User $user, string $warehouseId): bool
    {
        return WarehouseMember::where('warehouse_id', $warehouseId)
            ->where('user_id', $user->id)
            ->exists();
    }

    protected function isManagerOf(User $user, string $warehouseId): bool
    {
        return WarehouseMember::where('warehouse_id', $warehouseId)
            ->where('user_id', $user->id)
            ->where('role', 'manager')
            ->exists();
    }
}
```

---

## Permission Matrix Reference

### Product Domain (organization-scoped)

| Action | Any User | Manager/Admin |
|--------|---------|--------------|
| viewAny | Yes | Yes |
| view | Yes (same org) | Yes |
| create | Yes | Yes |
| update | Yes (same org) | Yes |
| delete | Yes (not active) | Yes |
| submit | Yes | Yes |
| approve | No | Yes |
| reject | No | Yes |

### Inventory Domain (warehouse-scoped)

| Action | Staff (member) | Manager | Org Admin |
|--------|---------------|---------|-----------|
| viewAny | Yes | Yes | Yes |
| view | Yes (assigned warehouse) | Yes | Yes (all) |
| create | Yes | Yes | Yes |
| update (draft) | Yes | Yes | Yes |
| delete (draft/cancelled) | Yes | Yes | Yes |
| submit | Yes | Yes | Yes |
| approve | No | Yes | Yes |
| cancel | No | Yes | Yes |
| receive (transfer) | No | Yes | Yes |

### Stock Count (special)

| Action | Staff | Manager | Org Admin |
|--------|-------|---------|-----------|
| approve | No | No | Yes only |

### Disposal (threshold-aware)

| Action | Manager | Org Admin |
|--------|---------|-----------|
| approve (at or below threshold) | Yes | Yes |
| approve (above threshold) | No | Yes |

---

## Registration

Register policies in `AuthServiceProvider` or use auto-discovery:

```php
// app/Providers/AuthServiceProvider.php
protected $policies = [
    Product::class => ProductPolicy::class,
    StockTransaction::class => StockTransactionPolicy::class,
    StockTransfer::class => StockTransferPolicy::class,
    StockCount::class => StockCountPolicy::class,
    // ...
];
```
