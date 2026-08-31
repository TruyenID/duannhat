<?php

/**
 * CustomerOrder Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Modules\CustomerOrder\Models\CustomerOrderBaseModel;
use App\Services\Order\Contracts\OrderPaymentLedgerReads;
use App\Services\Order\Contracts\OrderStatusVocabulary;
use App\Services\Order\Internal\EloquentOrderPersistence;
use App\Support\QrTokenGenerator;
use App\Traits\AuditsActivity;
use Database\Factories\CustomerOrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * CustomerOrder — add project-specific model logic here.
 */
class CustomerOrder extends CustomerOrderBaseModel
{
    use AuditsActivity;
    use HasFactory;

    /**
     * Statuses that count as "open" for referential delete-guards (plan-042):
     * an entity (table/customer/sku) referenced by an order in one of these
     * states cannot be deleted. Terminal states (closed, voided) do not block.
     *
     * @var list<string>
     */
    public const OPEN_STATUSES = OrderStatusVocabulary::OPEN;

    /** @var array<string, float> */
    private array $pendingConditionAmounts = [];

    /**
     * Scope to orders in an open (non-terminal) status. Used by the plan-042
     * delete-guards to detect whether an entity is still referenced by a live
     * order.
     */
    public function scopeOpen($query)
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    /**
     * Normalize a client-supplied pickup instant to the app timezone on write.
     *
     * Eloquent formats a Carbon using that Carbon's OWN timezone, but reads the
     * column back in `config('app.timezone')`. So a UTC instant from a client
     * (`…Z` — what customer-web's `date.toISOString()` sends) is written as a
     * UTC wall-clock and re-read as JST: the value round-trips 9h early and the
     * pickup lands BEFORE the order was placed. Converting to the app timezone
     * here keeps write and read in the same frame for every path — the takeaway
     * service, generic Omnify mass-assignment updates, factories and seeders
     * alike — instead of relying on each call site to remember.
     *
     * Idempotent: a value already in the app timezone is unchanged, and a naive
     * string is parsed in the app timezone (Carbon's default) so it stays put.
     */
    protected function scheduledPickupTime(): Attribute
    {
        return Attribute::set(
            fn ($value) => $value === null || $value === ''
                ? null
                : Carbon::parse($value)
                    ->setTimezone(config('app.timezone', 'UTC'))
                    ->format('Y-m-d H:i:s'),
        );
    }

    protected $fillable = [
        'order_code',
        // plan-041 — workstation's local order UUID; durable idempotency key
        // so a LAN sync-up replay maps to the same cloud order.
        'client_order_id',
        'order_type',
        'status',
        'subtotal',
        // #2041 — computed/write-through properties. Their Attribute setters
        // translate fixture/import writes into order_conditions; no header
        // columns exist for them anymore.
        'discount_amount',
        // #1124 — the manually-entered checkout intent + its mandatory reason.
        // The effective applied amount is now projected from order_conditions.
        'manual_discount_amount',
        'manual_discount_reason',
        'service_charge',
        'tax_amount',
        'total_amount',
        'paid_amount',
        'total_tip',
        // plan-043 BUG-1 — 総額表示 mode snapshot, stamped at creation from the
        // branch's prices_include_tax (CustomerOrderService::insertOrder). MUST be
        // listed here because this model OVERRIDES the base $fillable (which has
        // it) — the override silently shadows the base, so without this line the
        // create() drops the value and included-mode never activates (BUG-1).
        'is_tax_included',
        // plan-045 — tax-rounding snapshot (mode + decimals), stamped alongside
        // is_tax_included in CustomerOrderService::insertOrder. Same shadow trap:
        // absent from this override, CustomerOrder::create() drops them and every
        // order silently falls back to the DB default (round/0) no matter what the
        // shop configured — so the rounding rule never reaches pos-web/workstation.
        'tax_rounding_mode',
        'tax_rounding_decimals',
        'stripe_payment_intent_id',
        'opened_at',
        'checkout_at',
        'closed_at',
        'voided_at',
        'void_reason',
        'guest_count',
        'note',
        'stock_out_transaction_id',
        'created_by_id',
        // 'customer_account_id' and 'cancellation_reason' were removed (#1264):
        // both columns are gone from customer_orders, and $fillable is the list a
        // developer consults to learn whether a field exists. Writing to the
        // second one is what fataled CancelOverdueTakeawayOrders on every run and
        // left 16 overdue orders unswept (#512).
        'customer_id',
        'customer_takeaway_name',
        'customer_takeaway_phone',
        // plan-035 — takeaway email used by OrderPlacedMail + OrderPaidInvoiceMail.
        'customer_takeaway_email',
        // #365 — locale snapshot (vi/en/ja) at order create. Mailables
        // honour it so OrderPaidInvoiceMail dispatched from a webhook
        // (no Accept-Language) still renders in the customer's chosen
        // language.
        'customer_locale',
        'branch_id',
        'brand_id',
        'organization_id',
        // Pickup time fields
        'pickup_type',
        'scheduled_pickup_time',
        // plan-031 — takeaway payment countdown
        'payment_due_at',
        // plan-037 — takeaway counter-pay confirmation step countdown.
        'confirmation_due_at',
        // #377 — split-bill mode picked by customer-web (or POS), so the
        // kiosk can skip the chooser and jump straight to allocation.
        'split_mode',
        // plan-039 follow-up — headcount for "Chia đều" pre-declared on
        // customer-web counter-pay flow; lets the kiosk show ¥X/người
        // directly without asking the cashier to type it again.
        'split_people_count',
        'auto_cancelled_at',
        'estimated_ready_time',
        'actual_ready_time',
        'preparation_minutes',
        // Plan-019 — coupon FK + frozen code snapshot. Must appear here
        // because this model OVERRIDES the base fillable (the base has
        // these too, but the override silently shadows it).
        'coupon_id',
        'coupon_code_snapshot',
        // plan-034 — shared dine-in session FK + POS soft-lock timestamp.
        'table_session_id',
        'editing_by_staff_at',
        // plan-044 — order↔shift attribution FK. Must be listed here because
        // this model OVERRIDES the base fillable; stamped by
        // CustomerOrderService::insertOrder and re-stamped at shift open.
        'till_session_id',
    ];

    /**
     * Hidden from serialization. qr_token is an unguessable handle the kiosk
     * resolves via /customer/qr/{token}; it must never leak through any order
     * payload. Deliberately NOT in $fillable either, so a request body can't
     * smuggle a client-chosen (predictable) token past the creating hook.
     *
     * @var list<string>
     */
    protected $hidden = [
        'qr_token',
    ];

    /**
     * Mint an opaque QR token on create so every order is reachable by a
     * single unguessable scan, in the same base62 namespace as table tokens.
     * Runs unconditionally in practice: qr_token is not fillable, so it is
     * always empty here unless a backfill/test set it explicitly.
     */
    protected static function booted(): void
    {
        static::creating(function (CustomerOrder $order): void {
            if (empty($order->qr_token)) {
                $order->qr_token = app(QrTokenGenerator::class)->generate(self::class, 'qr_token');
            }
        });

        static::saved(function (CustomerOrder $order): void {
            $order->flushPendingConditionAmounts();
        });

        // Force-delete issues a raw DELETE. Its FK cascade cannot clean up this
        // order's product_reviews: the customer_order_item_id FK is RESTRICT, so
        // the item rows can't go while a review points at them, and even where a
        // cascade did fire it would bypass the ProductReview::deleting event that
        // reverses the product's review_* aggregates — leaving the counts drifted
        // above zero forever. Delete the reviews through Eloquent first: the event
        // rolls the aggregates back, and clearing the rows releases the RESTRICT.
        //
        // The delete itself is owned by the canonical order write boundary
        // (plan-047 T4.14) — this hook only delegates, so force-deletes that
        // bypass EloquentOrderPersistence::forceDeleteOrder() stay safe.
        static::forceDeleting(function (CustomerOrder $order): void {
            app(EloquentOrderPersistence::class)->purgeProductReviews($order);
        });

        // #2866 — lưới an toàn cho rào TIỀN, cùng khuôn với `forceDeleting`.
        //
        // `WritesCustomerOrders::delete()` là biên ghi chuẩn và có đủ rào, nhưng
        // nó chỉ chặn được đường đi QUA nó. Trước đây model không có hook
        // `deleting` nào, nên mọi `$order->delete()` — tinker, service khác,
        // cascade — đi vòng qua sạch. Đo được hậu quả: `ORD-2026-0018` ở trạng
        // thái `closed` vẫn bị xoá mềm, dù rào nói chỉ `Open` mới được xoá, và
        // nó đang giữ ¥297 đã capture thật ở Stripe.
        //
        // Ở đây CỐ Ý chỉ canh vế TIỀN, không lặp lại rào trạng thái và rào món
        // đã phục vụ. Lý do: hai rào kia là luật quy trình của quầy, còn vế tiền
        // là bất biến kế toán — xoá một bản ghi đứng sau khoản đã thu thì không
        // còn gì để đối soát với PSP. Nhân đôi rào quy trình xuống tầng model sẽ
        // làm gãy các đường dọn dữ liệu hợp lệ (đơn nháp, fixture, reseed) mà
        // không bảo vệ thêm được đồng nào.
        // Đi qua CỔNG `OrderPaymentLedgerReads`, không gọi thẳng
        // `OrderPayment::netCollectedForOrder()`. Deptrac chặn đúng chỗ này —
        // "Ordering must not depend on Payments" — và đó là rào có lý: cổng này
        // là nơi DUY NHẤT định nghĩa "tiền đơn còn giữ", nên mọi người hỏi cùng
        // một câu phải đi cùng một cửa. `WritesCustomerOrders` đã hỏi qua đây.
        static::deleting(function (CustomerOrder $order): void {
            $collected = app(OrderPaymentLedgerReads::class)
                ->netCollectedForOrder((string) $order->getKey());

            if ($collected > 0) {
                abort(409, sprintf(
                    'Cannot delete: order is holding %s in payments — void and refund it instead of deleting.',
                    number_format($collected, 2),
                ));
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'scheduled_pickup_time' => 'datetime',
            'estimated_ready_time' => 'datetime',
            'actual_ready_time' => 'datetime',
            'preparation_minutes' => 'integer',
            // plan-031 — takeaway payment countdown
            'payment_due_at' => 'datetime',
            // plan-037 — takeaway counter-pay confirmation countdown
            'confirmation_due_at' => 'datetime',
            'auto_cancelled_at' => 'datetime',
            // plan-034 — POS edit soft-lock timestamp.
            'editing_by_staff_at' => 'datetime',
        ]);
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): CustomerOrderFactory
    {
        return CustomerOrderFactory::new();
    }

    /**
     * Tables currently serving this order (reverse of Table.current_order_id).
     * Multiple tables = merged tables. Empty/wrong once the order closes and
     * the table is released or reassigned — use the inherited `table()`
     * (belongsTo via `table_id`, defined on CustomerOrderBaseModel) for
     * history, since that snapshot survives checkout (#2531).
     */
    public function tables(): HasMany
    {
        return $this->hasMany(Table::class, 'current_order_id');
    }

    /**
     * plan-034 — shared dine-in session this order is pinned to. NULL for
     * legacy orders and all takeaway orders.
     */
    public function tableSession(): BelongsTo
    {
        return $this->belongsTo(TableSession::class);
    }

    /**
     * Scope: orders in an active (non-terminal) status.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            CustomerOrderStatusEnum::Open,
            CustomerOrderStatusEnum::Dining,
            CustomerOrderStatusEnum::Checkout,
            CustomerOrderStatusEnum::Paying,
        ]);
    }

    /**
     * Scope: đơn khách TRẢ CHƯA ĐỦ — `paying` và `paid_amount < total_amount`.
     *
     * #1992 — ĐÂY là định nghĩa duy nhất. Trước đó nó được viết tay ở HAI nơi,
     * bằng hai công nghệ truy vấn khác nhau: `CustomerOutstandingOrderService`
     * (Eloquent, lọc theo khách — banner nhắc nợ ở màn thanh toán) và
     * `DebtController::partPaid()` (`DB::table` thô, lọc theo chi nhánh — bảng
     * tra cứu ở POS). Hai bản chưa lệch về số tiền, nhưng đã lệch về cách lọc
     * đơn xoá mềm và cách sắp xếp, tức đã bắt đầu trôi.
     *
     * ## `checkout` KHÔNG phải nợ — chỗ dễ nhầm nhất
     *
     * Một đơn `checkout` chưa thu đồng nào là **vé đang mở**, không phải khách
     * nợ tiền. Nới tập trạng thái ra là mọi bàn đang ăn dở đều hiện thành người
     * nợ. Luật này từng chỉ sống trong một docblock dài và không có test nào
     * ghim; giờ nó là một dòng code có test.
     *
     * ## Đây KHÔNG phải nợ ghi sổ (`on_account`)
     *
     * Hai nghĩa vụ khác nhau, cố ý không gộp: nợ ghi sổ là quán CHO nợ có chủ ý
     * và thu được theo `settles_payment_id`; đơn trả thiếu là đơn không ai kết
     * thúc. Gộp thành một con số thì không còn phân biệt được "đã cấp X tín
     * dụng" với "X đi mất" — xem `Shop\DebtController`.
     */
    public function scopePartPaid(Builder $query): Builder
    {
        return $query
            ->where('status', CustomerOrderStatusEnum::Paying->value)
            ->whereColumn('paid_amount', '<', 'total_amount');
    }

    /**
     * Computed: remaining amount to be paid.
     */
    public function getRemainingAmountAttribute(): float
    {
        return (float) $this->total_amount - (float) $this->paid_amount;
    }

    /**
     * #2041 — financial components live in order_conditions, not columns on
     * customer_orders. Keep the public model properties stable for resources
     * and domain callers while making every read come from the ledger.
     */
    protected function discountAmount(): Attribute
    {
        return Attribute::make(
            get: fn (): float => abs($this->pendingConditionAmounts['discount'] ?? $this->conditionAmount('discount')),
            // Setter đổ sang `manual_discount_amount` là CÓ CHỦ Ý, và chỉ an toàn
            // vì không đường production nào gán `discount_amount` trên model: đo
            // trên cây này, `->discount_amount =` không xuất hiện ở đâu trong
            // `backend/app`, và checkout ĐỌC `$data['discount_amount']` để soi
            // governance rồi tự ghi `manual_discount_amount` (WritesCustomerOrders
            // ~944) chứ không đẩy qua đây. Coupon recompute đi thẳng vào sổ
            // `order_conditions`, nên nó KHÔNG bao giờ đóng dấu khoản giảm tự động
            // thành khoản nhập tay — đúng ranh giới "ý định vs thực tế" ở
            // WritesCustomerOrders ~3006. Người gọi còn lại là seeder/fixture,
            // nơi con số ấy đúng là ý định nhập tay.
            set: function ($value): array {
                $this->pendingConditionAmounts['discount'] = -abs((float) $value);

                return ['manual_discount_amount' => abs((float) $value)];
            },
        );
    }

    protected function serviceCharge(): Attribute
    {
        return Attribute::make(
            get: fn (): float => $this->pendingConditionAmounts['service_charge'] ?? $this->conditionAmount('service_charge'),
            set: function ($value): array {
                $this->pendingConditionAmounts['service_charge'] = (float) $value;

                return [];
            },
        );
    }

    protected function taxAmount(): Attribute
    {
        return Attribute::make(
            get: fn (): float => $this->pendingConditionAmounts['tax'] ?? $this->conditionAmount('tax'),
            set: function ($value): array {
                $this->pendingConditionAmounts['tax'] = (float) $value;

                return [];
            },
        );
    }

    /**
     * Σ sổ `order_conditions` theo loại.
     *
     * Đơn CHƯA lưu không thể có dòng điều kiện nào — chúng khoá theo
     * `conditionable_id`, mà id ấy chưa tồn tại trong DB. Nên `0` ở đây là câu
     * trả lời ĐÚNG, không phải fallback: không có gì để cộng.
     *
     * Chặn ở đây vì `Attribute` accessor THẮNG raw attribute, nên một model dựng
     * trong bộ nhớ bằng `setRawAttributes()` vẫn rơi vào truy vấn — và
     * `SplitByItemsCalculator` là hàm thuần, cố ý không chạm DB
     * (`SplitByItemsCalculatorTest` / `SplitByItemsRefundLinesTest` ghim điều
     * đó). Thiếu chốt này, đọc một attribute tiền trên model chưa lưu nổ
     * `BindingResolutionException`/`QueryException` thay vì trả 0.
     *
     * Thứ tự hai điều kiện có chủ ý: hỏi `relationLoaded` TRƯỚC. Ai đã
     * `setRelation('conditions', …)` tường minh thì đó là sổ họ muốn dùng, kể cả
     * trên model chưa lưu — đảo lại thì mọi fixture in-memory đều bị ép về 0 và
     * không còn cách nào diễn đạt \"đơn này có giảm giá\".
     */
    private function conditionAmount(string $type): float
    {
        if (! $this->relationLoaded('conditions')) {
            if (! $this->exists) {
                return 0.0;
            }

            $this->setRelation('conditions', $this->conditions()->get());
        }

        return (float) $this->conditions
            ->where('type', $type)
            ->sum(fn (OrderCondition $condition) => (float) $condition->amount);
    }

    /**
     * Translate old fixture/import assignments into the new source of truth.
     * Production pricing writes richer per-rate rows directly and never enters
     * this adapter.
     */
    private function flushPendingConditionAmounts(): void
    {
        if ($this->pendingConditionAmounts === []) {
            return;
        }

        $currency = (string) (ShopOrderSetting::where('branch_id', $this->branch_id)->value('currency_code') ?? 'JPY');
        $labels = [
            'discount' => 'Discount',
            'service_charge' => 'Service charge',
            'tax' => 'Tax',
        ];

        foreach ($this->pendingConditionAmounts as $type => $amount) {
            $this->conditions()->where('type', $type)->delete();
            if (abs($amount) < 0.000001) {
                continue;
            }

            $this->conditions()->create([
                'type' => $type,
                'source' => $type === 'tax' ? 'tax_type' : ($type === 'discount' ? 'manual' : 'service_charge'),
                'label' => $labels[$type],
                'amount' => $amount,
                'currency_code' => $currency,
            ]);
        }

        $this->pendingConditionAmounts = [];
        $this->unsetRelation('conditions');
    }

    /**
     * plan-045 — the order-level financial condition ledger (tax / discount /
     * refund snapshots). Polymorphic via the 'order' morph alias. Tax + discount
     * rows are regenerated on each recompute; refund rows are append-only events.
     *
     * @return MorphMany<OrderCondition, $this>
     */
    public function conditions(): MorphMany
    {
        return $this->morphMany(OrderCondition::class, 'conditionable');
    }
}
