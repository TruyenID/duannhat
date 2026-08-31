<?php

/**
 * CustomerOrderItem Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\CustomerOrderItem\Models\CustomerOrderItemBaseModel;
use App\Traits\AuditsActivity;
use Database\Factories\CustomerOrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * CustomerOrderItem — add project-specific model logic here.
 */
class CustomerOrderItem extends CustomerOrderItemBaseModel
{
    use AuditsActivity;
    use HasFactory;

    /**
     * Item name shown to operators (kitchen ticket, workstation list).
     * Derived from product_sku at read time because customer_order_items
     * has no name snapshot column. Workstation mirrors this into its own
     * order_items.menu_item_name during sync pull.
     */
    protected $appends = ['menu_item_name', 'is_refund'];

    /**
     * Plan-028 — KDS kitchen transition timestamps added via migration.
     * Cast as datetime so Carbon methods work in KdsItemResource.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'started_preparing_at' => 'datetime',
            'ready_at' => 'datetime',
        ]);
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): CustomerOrderItemFactory
    {
        return CustomerOrderItemFactory::new();
    }

    public function getMenuItemNameAttribute(?string $value = null): string
    {
        $sku = $this->productSku;

        // Priority: stored snapshot (if a column is ever added) → SKU's own
        // name → the product's locale-resolved name (vi→ja→en→any, dodging
        // translatable use_fallback=false) → SKU code. The product may be
        // soft-deleted; callers eager-load it withTrashed to keep historical
        // names resolving.
        $candidates = [
            $value,
            $sku?->name,
            $sku?->product?->localizedName(),
            $sku?->sku,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * plan-045 — true when this row is a REFUND line (negative qty/tax copied
     * from the original line it points at via refund_of_item_id). Normal lines
     * have refund_of_item_id === null.
     */
    public function getIsRefundAttribute(): bool
    {
        return $this->refund_of_item_id !== null;
    }

    /**
     * plan-045 — the original line this refund line reverses (null on normal
     * lines). Self-referential; RESTRICT so an original with refunds can't be
     * hard-deleted.
     *
     * @return BelongsTo<CustomerOrderItem, $this>
     */
    public function refundOf(): BelongsTo
    {
        return $this->belongsTo(CustomerOrderItem::class, 'refund_of_item_id');
    }

    /**
     * plan-045 — the refund lines that reverse (part of) this original line.
     *
     * @return HasMany<CustomerOrderItem, $this>
     */
    public function refundChildren(): HasMany
    {
        return $this->hasMany(CustomerOrderItem::class, 'refund_of_item_id');
    }

    /**
     * plan-045 — per-line financial condition ledger rows (e.g. the item-level
     * tax mirror, a promotion discount, or the refund event on a refund line).
     * Polymorphic via the 'order_item' morph alias.
     *
     * @return MorphMany<OrderCondition, $this>
     */
    public function conditions(): MorphMany
    {
        return $this->morphMany(OrderCondition::class, 'conditionable');
    }
}
