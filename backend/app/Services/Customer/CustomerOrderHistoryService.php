<?php

namespace App\Services\Customer;

use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Support\BusinessClock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CustomerOrderHistoryService
{
    private const PAGE_SIZE = 20;

    /**
     * @param  array{status?: string, branch_slug?: string, from?: string, to?: string, cursor?: string, payment_status?: string}  $filters
     * @return array{data: Collection, meta: array{has_more: bool, next_cursor: ?string}}
     */
    public function list(Customer $customer, array $filters = []): array
    {
        $query = CustomerOrder::query()
            ->where('customer_id', $customer->id)
            // The card on /account/orders renders the first item's photo and the
            // item names, exactly like the guest list — so this needs the same
            // eager-load chain batchShow() already uses. With bare `items`,
            // CustomerOrderSummaryResource::resolveItemImageUrl() sees no loaded
            // gallery/thumbnail relation and returns null for every order, while
            // the item name lazy-loads one query per line.
            ->with([
                'branch:id,name,slug,currency',
                'items.productSku.product.galleryFirst',
                'items.productSku.product.thumbnail',
                'payments',
            ])
            // #1758 — `is_reviewed` trên card. Hai subquery EXISTS cho cả trang
            // 20 đơn; nếu để resource tự hỏi thì là 40 câu truy vấn.
            ->withExists(['productReviews', 'branchReviews'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // plan-031: filter by payment_due_at (pending = has payment_due_at, paid = fully paid)
        if (! empty($filters['payment_status'])) {
            if ($filters['payment_status'] === 'pending') {
                $query->whereNotNull('payment_due_at');
            } elseif ($filters['payment_status'] === 'paid') {
                $query->whereRaw('paid_amount >= total_amount');
            }
        }

        if (! empty($filters['branch_slug'])) {
            $query->whereHas('branch', fn ($q) => $q->where('slug', $filters['branch_slug']));
        }

        // #1091 — history date filters bind to the branch's business day.
        // With no branch_slug (cross-branch history) the configured default
        // branch timezone applies — a single query cannot honour a
        // different timezone per row.
        $filterBranchId = ! empty($filters['branch_slug'])
            ? DB::table('branches')->where('slug', $filters['branch_slug'])->value('id')
            : null;
        [$dateFrom, $dateUntil] = BusinessClock::utcRangeForBusinessDates(
            $filterBranchId === null ? null : (string) $filterBranchId,
            $filters['from'] ?? null,
            $filters['to'] ?? null,
        );
        if ($dateFrom !== null) {
            $query->where('created_at', '>=', $dateFrom);
        }
        if ($dateUntil !== null) {
            $query->where('created_at', '<', $dateUntil);
        }

        if (! empty($filters['cursor'])) {
            $decoded = json_decode(base64_decode($filters['cursor']), true);
            if ($decoded && isset($decoded['created_at'], $decoded['id'])) {
                $query->where(function ($q) use ($decoded) {
                    $q->where('created_at', '<', $decoded['created_at'])
                        ->orWhere(function ($q2) use ($decoded) {
                            $q2->where('created_at', '=', $decoded['created_at'])
                                ->where('id', '<', $decoded['id']);
                        });
                });
            }
        }

        $items = $query->limit(self::PAGE_SIZE + 1)->get();
        $hasMore = $items->count() > self::PAGE_SIZE;

        if ($hasMore) {
            $items = $items->take(self::PAGE_SIZE);
        }

        $nextCursor = null;
        if ($hasMore && $items->isNotEmpty()) {
            $last = $items->last();
            $nextCursor = base64_encode(json_encode([
                'created_at' => $last->created_at->toIso8601String(),
                'id' => $last->id,
            ]));
        }

        return [
            'data' => $items,
            'meta' => [
                'has_more' => $hasMore,
                'next_cursor' => $nextCursor,
            ],
        ];
    }

    public function detail(Customer $customer, string $orderId): CustomerOrder
    {
        return CustomerOrder::query()
            ->where('customer_id', $customer->id)
            ->where('id', $orderId)
            // Same chain the guest detail path loads (see
            // CustomerOrderController::formatItem): the card renders the item
            // photo and its chosen toppings, and both read through relations
            // that must be loaded — an unloaded `galleryFirst` resolves to null
            // rather than to the photo, and an unloaded `orderItemToppings`
            // serializes as an empty option list rather than the toppings the
            // customer actually paid for.
            ->with([
                'branch:id,name,slug,currency',
                'items.productSku.product.galleryFirst',
                'items.productSku.product.thumbnail',
                'items.productSku.galleryFirst',
                'items.orderItemToppings.productSku.product',
                'items.orderItemToppings.toppingGroupItem.product',
                'payments.paymentMethod',
                'tables',
            ])
            ->firstOrFail();
    }
}
