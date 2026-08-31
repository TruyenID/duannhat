<?php

namespace App\Services\Customer;

use App\Events\Order\OrderMutated;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Services\Order\Internal\EloquentOrderPersistence;
use App\Services\Order\Internal\OrderMutationContextFactory;
use App\Support\BusinessClock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Customer order query + legacy array transport delegating mutations to EloquentOrderPersistence. */
class CustomerOrderService
{
    public function __construct(
        private readonly EloquentOrderPersistence $persistence,
    ) {}

    /**
     * @param  array{organization_id?: string, branch_id?: string, brand_id?: string, status?: string, customer_id?: string, table_id?: string, date_from?: string, date_to?: string, search?: string, order_type?: string, sort?: string, per_page?: int}  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = CustomerOrder::query()->with(['branch', 'customer:id,first_name,last_name,phone', 'tables:id,code,name,status', 'conditions']);

        if (! empty($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }

        $query->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id));
        $query->when($filters['brand_id'] ?? null, fn ($q, $id) => $q->where('brand_id', $id));
        // Status accepts a single value ("open") OR a comma-separated list
        // ("open,dining,checkout,paying") — POS tab-restore uses the latter
        // to fetch every staff-visible lifecycle in one request.
        $query->when($filters['status'] ?? null, function ($q, $s) {
            $statuses = array_values(array_filter(array_map('trim', explode(',', (string) $s))));
            if (count($statuses) === 1) {
                $q->where('status', $statuses[0]);
            } elseif (count($statuses) > 1) {
                $q->whereIn('status', $statuses);
            }
        });
        $query->when($filters['customer_id'] ?? null, fn ($q, $id) => $q->where('customer_id', $id));
        // #1091 — filter dates are BRANCH-local; convert to UTC instant
        // bounds instead of whereDate (which compares in the DB's UTC day).
        [$dateFrom, $dateUntil] = BusinessClock::utcRangeForBusinessDates(
            $filters['branch_id'] ?? null,
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null,
        );
        $query->when($dateFrom, fn ($q) => $q->where('created_at', '>=', $dateFrom));
        $query->when($dateUntil, fn ($q) => $q->where('created_at', '<', $dateUntil));
        $query->when($filters['order_type'] ?? null, fn ($q, $t) => $q->where('order_type', $t));

        // Filter by table — find orders whose tables include this table_id
        $query->when($filters['table_id'] ?? null, function ($q, $tableId) {
            $q->whereHas('tables', fn ($tq) => $tq->where('tables.id', $tableId));
        });

        $query->when($filters['search'] ?? null, function ($q, $s) {
            $q->where(function ($q) use ($s) {
                $q->where('order_code', 'like', "%{$s}%")
                    ->orWhereHas('customer', function ($q) use ($s) {
                        $q->where('first_name', 'like', "%{$s}%")
                            ->orWhere('last_name', 'like', "%{$s}%")
                            ->orWhere('phone', 'like', "%{$s}%");
                    });
            });
        });

        $sort = $filters['sort'] ?? '-created_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $query->orderBy($column, $direction);

        return $query->paginate($filters['per_page'] ?? 25);
    }

    /**
     * Aggregate stats for HQ report.
     *
     * @param  array{organization_id?: string, brand_id?: string, branch_id?: string, status?: string, date_from?: string, date_to?: string}  $filters
     * @return array{total_revenue: int, order_count: int, avg_order_value: int, currencies: list<string>}
     */
    public function aggregate(array $filters = []): array
    {
        $query = CustomerOrder::query()->where('status', CustomerOrderStatusEnum::Closed->value);

        if (! empty($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }

        $query->when($filters['brand_id'] ?? null, fn ($q, $id) => $q->where('brand_id', $id));
        $query->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id));
        // #1091 — filter dates are BRANCH-local; convert to UTC instant
        // bounds instead of whereDate (which compares in the DB's UTC day).
        [$dateFrom, $dateUntil] = BusinessClock::utcRangeForBusinessDates(
            $filters['branch_id'] ?? null,
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null,
        );
        $query->when($dateFrom, fn ($q) => $q->where('created_at', '>=', $dateFrom));
        $query->when($dateUntil, fn ($q) => $q->where('created_at', '<', $dateUntil));

        // Clone BEFORE the aggregate select runs. Eloquent builders are MUTABLE:
        // `$query->selectRaw(...)` rewrites the select clause on `$query` itself,
        // so a clone taken afterwards inherits it and `pluck('branches.currency')`
        // reads a column that is not in the result set — a 500 on a page that
        // renders today. Caught by seven existing order tests, not by mine.
        $currencyQuery = (clone $query)
            ->join('branches', 'branches.id', '=', 'customer_orders.branch_id');

        $result = $query->selectRaw('COALESCE(SUM(total_amount), 0) as total_revenue, COUNT(*) as order_count')->first();

        $totalRevenue = (int) $result->total_revenue;
        $orderCount = (int) $result->order_count;

        // #1961 — say WHICH currencies this sum is made of.
        //
        // `SUM(total_amount)` adds every matching order regardless of currency,
        // so on a brand that runs both a Hanoi and a Tokyo shop the default
        // "all branches" view produces VND + JPY added together. The number was
        // already meaningless; the reported symptom (the wrong symbol) was just
        // the visible half. Handing the client a currency code to print would
        // have made a nonsense figure look authoritative — the opposite of a fix.
        //
        // The client cannot work this out for itself: it holds ONE PAGE of
        // orders while the aggregate spans the whole filtered set, so a page of
        // VND rows says nothing about the JPY rows on page 4. Hence it is
        // answered here, where the same filters are already applied.
        //
        // A separate query rather than a GROUP BY: the caller needs one scalar
        // pair plus this list, and reshaping the existing aggregate into
        // per-currency rows would change a response every existing client reads.
        //
        // Read from `branches.currency`, NOT from the order. An order carries no
        // currency column at all — `CustomerOrderResource` derives the field the
        // client sees from `$this->branch?->currency` (#431). My first draft
        // selected `customer_orders.currency_code`, which does not exist; SQLite
        // and MySQL would both have thrown on a page that currently renders.
        $currencies = $currencyQuery
            ->distinct()
            ->orderBy('branches.currency')
            ->pluck('branches.currency')
            ->filter(fn ($c) => is_string($c) && $c !== '')
            ->values()
            ->all();

        return [
            'total_revenue' => $totalRevenue,
            'order_count' => $orderCount,
            'avg_order_value' => $orderCount > 0 ? (int) round($totalRevenue / $orderCount) : 0,
            // Empty when nothing matched; one entry is the ordinary case; more
            // than one means the two money figures above must NOT be shown as a
            // single amount.
            'currencies' => $currencies,
        ];
    }

    public function findById(string $id): CustomerOrder
    {
        return CustomerOrder::with([
            'branch',
            'customer',
            'conditions', // plan-045 — order-level condition ledger for the resource
            'items.productSku.product',
            // POS cart line thumbnail — eager-load the SKU's first gallery
            // image so ProductSkuResource can surface `image_url` without
            // a separate N+1 fetch when the cart re-renders. The `galleryFirst`
            // morphOne is scoped + ordered server-side so only 1 row joins
            // per SKU.
            'items.productSku.galleryFirst',
            // Selected variant options (size / sugar level / etc). The SKU
            // already encodes the variant; eager-loading each option value +
            // its parent option lets the admin order detail render a
            // "Option group: value" breakdown without an N+1. ProductSkuResource
            // surfaces these as `option_value1/2/3` (with nested `option`).
            'items.productSku.optionValue1.option',
            'items.productSku.optionValue2.option',
            'items.productSku.optionValue3.option',
            // Plan 015 — surface chosen toppings on every line.
            'items.orderItemToppings.toppingGroupItem.product',
            'items.orderItemToppings.toppingGroupItem.toppingGroup',
            'payments.paymentMethod',
            'tables',
        ])->findOrFail($id);
    }

    // =========================================================================
    //  Create (Open order)
    // =========================================================================

    public function create(array $data): CustomerOrder
    {
        return $this->emitTransportMutation('create', null, $this->persistence->transportCreate($data));
    }

    public function confirmOrder(CustomerOrder $order): CustomerOrder
    {
        return $this->emitTransportMutation('confirmOrder', $order, $this->persistence->confirmOrder($order));
    }

    public function initOrder(CustomerOrder $order, array $data): CustomerOrder
    {
        return $this->emitTransportMutation('initOrder', $order, $this->persistence->initOrder($order, $data));
    }

    public function update(CustomerOrder $order, array $data): CustomerOrder
    {
        return $this->emitTransportMutation('update', $order, $this->persistence->update($order, $data));
    }

    public function delete(CustomerOrder $order): void
    {
        $this->emitTransportMutation('delete', $order, $this->persistence->delete($order));
    }

    public function continueTableOrder(string $branchId, array $data): CustomerOrder
    {
        return $this->emitTransportMutation('continueTableOrder', null, $this->persistence->continueTableOrder($branchId, $data));
    }

    public function checkout(CustomerOrder $order, array $data): CustomerOrder
    {
        return $this->emitTransportMutation('checkout', $order, $this->persistence->checkout($order, $data));
    }

    public function voidOrder(CustomerOrder $order, array $data): CustomerOrder
    {
        return $this->emitTransportMutation('voidOrder', $order, $this->persistence->voidOrder($order, $data));
    }

    public function cancel(CustomerOrder $order, ?string $reason = null): CustomerOrder
    {
        return $this->emitTransportMutation('cancel', $order, $this->persistence->cancel($order, $reason));
    }

    public function expireOverdueTakeaway(CustomerOrder $order): CustomerOrder
    {
        return $this->emitTransportMutation('expireOverdueTakeaway', $order, $this->persistence->expireOverdueTakeaway($order));
    }

    public function addItems(CustomerOrder $order, array $data): array
    {
        return $this->emitTransportMutation('addItems', $order, $this->persistence->addItems($order, $data));
    }

    public function updateItem(CustomerOrder $order, string $itemId, array $data): CustomerOrderItem
    {
        return $this->emitTransportMutation('updateItem', $order, $this->persistence->updateItem($order, $itemId, $data));
    }

    public function voidItem(CustomerOrder $order, string $itemId, array $data): CustomerOrderItem
    {
        return $this->emitTransportMutation('voidItem', $order, $this->persistence->voidItem($order, $itemId, $data));
    }

    public function removeItem(CustomerOrder $order, string $itemId): void
    {
        $this->emitTransportMutation('removeItem', $order, $this->persistence->transportRemoveItem($order, $itemId));
    }

    public function refundItem(CustomerOrder $order, string $itemId, float $quantity, ?string $reason = null, ?string $refundLineId = null): CustomerOrder
    {
        return $this->emitTransportMutation('refundItem', $order, $this->persistence->refundItem($order, $itemId, $quantity, $reason, $refundLineId));
    }

    public function mergeTable(CustomerOrder $order, string $tableId): CustomerOrder
    {
        return $this->emitTransportMutation('mergeTable', $order, $this->persistence->mergeTable($order, $tableId));
    }

    public function unmergeTable(CustomerOrder $order, string $tableId): CustomerOrder
    {
        return $this->emitTransportMutation('unmergeTable', $order, $this->persistence->transportUnmergeTable($order, $tableId));
    }

    public function splitBill(CustomerOrder $order, ?int $splitCount = null): array
    {
        return $this->emitTransportMutation('splitBill', $order, $this->persistence->splitBill($order, $splitCount));
    }

    public function splitByItemsPreview(CustomerOrder $order, ?array $candidateAllocations = null): array
    {
        return $this->persistence->splitByItemsPreview($order, $candidateAllocations);
    }

    public function refreshOrderTotals(CustomerOrder $order): void
    {
        $this->emitTransportMutation('refreshOrderTotals', $order, $this->persistence->refreshOrderTotals($order));
    }

    public function commitAwaitingConfirmation(CustomerOrder $order): CustomerOrder
    {
        return $this->emitTransportMutation('commitAwaitingConfirmation', $order, $this->persistence->commitAwaitingConfirmation($order));
    }

    public function voidAwaitingConfirmation(CustomerOrder $order, string $reason): CustomerOrder
    {
        return $this->emitTransportMutation('voidAwaitingConfirmation', $order, $this->persistence->voidAwaitingConfirmation($order, $reason));
    }

    public function assignTableSession(CustomerOrder $order, string $sessionId): CustomerOrder
    {
        return $this->emitTransportMutation('assignTableSession', $order, $this->persistence->assignTableSession($order, $sessionId));
    }

    public function setSplitModeFields(CustomerOrder $order, string $splitMode, ?int $splitPeopleCount): CustomerOrder
    {
        return $this->emitTransportMutation('setSplitModeFields', $order, $this->persistence->setSplitModeFields($order, $splitMode, $splitPeopleCount));
    }

    public function stampKitchenTimestampIfNull(CustomerOrderItem $item, string $column): CustomerOrderItem
    {
        return $this->emitTransportMutation('stampKitchenTimestampIfNull', null, $this->persistence->stampKitchenTimestampIfNull($item, $column));
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, float>  $authoritativePrices
     */
    public function syncWorkstationItems(CustomerOrder $order, array $items, array $authoritativePrices): CustomerOrder
    {
        return $this->emitTransportMutation('syncWorkstationItems', $order, $this->persistence->transportWorkstationSyncItems($order, $items, $authoritativePrices));
    }

    public function ghostCreateWorkstationItem(CustomerOrder $order, string $itemId, array $snap): CustomerOrderItem
    {
        return $this->emitTransportMutation('ghostCreateWorkstationItem', $order, $this->persistence->transportWorkstationGhostItem($order, $itemId, $snap));
    }

    public function transportWorkstationPatchOrder(CustomerOrder $order, array $patch): CustomerOrder
    {
        return $this->emitTransportMutation('transportWorkstationPatchOrder', $order, $this->persistence->transportWorkstationPatchOrder($order, $patch));
    }

    public function transportWorkstationSoftDelete(CustomerOrder $order): void
    {
        $this->emitTransportMutation('transportWorkstationSoftDelete', $order, $this->persistence->transportWorkstationSoftDelete($order));
    }

    public function transportWorkstationVoid(CustomerOrder $order, string $reason): CustomerOrder
    {
        return $this->emitTransportMutation('transportWorkstationVoid', $order, $this->persistence->transportWorkstationVoid($order, $reason));
    }

    public function transportWorkstationCheckout(CustomerOrder $order, array $data): CustomerOrder
    {
        return $this->emitTransportMutation('transportWorkstationCheckout', $order, $this->persistence->transportWorkstationCheckout($order, $data));
    }

    public function transportWorkstationPatchItem(CustomerOrderItem $item, array $patch): void
    {
        $this->emitTransportMutation('transportWorkstationPatchItem', null, $this->persistence->transportWorkstationPatchItem($item, $patch));
    }

    public function transportWorkstationSoftDeleteItem(CustomerOrderItem $item): void
    {
        $this->emitTransportMutation('transportWorkstationSoftDeleteItem', null, $this->persistence->transportWorkstationSoftDeleteItem($item));
    }

    public function transportWorkstationVoidItem(CustomerOrderItem $item, string $reason): void
    {
        $this->emitTransportMutation('transportWorkstationVoidItem', null, $this->persistence->transportWorkstationVoidItem($item, $reason));
    }

    public function transportWorkstationApplyCoupon(CustomerOrder $order, string $couponId, string $couponCode, float $discount): void
    {
        $this->emitTransportMutation('transportWorkstationApplyCoupon', $order, $this->persistence->transportWorkstationApplyCoupon($order, $couponId, $couponCode, $discount));
    }

    public function transportWorkstationReleaseCoupon(CustomerOrder $order): void
    {
        $this->emitTransportMutation('transportWorkstationReleaseCoupon', $order, $this->persistence->transportWorkstationReleaseCoupon($order));
    }

    /**
     * Announce a mutation taken through the ARRAY transport (this facade).
     *
     * `transport` names the SHAPE of the call — arrays instead of typed
     * Commands — not a compatibility branch for old data (#2188). It is the
     * live door for kiosk and customer-web.
     *
     * There are two order facades during the plan-047 migration and they are
     * disjoint: this one and OrderService both wrap EloquentOrderPersistence
     * directly, neither calls the other. Emitting only from the typed facade
     * would therefore have left every kiosk and customer-web order invisible to
     * listeners — a hook that silently covers half the traffic is worse than no
     * hook, because nobody can tell which half they got.
     *
     * The context is SYNTHESISED (these methods take arrays, not commands), so
     * `actorId` is whoever created the order rather than whoever acted, and the
     * correlation id is minted here instead of carried from the request. A
     * listener that needs true request attribution should read it from the
     * typed path; this is the honest best available for the array one, and it
     * disappears as callers migrate.
     */
    private function emitTransportMutation(string $command, ?CustomerOrder $order, mixed $result = null): mixed
    {
        $subject = $order ?? ($result instanceof CustomerOrder ? $result : null);

        $context = $subject !== null
            ? OrderMutationContextFactory::fromOrder($subject)
            : OrderMutationContextFactory::system();

        $orderId = $subject?->id;
        if ($orderId === null && $result instanceof CustomerOrder) {
            $orderId = $result->id;
        }

        OrderMutated::dispatch($command, $orderId === null ? null : (string) $orderId, $context, $result);

        return $result;
    }
}
