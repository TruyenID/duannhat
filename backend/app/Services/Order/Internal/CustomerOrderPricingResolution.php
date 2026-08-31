<?php

namespace App\Services\Order\Internal;

use App\Exceptions\MenuPromotionException;
use App\Models\CustomerOrder;
use App\Models\ShopOrderSetting;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\CustomerOrderTypeEnum;
use App\Omnify\Enums\MenuPromotionStackingModeEnum;
use App\Omnify\Enums\OrderItemPriceSourceEnum;
use App\Services\Catalog\Contracts\ProductCategoryLookup;
use App\Services\Customer\OrderPricingCalculator;
use App\Services\DomainMutation\VerificationAuthority;
use App\Services\Order\Commands\ChangeOrderItemsCommand;
use App\Services\Order\Commands\CreateOrderCommand;
use App\Services\Order\Contracts\OrderLineCatalogAnchors;
use App\Services\Order\Contracts\OrderLineMenuAnchor;
use App\Services\Order\Contracts\OrderLineSkuAnchor;
use App\Services\Order\Contracts\OrderLineTaxBatch;
use App\Services\Order\Contracts\OrderLineTaxPricing;
use App\Services\Order\Contracts\OrderPricingResolutionPort;
use App\Services\Order\Contracts\OrderToppingSelectionPricing;
use App\Services\Order\ValueObjects\OrderDraftPayload;
use App\Services\Order\ValueObjects\OrderLineEvidence;
use App\Services\Order\ValueObjects\OrderLinePayload;
use App\Services\Order\ValueObjects\OrderLineSelectionPayload;
use App\Services\Order\ValueObjects\OrderPricingEvidence;
use App\Services\Order\ValueObjects\OrderServiceChargePayload;
use App\Services\Order\ValueObjects\OrderToppingPayload;
use App\Services\Order\ValueObjects\TrustedOrderSnapshot;
use App\Services\Promotion\Contracts\FloatingSectionPricing;
use App\Services\Promotion\Contracts\MenuPromotionResolver;
use App\Support\CurrencyMinorUnit;
use App\Support\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Typed pricing resolution for order creation (plan-047 T2.12, issue #1090).
 *
 * "Without changing behavior" is the contract, so this resolver deliberately
 * REUSES the legacy engine's own components instead of re-deriving any money
 * rule: the #514 menu-price scope, FloatingSectionPriceResolver,
 * MenuPromotionService (with the same HALF_UP discount rounding), TaxResolver's
 * inheritance chain, and OrderPricingCalculator's once-per-rate-group rounding
 * plus per-line allocation. Its output is pinned against the legacy oracle
 * (OrderPricingOracleTest) by TypedOrderPricingResolverTest.
 *
 * Toppings price through the SAME ToppingSelectionPricer the legacy addItems
 * path delegates to (mandatory-group autofill, per-group min/max, per-product
 * override prices, free_up_to_n at line level),
 * so the two engines cannot drift on topping money.
 *
 * A create-time coupon is deliberately NOT priced here. The legacy flow applies
 * it AFTER create+addItems, on the persisted order, via CouponService::apply
 * (which owns freshness, counters, branch eligibility, stacking guards, and the
 * total recompute) — inside the same transaction so an invalid code rolls the
 * whole order back. The typed path mirrors that exactly: the snapshot prices the
 * pre-coupon order, the witness verifies it, and insertResolvedOrder hands the
 * carried couponCode to the same CouponService. One coupon engine, no drift.
 */
final class CustomerOrderPricingResolution implements OrderPricingResolutionPort
{
    /**
     * #962 · 7a-7 — cổng thuế thay cho `TaxResolver` (Pricing) đặt thẳng làm
     * default-arg. Vẫn lười khởi tạo để mọi setup DI dựng `new
     * CustomerOrderPricingResolution` không đối số còn chạy.
     */
    private ?OrderLineTaxBatch $resolvedTaxBatch = null;

    public function __construct(
        private readonly OrderPricingCalculator $calculator = new OrderPricingCalculator,
        private readonly ?OrderToppingSelectionPricing $toppingPricer = null,
    ) {}

    /**
     * MỘT lô cho cả vòng đời instance này — đúng vòng đời memo mà default-arg
     * `new TaxResolver` cũ có. `bind` (không `singleton`) nên mỗi lần giải một đơn
     * là một instance, tức một lô, tức một memo tươi.
     */
    private function taxBatch(): OrderLineTaxBatch
    {
        return $this->resolvedTaxBatch ??= app(OrderLineTaxPricing::class)->beginBatch();
    }

    private function toppingPricer(): OrderToppingSelectionPricing
    {
        return $this->toppingPricer ?? app(OrderToppingSelectionPricing::class);
    }

    /**
     * #962 · 7a-8 — neo dòng đơn vào SKU + dòng menu, qua cổng do Ordering khai
     * (Catalog hiện thực). Lười như `toppingPricer()` ở trên, cùng lý do.
     */
    private function catalog(): OrderLineCatalogAnchors
    {
        return app(OrderLineCatalogAnchors::class);
    }

    public function resolveOrder(CreateOrderCommand $command): TrustedOrderSnapshot
    {
        $selection = $command->payload;

        $branchId = $command->branchId;
        $setting = ShopOrderSetting::query()->where('branch_id', $branchId)->first();

        $currency = $setting?->currency_code ?? 'JPY';
        $includeTax = (bool) ($setting?->prices_include_tax ?? false);
        $taxMode = $setting?->tax_rounding_mode ?: 'round';
        $taxDecimals = $setting?->tax_rounding_decimals !== null ? (int) $setting->tax_rounding_decimals : null;
        $step = RoundingMode::step('auto', $currency);
        $taxStep = RoundingMode::taxStep($taxDecimals, $currency);
        $exponent = CurrencyMinorUnit::exponent($currency);
        $toMinor = static fn (float $major): int => (int) round($major * (10 ** $exponent));

        // Resolve every menu line against THIS branch's active menus — the same
        // scope rule as the legacy explicit-menu-line branch. The menu row also
        // anchors tenant identity: brand/organization come from it, never from
        // the client.
        // Anchor every line first: explicit menu line, the #514 fallback for
        // product-anchored lines, or off-menu. Collect the concrete SKUs so the
        // floating-price lookup stays one batch.
        $anchored = [];
        $skuIds = [];
        foreach ($selection->lines as $index => $line) {
            [$menuSku, $sku] = $this->anchorForSelection($line, $index, $branchId);
            $anchored[] = [$line, $menuSku, $sku, $index];
            $skuIds[] = $sku->skuId;
        }

        $floatingPrices = app(FloatingSectionPricing::class)->resolveForSkus(
            $branchId,
            array_values(array_unique($skuIds)),
        );

        /** @var list<array{selection: OrderLineSelectionPayload, menuSku: ?OrderLineMenuAnchor, sku: OrderLineSkuAnchor, unitPrice: float, originalUnitPrice: ?float, priceSource: OrderItemPriceSourceEnum, promotionId: ?string, rate: float, taxTypeId: ?string, lineSubtotal: float}> $resolved */
        $resolved = [];
        $rateSubtotals = [];
        $brandId = null;
        $organizationId = null;

        foreach ($anchored as [$line, $menuSku, $sku, $index]) {
            $entry = $this->resolveLineEntry(
                $line,
                $menuSku,
                $sku,
                $index,
                $branchId,
                $command->context->organizationId,
                $floatingPrices,
                $brandId,
                null,
            );
            $brandId ??= $entry['brandId'];
            $organizationId ??= $entry['organizationId'];

            $rateKey = (string) $entry['rate'];
            $rateSubtotals[$rateKey] = ($rateSubtotals[$rateKey] ?? 0.0) + $entry['lineSubtotal'];
            $resolved[] = $entry;
        }

        $pricing = $this->calculator->priceGroups(
            $rateSubtotals,
            0.0, // create-time coupon deferred; manual discounts never exist at create
            (float) ($setting?->service_charge_rate ?? 0),
            (float) ($setting?->service_charge_tax_rate ?? 0),
            $includeTax,
            $step,
            $taxStep,
            $taxMode,
        );

        // Per-line tax via the exact allocation stampLineTaxAmounts uses, so the
        // evidence equals what the legacy engine would stamp on the rows.
        $lineTaxes = $this->allocateLineTaxes($resolved, $includeTax, $taxStep, $taxMode);

        $lines = [];
        foreach ($resolved as $i => $entry) {
            /** @var OrderLineSelectionPayload $sel */
            $sel = $entry['selection'];
            $menuSku = $entry['menuSku'];
            $sku = $entry['sku'];

            $toppingPayloads = array_map(
                static fn (array $row): OrderToppingPayload => new OrderToppingPayload(
                    $row['topping_group_item_id'],
                    $row['quantity'],
                    $toMinor((float) $row['unit_price']),
                    $row['product_sku_id'],
                    $row['note'] ?? null,
                    $row['waived_quantity'],
                ),
                $entry['toppingRows'],
            );

            $lines[] = new OrderLinePayload(
                itemId: $sel->lineId,
                productId: (string) $sku->productId,
                skuId: $sku->skuId,
                quantity: $sel->quantity,
                unitPriceMinor: $toMinor($entry['unitPrice']),
                toppings: $toppingPayloads,
                evidence: new OrderLineEvidence(
                    menuId: $menuSku?->menuId,
                    menuProductId: $menuSku?->menuProductId,
                    menuProductSkuId: $menuSku?->menuProductSkuId,
                    taxTypeId: $entry['taxTypeId'],
                    originalUnitPriceMinor: $entry['originalUnitPrice'] === null ? null : $toMinor($entry['originalUnitPrice']),
                    taxRateBasisPoints: (int) round($entry['rate'] * 100),
                    taxAmountMinor: $toMinor($lineTaxes[$i]),
                    promotionId: $entry['promotionId'],
                    // The promotion discount is baked INTO unitPriceMinor with
                    // the strikethrough in originalUnitPriceMinor — the legacy
                    // model. promotionDiscountMinor stays 0 because the order's
                    // discountMinor tracks coupons/manual discounts only.
                    promotionDiscountMinor: 0,
                    // Split so persistence can rebuild the per-unit DB columns
                    // exactly: line = qty x unit, topping = qty x per-unit
                    // topping subtotal (already floored at -unit). Their sum is
                    // the rate-group contribution the engine priced.
                    lineSubtotalMinor: $sel->quantity * $toMinor($entry['unitPrice']),
                    toppingSubtotalMinor: $sel->quantity * $toMinor($entry['toppingSubtotal']),
                    priceSource: $entry['priceSource'],
                ),
                note: $sel->note,
            );
        }

        $serviceChargeMinor = $toMinor($pricing->serviceCharge);
        $serviceChargeTaxMinor = $toMinor($pricing->serviceChargeTax);
        $serviceCharge = $serviceChargeMinor === 0 && $serviceChargeTaxMinor === 0
            ? null
            : new OrderServiceChargePayload(
                amountMinor: $serviceChargeMinor,
                taxAmountMinor: $serviceChargeTaxMinor,
                taxRateBasisPoints: (int) round((float) ($setting?->service_charge_tax_rate ?? 0) * 100) ?: null,
            );

        // Same default the legacy insertOrder applies: takeaway starts pending,
        // everything else starts open.
        $initialStatus = $selection->orderType === CustomerOrderTypeEnum::Takeaway
            ? CustomerOrderStatusEnum::Pending
            : CustomerOrderStatusEnum::Open;

        $draft = new OrderDraftPayload(
            lines: $lines,
            note: $selection->note,
            orderType: $selection->orderType,
            pickupType: $selection->pickupType,
            scheduledPickupAt: $selection->scheduledPickupAt,
            contact: $selection->contact,
            customerId: $selection->customerId,
            guestCount: $selection->guestCount,
            tableIds: $selection->tableIds,
            locale: $selection->locale,
            channel: $selection->channel,
            deviceId: $selection->deviceId,
            status: $initialStatus,
            splitMode: $selection->splitMode,
            splitPeopleCount: $selection->splitPeopleCount,
            couponCode: $selection->couponCode,
            pricingEvidence: new OrderPricingEvidence(
                subtotalMinor: $toMinor($pricing->subtotal),
                discountMinor: $toMinor($pricing->discount),
                serviceChargeMinor: $serviceChargeMinor,
                taxMinor: $toMinor($pricing->taxAmount),
                totalMinor: $toMinor($pricing->totalAmount),
                taxIncluded: $includeTax,
                taxRoundingMode: $taxMode,
                taxRoundingDecimals: $taxDecimals,
            ),
            serviceCharge: $serviceCharge,
        );

        return TrustedOrderSnapshot::fromPricingResolver(
            $this,
            VerificationAuthority::forConfiguredAdapter($this, OrderPricingResolutionPort::class, ['order.trusted_snapshot']),
            $command,
            $draft,
            $initialStatus,
            $currency,
            hash('sha256', json_encode([
                'rate_subtotals' => $rateSubtotals,
                'include_tax' => $includeTax,
                'tax_mode' => $taxMode,
                'tax_decimals' => $taxDecimals,
                'currency' => $currency,
                'service_charge_rate' => (float) ($setting?->service_charge_rate ?? 0),
                'service_charge_tax_rate' => (float) ($setting?->service_charge_tax_rate ?? 0),
            ], JSON_THROW_ON_ERROR)),
            CarbonImmutable::now()->format(DATE_ATOM),
        );
    }

    /**
     * Resolve the (menu line, SKU) anchor for one selection line — the legacy
     * addItems contract, verbatim:
     *   - explicit menu_product_sku_id → THAT branch-scoped active menu line,
     *     or a loud stale-menu refusal;
     *   - product-anchored → the #514 rule: this branch's LOWEST active menu
     *     price for the SKU, tie-broken by id (deterministic, and never charges
     *     more than any menu advertised);
     *   - on no menu at all → off-menu: the SKU's own selling price, brand and
     *     tenant anchored on the PRODUCT instead of a menu row.
     *
     * @return array{0: ?OrderLineMenuAnchor, 1: OrderLineSkuAnchor}
     */
    private function anchorForSelection(OrderLineSelectionPayload $line, int|string $index, string $branchId): array
    {
        if ($line->menuProductSkuId !== null) {
            $menuSku = $this->catalog()->activeMenuLine((string) $line->menuProductSkuId, $branchId);

            if ($menuSku === null) {
                throw new InvalidArgumentException(
                    "Order line {$index} references menu SKU {$line->menuProductSkuId}, which is not an active menu line of branch {$branchId}. The customer may be ordering from a stale menu.",
                );
            }

            // Lối cũ đọc `$menuSku->productSku` (quan hệ) và tin nó không null. Nay
            // cổng trả `null` khi SKU đã bị xoá mềm dưới chân dòng menu, và ta từ
            // chối HIỂN NGÔN thay vì để một null lọt xuống `isSellable()` rồi nổ
            // thành 500 không đọc được.
            $sku = $this->catalog()->sku($menuSku->productSkuId);
            if ($sku === null) {
                throw new InvalidArgumentException(
                    "Order line {$index} references menu SKU {$line->menuProductSkuId}, whose product SKU {$menuSku->productSkuId} no longer exists.",
                );
            }

            return [$menuSku, $sku];
        }

        $sku = $this->catalog()->sku((string) $line->productSkuId);
        if ($sku === null) {
            throw new InvalidArgumentException(
                "Order line {$index} references product SKU {$line->productSkuId}, which does not exist.",
            );
        }

        return [$this->catalog()->cheapestActiveMenuLine($branchId, $sku->skuId), $sku];
    }

    /**
     * Resolve ONE order line against the menu/promotion/topping/tax engine —
     * the shared core of resolveOrder (batch) and resolveLine (single-line
     * changeItems). Kept in one place so the create path and the item-mutation
     * path cannot drift on any money rule.
     *
     * @param  array<string, array{price: float|null}>  $floatingPrices  keyed by product_sku_id
     * @param  string|null  $expectedBrandId  set on multi-line create to enforce one brand
     * @param  string|null  $orderCouponId  the ORDER's active coupon (changeItems on an
     *                                      existing order) — legacy addItems refuses an exclusive promotion when a
     *                                      coupon is already applied, and so does this.
     * @return array{selection: OrderLineSelectionPayload, menuSku: ?OrderLineMenuAnchor, sku: OrderLineSkuAnchor, unitPrice: float, originalUnitPrice: ?float, priceSource: OrderItemPriceSourceEnum, promotionId: ?string, rate: float, taxTypeId: ?string, lineSubtotal: float, toppingSubtotal: float, toppingRows: array, brandId: string, organizationId: string}
     */
    private function resolveLineEntry(
        OrderLineSelectionPayload $line,
        ?OrderLineMenuAnchor $menuSku,
        OrderLineSkuAnchor $sku,
        int|string $index,
        string $branchId,
        ?string $contextOrganizationId,
        array $floatingPrices,
        ?string $expectedBrandId,
        ?string $orderCouponId,
        ?float $expectedUnitPrice = null,
    ): array {
        if (! $sku->sellable) {
            throw new InvalidArgumentException(
                "Order line {$index} points at product SKU {$sku->skuId}, which is not sellable (paused, draft, or inactive). Refusing to price it.",
            );
        }

        // Tenant identity: the menu row when the line rides one, otherwise the
        // PRODUCT (off-menu legacy fallback has no menu row to cite).
        // #962 · 7a-8 — `$menuSku?->menuId !== null` là bản scalar của
        // `$menuSku?->menuProduct?->menu !== null` cũ: cổng chỉ điền brand/org khi
        // giải được đến tận hàng `menus`, y như chuỗi `?->` mà nó thay thế.
        $hasMenu = $menuSku?->menuId !== null;
        $anchorBrandId = $hasMenu ? (string) $menuSku->brandId : (string) $sku->productBrandId;
        $anchorOrganizationId = $hasMenu ? (string) $menuSku->organizationId : (string) $sku->productOrganizationId;

        $brandId = $expectedBrandId ?? $anchorBrandId;
        // Tenant/brand consistency is enforced only for MENU-anchored lines —
        // the menu row is branch-scoped so these can only trip on genuine
        // cross-tenant input. The legacy addItems path performs NO tenant check
        // on a bare product SKU, so the off-menu path mirrors that (behavior
        // parity) and logs instead; tightening it is a deliberate follow-up,
        // not a silent 500 on shipped flows.
        if ($hasMenu) {
            if ($anchorBrandId !== $brandId) {
                throw new InvalidArgumentException('All order lines must come from menus of one brand.');
            }
            if ($contextOrganizationId !== null && $anchorOrganizationId !== $contextOrganizationId) {
                throw new InvalidArgumentException(
                    "Order line {$index} belongs to another organization's menu. Cross-tenant pricing is refused.",
                );
            }
        } elseif ($contextOrganizationId !== null && $anchorOrganizationId !== '' && $anchorOrganizationId !== $contextOrganizationId) {
            Log::warning('order.pricing.off_menu_cross_tenant_sku', [
                'line' => (string) $index,
                'product_sku_id' => $sku->skuId,
                'sku_organization_id' => $anchorOrganizationId,
                'context_organization_id' => $contextOrganizationId,
            ]);
        }

        // Price precedence: explicit menu line → floating-section price if
        // LOWER (never higher) → promotion discount baked into the unit
        // price with the strikethrough kept as original_unit_price. This is
        // the legacy addItems sequence verbatim.
        // #2618 (ruling #2132 §B) — snapshot the price SOURCE at the exact
        // spot each precedence step wins. Floating is the source only when
        // it is LOWER: the min() keeps the menu price otherwise, and
        // stamping `floating` on a menu-priced line is a wrong snapshot.
        $rawUnitPrice = (float) ($menuSku?->sellingPrice ?? $sku->sellingPrice);
        $priceSource = $menuSku?->sellingPrice !== null
            ? OrderItemPriceSourceEnum::Menu
            : OrderItemPriceSourceEnum::SkuBase;
        $floating = $floatingPrices[$sku->skuId]['price'] ?? null;
        if ($floating !== null) {
            if ((float) $floating < $rawUnitPrice) {
                $priceSource = OrderItemPriceSourceEnum::Floating;
            }
            $rawUnitPrice = min($rawUnitPrice, (float) $floating);
        }

        $promotion = app(MenuPromotionResolver::class)->activeFor(
            $branchId,
            (string) $sku->productId,
            $this->productCategoryIds((string) $sku->productId),
        );

        $unitPrice = $rawUnitPrice;
        $originalUnitPrice = null;
        if ($promotion !== null) {
            // Decision B5 reverse stacking guard — the order already carries a
            // coupon and this item's promotion is exclusive. Same structured
            // refusal as legacy addItems; the FE shows the auto-remove dialog.
            $stackingMode = $promotion->stackingMode;
            if ($orderCouponId !== null && $stackingMode === MenuPromotionStackingModeEnum::ExclusiveWithCoupons) {
                throw MenuPromotionException::cannotAddPromotionItemWithCoupon(
                    $orderCouponId,
                    [
                        'product_sku_id' => $sku->skuId,
                        'applied_promotion_id' => $promotion->id,
                        'product_id' => $sku->productId,
                    ],
                );
            }

            $originalUnitPrice = $rawUnitPrice;
            // #2618 — the promotion decides the final price formula (even at
            // percent = 0), matching applied_promotion_id being non-null.
            $priceSource = OrderItemPriceSourceEnum::MenuPromotion;
            $unitPrice = round($rawUnitPrice * (100 - $promotion->discountPercent) / 100, 2, PHP_ROUND_HALF_UP);
        }

        // #1715 — `$unitPrice` đã chốt: chặn nếu server tính CAO hơn cái client
        // đang hiển thị. Một dòng một lần gọi ở đường này (append dine-in), nên
        // ghi rồi khẳng định ngay. Ném trước khi định giá topping / giải thuế nên
        // không việc nào phía sau kịp chạy. Độ chính xác khi so theo tiền tệ của
        // CHI NHÁNH — chỉ đọc khi thật sự có kỳ vọng giá để so.
        $priceDrift = new UnitPriceDriftGuard(
            $expectedUnitPrice === null
                ? 'JPY'
                : (ShopOrderSetting::query()->where('branch_id', $branchId)->value('currency_code') ?? 'JPY'),
        );
        $priceDrift->record($index, $sku->skuId, $expectedUnitPrice, $unitPrice);
        $priceDrift->assertNoDrift();

        // Toppings: the shared pricer runs autofill + validation + group
        // strategy exactly as legacy addItems does. A discount topping may
        // go negative, but the line (unit + topping) never drops below
        // zero — a discount can zero a line, never pay the customer.
        $toppingResult = $this->toppingPricer()->priceForSku($sku->skuId, array_map(
            static fn ($t): array => [
                'topping_group_item_id' => $t->toppingGroupItemId,
                'product_sku_id' => $t->productSkuId,
                'quantity' => $t->quantity,
                'note' => $t->note,
            ],
            $line->toppings,
        ),
            $menuSku?->menuProductId,
            // #1180 — a line that did NOT name a menu line came from the
            // floating-section spotlight (those items ship menu_product_sku_id
            // = null), which is the surface that displayed the promo topping
            // price. Give the pricer that owner so the guest is charged what
            // the spotlight showed; a line that DID name a menu line keeps the
            // menu tier and charges what its section showed.
            $line->menuProductSkuId === null
                ? ($floatingPrices[$sku->skuId]['floating_section_product_id'] ?? null)
                : null,
        );
        $toppingSubtotal = max($toppingResult->subtotal, -$unitPrice);
        $toppingRows = $toppingResult->rows;

        // #1218 — the menu + section the line was ordered from, taken from the
        // very menu line whose price this resolution is using, so the rate and
        // the price can never come from different menus.
        $tax = $this->taxBatch()->resolveForLine(
            (string) $sku->productId,
            $sku->productTaxTypeId,
            $menuSku?->taxTypeId,
            $branchId,
            $brandId,
            $menuSku?->menuId,
            $menuSku?->menuSectionId,
        );

        return [
            'selection' => $line,
            'menuSku' => $menuSku,
            'sku' => $sku,
            'unitPrice' => $unitPrice,
            'originalUnitPrice' => $originalUnitPrice,
            'priceSource' => $priceSource,
            'promotionId' => $promotion?->id,
            'rate' => (float) $tax->rate,
            'taxTypeId' => $tax->taxTypeId,
            'lineSubtotal' => $line->quantity * ($unitPrice + $toppingSubtotal),
            'toppingSubtotal' => $toppingSubtotal,
            'toppingRows' => $toppingRows,
            'brandId' => $anchorBrandId,
            'organizationId' => $anchorOrganizationId,
        ];
    }

    /**
     * Single-line resolution for changeItems (add/revise). Anchors on the
     * ORDER: branch, order_type, and the active coupon all come from the row,
     * so a stale client cannot re-anchor the line elsewhere.
     *
     * taxAmountMinor is deliberately 0 here: for a line entering an EXISTING
     * order the per-line tax is a group allocation over the whole order, and
     * refreshOrderTotals stamps it after persistence — exactly as legacy
     * addItems leaves tax_amount at its default for applyPricing to fill.
     */
    public function resolveLine(ChangeOrderItemsCommand $command): OrderLinePayload
    {
        $order = CustomerOrder::query()->findOrFail($command->orderId);
        $line = $command->payload;
        $branchId = (string) $order->branch_id;

        // Order-status gate FIRST, mirroring legacy addItems' assertStatus
        // list and its 409 — the legacy path checks status before it ever
        // looks at the SKU, so a closed order answers 409 even when the line
        // also happens to be unsellable. Duplicated deliberately (the write
        // path re-asserts); parity is pinned by CustomerOrderTransitionTest.
        $status = $order->status instanceof CustomerOrderStatusEnum
            ? $order->status
            : CustomerOrderStatusEnum::from((string) $order->status);
        $mutable = [
            CustomerOrderStatusEnum::AwaitingConfirmation,
            CustomerOrderStatusEnum::Confirmed,
            CustomerOrderStatusEnum::Open,
            CustomerOrderStatusEnum::Pending,
        ];
        if (! in_array($status, $mutable, true)) {
            abort(409, "Cannot change items: order status is '{$status->value}', expected one of: ".implode(', ', array_map(fn ($s) => $s->value, $mutable)));
        }

        [$menuSku, $sku] = $this->anchorForSelection($line, $line->lineId, $branchId);

        $floatingPrices = app(FloatingSectionPricing::class)->resolveForSkus(
            $branchId,
            [$sku->skuId],
        );

        $entry = $this->resolveLineEntry(
            $line,
            $menuSku,
            $sku,
            $line->lineId,
            $branchId,
            $command->context->organizationId,
            $floatingPrices,
            null,
            $order->coupon_id !== null ? (string) $order->coupon_id : null,
            // #1715 — chỉ đường NÀY (append dine-in) mới nhận được kỳ vọng giá từ
            // client. `resolveOrder` bên trên đi qua `CreateOrderCommand`, mà đường
            // đó customer-web chưa dùng (takeaway và đơn dine-in ĐẦU TIÊN đều đi
            // động cơ legacy `WritesCustomerOrders`, nơi cổng này đã cắm riêng).
            $command->expectedUnitPrice,
        );

        $exponent = CurrencyMinorUnit::exponent(
            ShopOrderSetting::query()->where('branch_id', $branchId)->value('currency_code') ?? 'JPY',
        );
        $toMinor = static fn (float $major): int => (int) round($major * (10 ** $exponent));

        $toppingPayloads = array_map(
            static fn (array $row): OrderToppingPayload => new OrderToppingPayload(
                $row['topping_group_item_id'],
                $row['quantity'],
                $toMinor((float) $row['unit_price']),
                $row['product_sku_id'],
                $row['note'] ?? null,
                $row['waived_quantity'],
            ),
            $entry['toppingRows'],
        );

        return new OrderLinePayload(
            itemId: $line->lineId,
            productId: (string) $entry['sku']->productId,
            skuId: $entry['sku']->skuId,
            quantity: $line->quantity,
            unitPriceMinor: $toMinor($entry['unitPrice']),
            toppings: $toppingPayloads,
            evidence: new OrderLineEvidence(
                menuId: $entry['menuSku']?->menuId,
                menuProductId: $entry['menuSku']?->menuProductId,
                menuProductSkuId: $entry['menuSku']?->menuProductSkuId,
                taxTypeId: $entry['taxTypeId'],
                originalUnitPriceMinor: $entry['originalUnitPrice'] === null ? null : $toMinor($entry['originalUnitPrice']),
                taxRateBasisPoints: (int) round($entry['rate'] * 100),
                taxAmountMinor: 0,
                promotionId: $entry['promotionId'],
                promotionDiscountMinor: 0,
                lineSubtotalMinor: $line->quantity * $toMinor($entry['unitPrice']),
                toppingSubtotalMinor: $line->quantity * $toMinor($entry['toppingSubtotal']),
                priceSource: $entry['priceSource'],
            ),
            note: $line->note,
        );
    }

    /**
     * Mirror of WritesCustomerOrders::stampLineTaxAmounts for a zero-discount
     * create: group the lines by rate, take the SAME once-per-group tax the
     * order totals used, and allocate it across the group's lines so
     * Σ(line tax) == group tax exactly.
     *
     * @param  list<array{rate: float, lineSubtotal: float}>  $resolved
     * @return array<int, float> line index → allocated tax (major units)
     */
    private function allocateLineTaxes(array $resolved, bool $includeTax, float $taxStep, string $taxMode): array
    {
        $groups = [];
        foreach ($resolved as $index => $entry) {
            $key = (string) $entry['rate'];
            $groups[$key]['rate'] = $entry['rate'];
            $groups[$key]['indexes'][] = $index;
            $groups[$key]['nets'][] = $entry['lineSubtotal'];
        }

        $allocatedByIndex = [];
        foreach ($groups as $group) {
            $groupTax = $this->calculator->groupTaxFor(array_sum($group['nets']), $group['rate'], $includeTax, $taxStep, $taxMode);
            $ideals = array_map(
                fn (float $net): float => $this->calculator->lineTaxIdeal($net, $group['rate'], $includeTax),
                $group['nets'],
            );
            $allocated = $this->calculator->allocateGroupTax($ideals, $groupTax, $taxStep);

            foreach ($group['indexes'] as $i => $index) {
                $allocatedByIndex[$index] = (float) $allocated[$i];
            }
        }

        return $allocatedByIndex;
    }

    /**
     * #2371 — hỏi Catalog qua cổng thay vì tự đọc pivot của nó. Xem
     * `ProductCategoryLookup` để biết vì sao chỗ đọc này được dời.
     *
     * @return list<string>
     */
    private function productCategoryIds(string $productId): array
    {
        return app(ProductCategoryLookup::class)->categoryIdsFor($productId);
    }
}
