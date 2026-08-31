<?php

namespace App\Services\Order\Internal;

use App\Exceptions\OfflineEvidenceRejected;
use App\Models\ShopOrderSetting;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\CustomerOrderTypeEnum;
use App\Omnify\Enums\OrderItemPriceSourceEnum;
use App\Services\Catalog\CatalogRevisionService;
use App\Services\Customer\OrderPricingCalculator;
use App\Services\Customer\TaxResolver;
use App\Services\DomainMutation\VerificationAuthority;
use App\Services\Order\Commands\ReplayOfflineOrderCommand;
use App\Services\Order\Contracts\OfflineSigningIdentity;
use App\Services\Order\Contracts\OrderEvidenceVerificationPort;
use App\Services\Order\Contracts\OrderLineCatalogAnchors;
use App\Services\Order\Contracts\OrderLineTaxPricing;
use App\Services\Order\Contracts\OrderMenuLineDirectory;
use App\Services\Order\Offline\OfflineOrderSigningMessage;
use App\Services\Order\ValueObjects\OrderDraftPayload;
use App\Services\Order\ValueObjects\OrderLineEvidence;
use App\Services\Order\ValueObjects\OrderLinePayload;
use App\Services\Order\ValueObjects\OrderLineSelectionPayload;
use App\Services\Order\ValueObjects\OrderPricingEvidence;
use App\Services\Order\ValueObjects\OrderServiceChargePayload;
use App\Services\Order\ValueObjects\OrderToppingPayload;
use App\Services\Order\ValueObjects\TrustedOrderSnapshot;
use App\Services\Topping\Contracts\ToppingLinePricing;
use App\Support\CurrencyMinorUnit;
use App\Support\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Verifies signed offline-order evidence and issues the trusted snapshot
 * (#1092/#1096 — the gate that decides whether Cloud believes money a device
 * collected while disconnected).
 *
 * FAIL-CLOSED IN ONE PLACE. Every check below throws
 * {@see OfflineEvidenceRejected} with its own reason code; nothing degrades to
 * "trust the payload". A rejected replay leaves no order behind and the device
 * keeps the row queued for operator handling.
 *
 * THE DEVICE NEVER ASSERTS MONEY. The evidence carries only "device D, using
 * key K, sold selection S against catalog revision R at time T". Cloud re-prices
 * S from revision R's immutable snapshot with its own tax engine, so a
 * compromised device cannot invent a price at all — the worst it can do is name
 * a revision, and the signature binds it to exactly one.
 *
 * TOPPINGS (#1114): priced from the revision's own topping maps — resolved
 * override/extra_price per (parent product, item, topping SKU) plus the group
 * strategy — never from the LIVE config, which moves. A snapshot that predates
 * v2 has no topping data, so an order naming it is refused rather than priced
 * from today's tables (a v1 revision is not a lie, it is just silent about
 * toppings, and silence must never be read as "free").
 */
final class OfflineOrderEvidenceVerifier implements OrderEvidenceVerificationPort
{
    /** Widest window an offline order may claim between issue and expiry. */
    public const MAX_EVIDENCE_WINDOW_HOURS = 72;

    public function __construct(
        // No default: the adapter lives in PlatformIntegration and naming it
        // here would re-open the very boundary this port closes (#962). It is
        // bound in OrderServiceProvider.
        private readonly OfflineSigningIdentity $signingIdentity,
        private readonly CatalogRevisionService $catalogRevisions = new CatalogRevisionService,
        private readonly OrderPricingCalculator $calculator = new OrderPricingCalculator,
    ) {}

    public function verifyOfflineReplay(ReplayOfflineOrderCommand $command): TrustedOrderSnapshot
    {
        $evidence = $command->evidence;
        $selection = $command->payload;
        $now = CarbonImmutable::now();

        // ── 1. Key: exists, belongs to this device, un-revoked, in-window ────
        // `issuedAt` is parsed up front because the identity port answers
        // "was this key valid AT that instant" in the same lookup. Safe: the
        // evidence value object already normalised it to ISO-8601 at
        // construction, so parsing cannot fail here.
        $issuedAt = CarbonImmutable::parse($evidence->issuedAt);
        $key = $this->signingIdentity->findSigningKey($evidence->keyId, $issuedAt);
        if ($key === null) {
            throw OfflineEvidenceRejected::because('unknown_signing_key', "Signing key {$evidence->keyId} is not registered.");
        }
        if ($key->deviceId !== $evidence->deviceId) {
            throw OfflineEvidenceRejected::because(
                'signing_key_device_mismatch',
                "Key {$evidence->keyId} belongs to device {$key->deviceId}, not {$evidence->deviceId}.",
            );
        }
        if ($key->revokedAt !== null) {
            throw OfflineEvidenceRejected::because(
                'signing_key_revoked',
                "Key {$evidence->keyId} was revoked at {$key->revokedAt} ({$key->revokedReason}). A revoked key cannot vouch for money, including retroactively.",
            );
        }
        if (! $key->validAtSignature) {
            throw OfflineEvidenceRejected::because(
                'signing_key_not_valid_at_issue',
                "Key {$evidence->keyId} was not valid at {$evidence->issuedAt} (valid {$key->issuedAt} → {$key->expiresAt}).",
            );
        }

        // ── 2. Device: exists, matches the order's branch, issuer is itself ──
        $device = $this->signingIdentity->findSigningDevice($evidence->deviceId);
        if ($device === null) {
            throw OfflineEvidenceRejected::because('unknown_device', "Device {$evidence->deviceId} does not exist.");
        }
        if ((string) $device->branchId !== $command->branchId) {
            throw OfflineEvidenceRejected::because(
                'device_branch_mismatch',
                "Device {$evidence->deviceId} belongs to branch {$device->branchId} but the replay targets {$command->branchId}.",
            );
        }
        if ((string) $device->organizationId !== (string) $command->context->organizationId) {
            throw OfflineEvidenceRejected::because(
                'device_tenant_mismatch',
                "Device {$evidence->deviceId} is outside the requesting organization.",
            );
        }

        // ── 3. Freshness: inside its own window, and the window is bounded ───
        $expiresAt = CarbonImmutable::parse($evidence->expiresAt);
        if ($issuedAt->gt($now->addMinutes(5))) {
            throw OfflineEvidenceRejected::because(
                'evidence_issued_in_future',
                "Evidence claims issue at {$evidence->issuedAt}, which is ahead of Cloud time {$now->toIso8601String()} beyond the 5-minute clock-skew allowance.",
            );
        }
        if ($now->gte($expiresAt)) {
            throw OfflineEvidenceRejected::because(
                'evidence_expired',
                "Evidence expired at {$evidence->expiresAt} (now {$now->toIso8601String()}). Resolve this order manually — Cloud will not price a sale it can no longer date.",
            );
        }
        if ($issuedAt->diffInHours($expiresAt, absolute: true) > self::MAX_EVIDENCE_WINDOW_HOURS) {
            throw OfflineEvidenceRejected::because(
                'evidence_window_too_wide',
                'Evidence window exceeds '.self::MAX_EVIDENCE_WINDOW_HOURS.'h; a device cannot grant itself an unbounded offline licence.',
            );
        }

        // ── 4. Signature over the exact bytes the device signed ─────────────
        $selectionDigest = OfflineOrderSigningMessage::selectionDigest($selection);
        $message = OfflineOrderSigningMessage::message(
            $evidence->deviceId,
            $evidence->issuerId,
            $evidence->catalogRevision,
            $evidence->issuedAt,
            $evidence->expiresAt,
            $evidence->keyId,
            $selectionDigest,
        );
        if (! OfflineOrderSigningMessage::verifySignature($message, $evidence->signature, $key->publicKey)) {
            throw OfflineEvidenceRejected::because(
                'signature_invalid',
                'Signature does not match the evidence envelope + selection. Either the order was altered after signing or it was signed by a different key.',
            );
        }

        // ── 5. Catalog: the named revision exists and still hashes true ─────
        $revision = $this->catalogRevisions->find($command->branchId, $evidence->catalogRevision);
        if ($revision === null) {
            throw OfflineEvidenceRejected::because(
                'unknown_catalog_revision',
                "Branch {$command->branchId} has no catalog revision {$evidence->catalogRevision}; Cloud cannot re-price this sale.",
            );
        }
        $snapshot = (array) $revision->snapshot;
        if ($this->catalogRevisions->hashSnapshot($snapshot) !== $revision->snapshot_hash) {
            throw OfflineEvidenceRejected::because(
                'catalog_revision_corrupt',
                "Revision {$evidence->catalogRevision} no longer hashes to its recorded value — refusing to price money against a mutated snapshot.",
            );
        }

        // ── 6. Price the selection from THAT revision, with Cloud's engine ───
        // Only the revision NUMBER and its hash travel on — the `CatalogRevision`
        // row is Catalog's, and pricing never needed more than those two values
        // plus the snapshot array (#962).
        return $this->issueSnapshot(
            $command,
            (int) $revision->revision,
            (string) $revision->snapshot_hash,
            $snapshot,
            $selectionDigest,
        );
    }

    /**
     * @param  array<string, array{sku: string, price: string, tax: ?string}>  $snapshot
     */
    private function issueSnapshot(
        ReplayOfflineOrderCommand $command,
        int $revisionNumber,
        string $revisionHash,
        array $snapshot,
        string $selectionDigest,
    ): TrustedOrderSnapshot {
        $selection = $command->payload;
        $setting = ShopOrderSetting::query()->where('branch_id', $command->branchId)->first();

        $currency = $setting?->currency_code ?? 'JPY';
        $includeTax = (bool) ($setting?->prices_include_tax ?? false);
        $taxMode = $setting?->tax_rounding_mode ?: 'round';
        $taxDecimals = $setting?->tax_rounding_decimals !== null ? (int) $setting->tax_rounding_decimals : null;
        $step = RoundingMode::step('auto', $currency);
        $taxStep = RoundingMode::taxStep($taxDecimals, $currency);
        $exponent = CurrencyMinorUnit::exponent($currency);
        $toMinor = static fn (float $major): int => (int) round($major * (10 ** $exponent));

        $snapshotVersion = (int) ($snapshot['v'] ?? 1);
        // v1 nested nothing; the whole array WAS the line map.
        $lineMap = $snapshotVersion >= 2 ? (array) ($snapshot['lines'] ?? []) : $snapshot;

        // #962 · 7a-7 — MỘT lô cho cả lần replay này, đúng như `new TaxResolver`
        // trước đây. Xem `OrderLineTaxPricing` về vòng đời memo.
        $taxBatch = app(OrderLineTaxPricing::class)->beginBatch();
        // #962 — hai cổng Catalog, giải ra MỘT lần cho cả lần replay (giống
        // `$taxBatch` ngay trên): chúng không giữ trạng thái theo dòng.
        $catalog = app(OrderLineCatalogAnchors::class);
        $menuLines = app(OrderMenuLineDirectory::class);
        $resolved = [];
        $rateSubtotals = [];

        foreach ($selection->lines as $index => $line) {
            if ($line->toppings !== [] && $snapshotVersion < 2) {
                throw OfflineEvidenceRejected::because(
                    'offline_toppings_unsupported',
                    "Line {$index} carries toppings but catalog revision {$revisionNumber} predates topping snapshots (v{$snapshotVersion}). Refusing rather than pricing them from today's config; sync this order through the legacy path.",
                );
            }

            // The line MUST name a menu line: the snapshot is keyed by it, and
            // an off-menu SKU has no historical price to verify against.
            if ($line->menuProductSkuId === null) {
                throw OfflineEvidenceRejected::because(
                    'offline_line_not_menu_anchored',
                    "Line {$index} has no menu_product_sku_id; an offline sale can only be verified against a menu line recorded in the catalog revision.",
                );
            }
            if (! array_key_exists($line->menuProductSkuId, $lineMap)) {
                throw OfflineEvidenceRejected::because(
                    'offline_line_absent_from_revision',
                    "Menu line {$line->menuProductSkuId} was not sellable at catalog revision {$revisionNumber}.",
                );
            }

            $entry = $lineMap[$line->menuProductSkuId];
            $unitPrice = (float) $entry['price'];

            // The SKU is Cloud's, taken from the snapshot — never from the
            // device's claim, so a device cannot re-point a line at a cheaper
            // product after the fact.
            //
            // #962 — cả hai phép tra đi qua `OrderLineCatalogAnchors`, cổng mà
            // Catalog hiện thực. `menuLine()` cố ý KHÔNG lọc `is_active` hay phạm
            // vi chi nhánh: giá đã chốt trong revision từ lúc bán, câu hỏi ở đây
            // chỉ là "định danh còn phục hồi được không".
            $menuLine = $catalog->menuLine($line->menuProductSkuId);
            if ($menuLine === null) {
                throw OfflineEvidenceRejected::because(
                    'offline_menu_line_deleted',
                    "Menu line {$line->menuProductSkuId} no longer exists; its historical price is known but its product identity is not recoverable.",
                );
            }

            // `sku()` đi qua `SoftDeletingScope` y như quan hệ `productSku` trước
            // đây, nên một SKU đã xoá mềm vẫn rơi vào nhánh "repointed" như cũ.
            $sku = $catalog->sku($menuLine->productSkuId);
            if ($sku === null || $sku->skuId !== $entry['sku']) {
                throw OfflineEvidenceRejected::because(
                    'offline_menu_line_repointed',
                    "Menu line {$line->menuProductSkuId} now points at a different SKU than revision {$revisionNumber} recorded.",
                );
            }

            // #1218 — menu + section inheritance, from the same menu line the
            // revision recorded. An offline device signs a SELECTION, never a
            // price, and Cloud re-prices it here; if that re-pricing walked a
            // different tier chain than the live engine, an honest order would
            // be rejected as tampered.
            //
            // Ba id tầng 1-3 lấy từ `OrderMenuLineDirectory` — ĐÚNG cổng mà đường
            // bán online dùng, nên hai đường không thể đi lệch chuỗi tầng. Nó đọc
            // `menu_products.menu_id`/`menu_section_id` thẳng từ cột (không qua
            // quan hệ `menu`), giữ nguyên hành vi trước #962.
            $menuTax = $menuLines->taxContextForMenuProduct($menuLine->menuProductId);

            $tax = $taxBatch->resolveForLine(
                (string) $sku->productId,
                $sku->productTaxTypeId,
                $entry['tax'] === null ? null : $menuTax->taxTypeId,
                $command->branchId,
                (string) $menuLine->brandId,
                $menuTax->menuId,
                $menuTax->menuSectionId,
            );

            // #1114 — toppings priced from the SAME revision, per unit of the
            // parent line (mirrors the live engine: the per-unit topping
            // subtotal is multiplied by the line quantity).
            $toppings = $this->priceToppingsFromSnapshot(
                $line,
                $snapshot,
                (string) $sku->productId,
                (string) $menuLine->menuProductId,
                $index,
                $revisionNumber,
            );

            $lineSubtotal = ($unitPrice + $toppings['subtotal']) * $line->quantity;
            $rateKey = (string) $tax->rate;
            $rateSubtotals[$rateKey] = ($rateSubtotals[$rateKey] ?? 0.0) + $lineSubtotal;

            $resolved[] = [
                'selection' => $line,
                'menuLine' => $menuLine,
                'sku' => $sku,
                'unitPrice' => $unitPrice,
                'toppingSubtotal' => $toppings['subtotal'],
                'toppingRows' => $toppings['rows'],
                'rate' => $tax->rate,
                'taxTypeId' => $tax->taxTypeId,
                'lineSubtotal' => $lineSubtotal,
            ];
        }

        $pricing = $this->calculator->priceGroups(
            $rateSubtotals,
            0.0,
            (float) ($setting?->service_charge_rate ?? 0),
            (float) ($setting?->service_charge_tax_rate ?? 0),
            $includeTax,
            $step,
            $taxStep,
            $taxMode,
        );

        $lineTaxes = $this->allocateLineTaxes($resolved, $includeTax, $taxStep, $taxMode);

        $lines = [];
        foreach ($resolved as $i => $entry) {
            $line = $entry['selection'];
            $menuLine = $entry['menuLine'];
            $sku = $entry['sku'];

            $lines[] = new OrderLinePayload(
                itemId: $line->lineId,
                productId: (string) $sku->productId,
                skuId: $sku->skuId,
                quantity: $line->quantity,
                unitPriceMinor: $toMinor($entry['unitPrice']),
                toppings: array_map(
                    static fn (array $row): OrderToppingPayload => new OrderToppingPayload(
                        $row['topping_group_item_id'],
                        $row['quantity'],
                        $toMinor((float) $row['unit_price']),
                        $row['product_sku_id'],
                        $row['note'] ?? null,
                        $row['waived_quantity'],
                    ),
                    $entry['toppingRows'],
                ),
                evidence: new OrderLineEvidence(
                    menuId: $menuLine->menuId,
                    menuProductId: (string) $menuLine->menuProductId,
                    menuProductSkuId: $menuLine->menuProductSkuId,
                    taxTypeId: $entry['taxTypeId'],
                    originalUnitPriceMinor: null,
                    taxRateBasisPoints: (int) round($entry['rate'] * 100),
                    taxAmountMinor: $toMinor($lineTaxes[$i]),
                    promotionId: null,
                    promotionDiscountMinor: 0,
                    // Split so persistence can rebuild the per-unit DB columns
                    // exactly, the same way the live resolver does.
                    lineSubtotalMinor: $line->quantity * $toMinor($entry['unitPrice']),
                    toppingSubtotalMinor: $line->quantity * $toMinor($entry['toppingSubtotal']),
                    // #2618 — every offline line anchors an EXPLICIT menu line
                    // (the revision's lineMap is keyed by menu_product_sku_id)
                    // and its price is that menu line's recorded price; there is
                    // no floating or promotion step on this path.
                    priceSource: OrderItemPriceSourceEnum::Menu,
                ),
                note: $line->note,
            );
        }

        $serviceChargeMinor = $toMinor($pricing->serviceCharge);
        $serviceChargeTaxMinor = $toMinor($pricing->serviceChargeTax);

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
            status: $selection->orderType === CustomerOrderTypeEnum::Takeaway
                ? CustomerOrderStatusEnum::Pending
                : CustomerOrderStatusEnum::Open,
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
            serviceCharge: $serviceChargeMinor === 0 && $serviceChargeTaxMinor === 0
                ? null
                : new OrderServiceChargePayload(
                    amountMinor: $serviceChargeMinor,
                    taxAmountMinor: $serviceChargeTaxMinor,
                    taxRateBasisPoints: (int) round((float) ($setting?->service_charge_tax_rate ?? 0) * 100) ?: null,
                ),
        );

        return TrustedOrderSnapshot::fromOfflineVerifier(
            $this,
            VerificationAuthority::forConfiguredAdapter($this, OrderEvidenceVerificationPort::class, ['order.trusted_snapshot']),
            $command,
            $draft,
            $draft->status,
            $currency,
            // The resolver fingerprint records WHAT authorised this money: the
            // signed selection, the catalog revision, and the engine settings.
            hash('sha256', json_encode([
                'offline' => true,
                'selection_digest' => $selectionDigest,
                'catalog_revision' => $revisionNumber,
                'catalog_hash' => $revisionHash,
                'include_tax' => $includeTax,
                'tax_mode' => $taxMode,
                'tax_decimals' => $taxDecimals,
                'currency' => $currency,
            ], JSON_THROW_ON_ERROR)),
            CarbonImmutable::now()->toIso8601String(),
        );
    }

    /**
     * Price a line's toppings from the catalog revision (#1114).
     *
     * Prices come from the snapshot's resolved map; the per-group discount
     * strategy is applied by the SAME {@see ToppingPricingService} the live
     * engine uses (fed the snapshot's strategy
     * + free_quantity) — writing a second discount engine here would drift from
     * the online path, which is exactly what plan-047 exists to prevent.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{subtotal: float, rows: array<int, array{topping_group_item_id: string, product_sku_id: string, quantity: int, unit_price: float, note: ?string, waived_quantity: int}>}
     */
    private function priceToppingsFromSnapshot(
        OrderLineSelectionPayload $line,
        array $snapshot,
        string $parentProductId,
        string $menuProductId,
        int|string $index,
        int $revisionNumber,
    ): array {
        if ($line->toppings === []) {
            return ['subtotal' => 0.0, 'rows' => []];
        }

        $items = (array) ($snapshot['topping_items'] ?? []);
        $groups = (array) ($snapshot['topping_groups'] ?? []);
        $prices = (array) ($snapshot['topping_prices'] ?? []);
        // #1192 — snapshot v3 records the SHOP tier per menu line. A v2 map is
        // absent here, which is correct for the branches that never overrode a
        // topping price; branches that DID are kept off the evidence path
        // entirely until their revision is rebuilt at v3 (see the
        // catalog_revision_has_toppings gate), rather than re-priced low.
        $overrides = (array) ($snapshot['topping_price_overrides'] ?? []);

        $byGroup = [];
        $rows = [];
        // #2619 — remember which (group, in-group index) each output row came
        // from, so the per-selection waived counts the shared pricer computes
        // can be folded back onto the rows the persistence layer will insert.
        $rowGroupRefs = [];

        foreach ($line->toppings as $topping) {
            $itemId = $topping->toppingGroupItemId;
            $skuId = $topping->productSkuId;

            if (! array_key_exists($itemId, $items)) {
                throw OfflineEvidenceRejected::because(
                    'offline_topping_absent_from_revision',
                    "Line {$index} names topping item {$itemId}, which was not sellable at catalog revision {$revisionNumber}.",
                );
            }

            // Tier 1 (menu line) beats tiers 2/3 (product), exactly as the Go
            // POS and the online pricer resolve it.
            $overrideKey = $menuProductId.'|'.$itemId.'|'.$skuId;
            $priceKey = $parentProductId.'|'.$itemId.'|'.$skuId;
            if (! array_key_exists($overrideKey, $overrides) && ! array_key_exists($priceKey, $prices)) {
                throw OfflineEvidenceRejected::because(
                    'offline_topping_price_unknown',
                    "Line {$index} names topping {$itemId} (sku {$skuId}) with no recorded price at revision {$revisionNumber} for product {$parentProductId}. Refusing rather than assuming zero.",
                );
            }

            $groupId = (string) $items[$itemId]['group'];
            if (! array_key_exists($groupId, $groups)) {
                throw OfflineEvidenceRejected::because(
                    'offline_topping_group_absent_from_revision',
                    "Topping group {$groupId} was not attached at revision {$revisionNumber}.",
                );
            }

            $unitPrice = (float) ($overrides[$overrideKey] ?? $prices[$priceKey]);
            $byGroup[$groupId]['selections'][] = [
                'topping_group_item_id' => $itemId,
                'product_sku_id' => $skuId,
                'quantity' => $topping->quantity,
                'unit_price' => $unitPrice,
            ];
            // #1597 — trước đây phải dựng một `ToppingGroup` GIẢ (không lưu,
            // không id) chỉ để nhét hai giá trị này qua chữ ký. Cổng nhận thẳng
            // cấu hình, nên cái model giả biến mất.
            $byGroup[$groupId]['price_strategy'] = $groups[$groupId]['strategy'];
            $byGroup[$groupId]['free_quantity'] = (int) $groups[$groupId]['free'];

            $rowGroupRefs[] = [$groupId, count($byGroup[$groupId]['selections']) - 1];
            $rows[] = [
                'topping_group_item_id' => $itemId,
                'product_sku_id' => $skuId,
                'quantity' => $topping->quantity,
                'unit_price' => $unitPrice,
                'note' => $topping->note,
            ];
        }

        $priced = app(ToppingLinePricing::class)->priceLineAcrossGroups($byGroup);

        // #2619 — stamp each row with how many of its units free_up_to_n
        // waived, from the SAME pricing run that produced the subtotal.
        foreach ($rowGroupRefs as $i => [$refGroupId, $refIndex]) {
            $rows[$i]['waived_quantity'] = $priced['waived_by_selection'][$refGroupId][$refIndex];
        }

        return ['subtotal' => (float) $priced['topping_subtotal'], 'rows' => $rows];
    }

    /**
     * Per-line tax allocation — delegates to the SAME calculator primitives the
     * live resolver uses (group tax → ideal per line → largest-remainder
     * allocation), so an offline order's per-line tax matches what the identical
     * basket produces online. Re-implementing the split here would be a second
     * rounding engine, and two engines drift.
     *
     * @param  array<int, array{rate: float, lineSubtotal: float}>  $resolved
     * @return array<int, float>
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
}
