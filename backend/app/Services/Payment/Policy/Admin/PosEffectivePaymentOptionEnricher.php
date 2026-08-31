<?php

namespace App\Services\Payment\Policy\Admin;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\PaymentGatewayOption;
use App\Models\PaymentMethod;
use App\Models\ShopPaymentOption;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Omnify\Enums\PaymentOptionRailEnum;
use App\Omnify\Enums\PaymentPolicyPreferenceEnum;
use App\Services\Payment\Policy\Contracts\PaymentOwnerOptionPolicySource;
use App\Services\Payment\Policy\Enums\UpstreamPolicyState;

/** Adds POS client capabilities and legacy PaymentMethod identity to effective options. */
final class PosEffectivePaymentOptionEnricher
{
    public function __construct(
        private readonly PaymentOwnerOptionPolicySource $ownerPolicySource,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function enrich(array $payload, Branch $shop): array
    {
        $rail = (string) ($payload['rail'] ?? '');
        $methodType = isset($payload['method_type']) && is_string($payload['method_type'])
            ? $payload['method_type']
            : null;

        $legacyCode = $this->legacyPaymentMethodCode($rail, $methodType);
        $legacyMethod = $legacyCode === null
            ? null
            : $this->resolveLegacyMethod($legacyCode, $shop);

        $requiresTendered = $legacyMethod !== null
            ? (bool) $legacyMethod->requires_tendered
            : $rail === PaymentOptionRailEnum::Cash->value;

        $immediateSettlement = $legacyMethod !== null
            ? (bool) $legacyMethod->is_auto_confirm
            : $this->defaultImmediateSettlement($rail, $methodType);

        $legacyType = $legacyMethod !== null ? (string) ($legacyMethod->type ?? '') : '';
        $supportsPosCheckout = ($payload['effective'] ?? false) === true
            && $immediateSettlement
            && $legacyMethod !== null
            && $legacyType !== 'on_account';

        return array_merge($payload, [
            'method_type' => $methodType,
            'legacy_payment_method_id' => $legacyMethod?->id,
            'legacy_payment_method_code' => $legacyCode,
            'client' => [
                'requires_tendered' => $requiresTendered,
                'immediate_settlement' => $immediateSettlement,
                'supports_pos_checkout' => $supportsPosCheckout,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $evaluation
     * @param  PaymentChannelEnum  $channel  #1085 — the internal-tender append is
     *                                       channel-aware: self_regi gets cash + card_terminal by catalog
     *                                       default, kiosk stays whatever its catalog channels say. Default
     *                                       Pos preserves every existing call site byte-for-byte.
     */
    public function enrichEvaluation(array $evaluation, Branch $shop, PaymentChannelEnum $channel = PaymentChannelEnum::Pos): array
    {
        $options = $evaluation['options'] ?? [];
        if (! is_array($options)) {
            return $evaluation;
        }

        $evaluation['options'] = [
            ...array_map(
                fn (mixed $option): array => $this->enrich(is_array($option) ? $option : [], $shop),
                $options,
            ),
            ...$this->internalTenderOptions($shop, $channel),
        ];

        return $evaluation;
    }

    /**
     * Internal tenders (cash, standalone card terminal) never have a merchant
     * connection, so the fail-closed policy resolver can never surface them —
     * candidates only exist for connection-backed catalog options. Plan-048
     * T1.2 instead bridges them here: each active `internal.*` catalog option
     * is appended with its legacy PaymentMethod identity, effective only when
     * that method exists and is active for the shop. `connection_id` stays
     * null by design — internal money is recordTender-only, never gateway API.
     *
     * @return list<array<string, mixed>>
     */
    private function internalTenderOptions(Branch $shop, PaymentChannelEnum $channel = PaymentChannelEnum::Pos): array
    {
        $catalogOptions = PaymentGatewayOption::query()
            ->with('translations')
            ->whereHas('provider', function ($query): void {
                $query->where('code', PaymentGatewayProviderCodeEnum::Internal->value)
                    ->where('is_active', true);
            })
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        // A shop CAN switch an internal tender off — "this branch does not take
        // cash". It can never switch one on that the catalog does not carry, so
        // reading the row here only ever narrows, exactly like every other tier
        // of payment policy. Before this the row was written and then ignored:
        // `PATCH /shops/{shop}/payment-options/{cash}` returned 200 and changed
        // nothing anywhere (#F10).
        $shop->loadMissing('brand');
        $brandId = $shop->brand === null ? null : (string) $shop->brand->id;

        $shopPreferences = ShopPaymentOption::query()
            ->where('branch_id', $shop->id)
            ->whereIn('option_id', $catalogOptions->pluck('id')->all())
            ->get()
            ->keyBy('option_id');

        $rows = [];
        foreach ($catalogOptions as $catalogOption) {
            $channels = is_array($catalogOption->channels) ? $catalogOption->channels : [];
            if (! in_array($channel->value, $channels, true)) {
                continue;
            }

            $rail = (string) ($catalogOption->rail instanceof \BackedEnum
                ? $catalogOption->rail->value
                : $catalogOption->rail);
            $methodType = $catalogOption->method_type === null
                ? null
                : (string) $catalogOption->method_type;

            $legacyCode = $this->legacyPaymentMethodCode($rail, $methodType);
            $legacyMethod = $legacyCode === null ? null : $this->resolveLegacyMethod($legacyCode, $shop);

            $shopOption = $shopPreferences->get($catalogOption->id);
            $shopPreference = $shopOption?->preference instanceof PaymentPolicyPreferenceEnum
                ? $shopOption->preference->value
                : (string) ($shopOption?->preference ?? PaymentPolicyPreferenceEnum::Inherit->value);

            $shopTurnedOff = in_array($shopPreference, [
                PaymentPolicyPreferenceEnum::Disabled->value,
                PaymentPolicyPreferenceEnum::Blocked->value,
            ], true);

            // …and the brand can turn it off for every shop at once. Without
            // this, HQ setting "Tiền mặt = Bị chặn" changed nothing anywhere
            // while the HQ screen showed it as blocked — the shop screen and
            // POS both went on reporting cash as effective.
            $brandDenied = $brandId !== null
                && $this->ownerPolicySource->resolve($brandId, (string) $catalogOption->id) === UpstreamPolicyState::Denied;

            $effective = $legacyMethod !== null && ! $shopTurnedOff && ! $brandDenied;
            $reason = match (true) {
                $brandDenied => 'internal_tender_brand_blocked',
                $shopTurnedOff => 'internal_tender_shop_disabled',
                $legacyMethod !== null => 'internal_tender',
                default => 'internal_tender_method_missing',
            };

            $rows[] = $this->enrich([
                'id' => (string) $catalogOption->id,
                'display_name' => (string) ($catalogOption->name ?? $catalogOption->code),
                // Every stored locale of the same label, alongside the resolved
                // one. A direct-to-Cloud caller localizes through SetLocale and
                // only ever needs `display_name`; the workstation pulls this
                // feed on a background tick with no Accept-Language and mirrors
                // it for EVERY terminal in the shop, so it needs all of them —
                // the reader's locale is not known at pull time. Absent for
                // connection-backed options, whose display_name is a method-type
                // slug rather than a translatable label; the mirror falls back
                // to `display_name` there.
                'display_name_i18n' => $this->catalogOptionNameTranslations($catalogOption),
                'provider' => PaymentGatewayProviderCodeEnum::Internal->value,
                'rail' => $rail,
                'method_type' => $methodType,
                'effective' => $effective,
                'source' => 'internal_catalog',
                'reason' => $reason,
                'error_code' => null,
                'connection_id' => null,
                'connection_option_id' => null,
                'shop_option_id' => $shopOption?->id,
                'owner_scope' => null,
                'operator_org_unit_id' => null,
                'shop_preference' => $shopPreference,
                'device_preference' => 'inherit',
                'trace' => [],
            ], $shop);
        }

        return $rows;
    }

    /**
     * Stored translations of a catalog option's `name`, keyed by locale.
     *
     * Reads the eager-loaded relation instead of `translate($locale)` per
     * language so the option list stays a fixed number of queries. A locale
     * with no row is omitted rather than filled from a fallback: the consumer
     * has to be able to tell "never translated" from "translated to the same
     * text", because only the former should fall back.
     *
     * @return array<string, string>|object locale → label; `(object) []` khi rỗng
     *                                      (xem lý do ở chỗ `return` bên dưới)
     */
    private function catalogOptionNameTranslations(PaymentGatewayOption $option): array|object
    {
        $out = [];
        foreach ($option->translations as $translation) {
            $value = $translation->getAttribute('name');
            if (is_string($value) && $value !== '') {
                $out[(string) $translation->locale] = $value;
            }
        }

        // Rỗng thì phải phát ra `{}`, KHÔNG phải `[]`.
        //
        // `json_encode([])` cho ra `[]`, và máy trạm giải mã trường này vào
        // `map[string]string` (`sync_pull_pos.go`). Go từ chối mảng vào map, nên
        // MỘT trường rỗng làm hỏng TOÀN BỘ lượt giải mã của feed:
        //
        //   json: cannot unmarshal array into Go struct field
        //   optionPull.data.branch.options.display_name_i18n of type map[string]string
        //
        // Hậu quả đo được trên máy dev: `PullEffectivePaymentOptions` hỏng mỗi
        // vòng, bảng `effective_payment_options` của máy trạm ở lại 0 dòng, và
        // POS báo "Chưa cấu hình phương thức thanh toán tại quầy" — quán không
        // thu được tiền. Lỗi chỉ đi vào một `slog.Warn`, nên không ai thấy.
        //
        // Nó nổ đúng khi tuỳ chọn KHÔNG có bản dịch nào — trạng thái hợp lệ theo
        // chính hợp đồng đang ghi ở đây ("mirror falls back to display_name") và
        // là trạng thái mặc định của mọi DB `migrate:fresh --seed`
        // (`PaymentGatewayCatalogSeeder` không seed `payment_gateway_option_translations`).
        //
        // Ép kiểu CHỈ khi rỗng: mảng có khoá chuỗi vốn đã encode ra `{}`, và giữ
        // nguyên kiểu mảng cho ca đó để mọi chỗ đọc bằng `['ja']` không đổi.
        return $out === [] ? (object) [] : $out;
    }

    /**
     * plan-055 #1831 — the payment methods this shop's INTERNAL tender catalog
     * resolves to (cash, standalone card terminal, on-account).
     *
     * Exists so "is this an internal tender?" has exactly ONE definition, and it
     * is the same one that builds the POS option list: the internal-provider
     * catalog, mapped through `legacyPaymentMethodCode()`. A second predicate
     * keyed on `PaymentMethod.type` or a hard-coded code list would drift from
     * this the first time the catalog changes, and drift here means either
     * refusing cash or waiving a gateway payment.
     *
     * Channel-independent on purpose: a cash sale is a cash sale whether it was
     * rung on POS or a kiosk, and enforcement must not depend on which surface
     * took it.
     *
     * Server-owned: `PaymentMethod` is resolved from the DB by id, so a device
     * cannot claim internal-ness. Naming a cash method only ever records a cash
     * payment — it routes no money through any gateway.
     *
     * @return list<string> payment method ids
     */
    public function internalTenderMethodIds(Branch $shop): array
    {
        $cacheKey = (string) $shop->id;

        if (array_key_exists($cacheKey, $this->internalTenderMethodIdCache)) {
            return $this->internalTenderMethodIdCache[$cacheKey];
        }

        $internalCodes = [];
        $gatewayCodes = [];

        // plan-055 (#1859) — the EFFECTIVITY WINDOW, not just `is_active`.
        //
        // `PaymentPolicyResolver:304` already refuses a capability outside its
        // window (`ConnectionApprovedCapability::appliesAt()` →
        // `CapabilityExpired`). This method did not, so the two disagreed: an
        // option seeded ahead of the Gate 2 cutover with a FUTURE
        // `effective_from` was counted here as gateway-routable — un-exempting
        // `card_terminal` for every shop in every org, since the catalog has no
        // org scoping — while the resolver correctly ignored it.
        //
        // Same predicate as `appliesAt()`: `from <= now < to`, `to` null = open.
        // Wall-clock `now()` on purpose and NOT `BusinessClock`: this compares
        // two instants against UTC timestamp columns. The business-time rule
        // (#1091) governs business DATES — shift boundaries, day-grouped
        // reports — not "is this catalog row in force right now".
        $now = now();

        $options = PaymentGatewayOption::query()
            ->where('is_active', true)
            ->where('effective_from', '<=', $now)
            ->where(function ($query) use ($now): void {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>', $now);
            })
            ->with('provider')
            ->get();

        foreach ($options as $option) {
            $rail = (string) ($option->rail instanceof \BackedEnum
                ? $option->rail->value
                : $option->rail);
            $methodType = $option->method_type === null ? null : (string) $option->method_type;

            $code = $this->legacyPaymentMethodCode($rail, $methodType);

            if ($code === null) {
                continue;
            }

            $isInternal = $option->provider !== null
                // `provider->code` is cast to an enum, so a bare (string) cast
                // throws — same trap as `Device::type`.
                && ($option->provider->code instanceof \BackedEnum
                    ? (string) $option->provider->code->value
                    : (string) $option->provider->code) === PaymentGatewayProviderCodeEnum::Internal->value
                && (bool) $option->provider->is_active;

            if ($isInternal) {
                $internalCodes[] = $code;
            } else {
                $gatewayCodes[] = $code;
            }
        }

        // On-account has NO catalog option at all, internal or otherwise, yet
        // `legacyPaymentMethodCode()` maps `on_account` to it and pos-web's debt
        // CTA posts it with no gateway identity. Caught in review: the first
        // version of this method CLAIMED to cover on-account in its docblock and
        // did not, so the Gate 6 flip would still have refused every 掛売
        // collection — the same defect this method exists to prevent, shipped
        // with a comment saying it was fixed.
        $internalCodes[] = self::ON_ACCOUNT_CODE;

        // MINUS anything a real gateway can also route — EXCEPT the tenders that
        // are physical or ledger-only.
        //
        // The subtraction is asymmetric in consequence, so it must be asymmetric
        // in scope. Under-subtract and a gateway payment gets waived: bad, but
        // recoverable. Over-subtract and CASH IS REFUSED AT THE TILL — the exact
        // bug this method exists to prevent, and cash cannot be un-refused.
        //
        // `cash` is reachable by ANY provider: `PaymentOptionRailEnum::Cash` is
        // not internal-only, `legacyPaymentMethodCode()` returns `cash` for rail
        // OR method_type `cash`, and `payment_gateway_options` has no org
        // scoping at all. So ONE konbini row on the cash rail — Stripe already
        // builds konbini intents and `sbps` is in the provider enum, and those
        // catalog rows land at the Gate 2 cutover, i.e. exactly when the flip is
        // being prepared — would subtract `cash` for EVERY shop in EVERY org.
        //
        // It would also be wrong on the merits: konbini money really does settle
        // through a gateway, so it arrives carrying its own option id and takes
        // the identity fork. Subtracting the physical `cash` METHOD is pure
        // collateral damage.
        //
        // `card_terminal` is the genuinely dual-routable one (`card_present`),
        // so it stays subject to the subtraction — that is the mirror hazard the
        // test below covers.
        //
        // This is not belt-and-braces: `legacyPaymentMethodCode()` maps
        // `card_present` to the SAME `card_terminal` code the internal option
        // maps to, and Stripe Terminal card_present is live. The day a gateway
        // option with `card_present` is seeded (the Gate 2 cutover), that method
        // becomes genuinely gateway-routable — and without this subtraction a
        // real gateway payment with no identity would be waived.
        $exemptCodes = array_values(array_diff(
            array_unique($internalCodes),
            array_diff(array_unique($gatewayCodes), self::NEVER_GATEWAY_ROUTABLE),
        ));

        $ids = [];

        foreach ($exemptCodes as $code) {
            $method = $this->resolveLegacyMethod($code, $shop);

            if ($method !== null && ! in_array((string) $method->id, $ids, true)) {
                $ids[] = (string) $method->id;
            }
        }

        return $this->internalTenderMethodIdCache[$cacheKey] = $ids;
    }

    private function legacyPaymentMethodCode(string $rail, ?string $methodType): ?string
    {
        if ($methodType === 'card_terminal' || $methodType === 'card_present') {
            return 'card_terminal';
        }

        if ($rail === PaymentOptionRailEnum::Cash->value || $methodType === 'cash') {
            return 'cash';
        }

        if ($methodType === 'on_account') {
            return 'debt';
        }

        return match ($rail) {
            PaymentOptionRailEnum::Card->value => 'card',
            PaymentOptionRailEnum::BankTransfer->value => 'transfer',
            PaymentOptionRailEnum::Wallet->value,
            PaymentOptionRailEnum::Qr->value,
            PaymentOptionRailEnum::EMoney->value => 'e_wallet',
            default => $methodType,
        };
    }

    private function defaultImmediateSettlement(string $rail, ?string $methodType): bool
    {
        if ($rail === PaymentOptionRailEnum::Cash->value || $methodType === 'cash') {
            return true;
        }

        return in_array($methodType, ['card_terminal', 'card_present'], true);
    }

    /** @var array<string, PaymentMethod|null> */
    private array $legacyMethodCache = [];

    /** @var array<string, list<string>> shop id => internal tender payment method ids */
    private array $internalTenderMethodIdCache = [];

    /** Legacy method code for 掛売 — it has no catalog option; see internalTenderMethodIds(). */
    private const ON_ACCOUNT_CODE = 'debt';

    /**
     * Tenders no gateway can route, whatever the catalog says.
     *
     * Cash is physical and 掛売 is a ledger entry; a catalog row claiming a
     * gateway routes either is a DATA ERROR, and honouring it would refuse cash
     * at the till for every shop in every org.
     *
     * @var list<string>
     */
    private const NEVER_GATEWAY_ROUTABLE = ['cash', self::ON_ACCOUNT_CODE];

    /** @var array<string, string|null> */
    private array $organizationIdCache = [];

    /**
     * plan-055 T7.1 (#1887) — resolve a branch-scoped payment method by its
     * legacy code. THE canonical implementation.
     *
     * `LegacyPaymentMethodResolver` (deleted in #1887) held an equivalent copy of
     * this query — same columns, same branch scoping, same
     * "branch-specific row wins over the org-wide one" ordering. Two copies of
     * one rule is how they drift, and this one decides which PaymentMethod a
     * payment is booked against.
     *
     * Giới hạn của chữ "canonical" ở đây: nó nói về TRUY VẤN, không nói về cách
     * ra `$organizationId`. `resolveLegacyMethod()` suy org từ branch
     * (`console_organization_id` → `organizations.id`); hai controller truyền
     * thẳng `$device->organization_id`. Hôm nay cả hai rơi vào cùng một
     * `organizations.id` nên không có lỗi sống — nhưng vẫn là hai đường suy diễn
     * nuôi chung một truy vấn, tức đúng loại trôi dạt mà việc gộp này định dẹp.
     *
     * Naming it here is safe: `LegacyRemovalReadiness` counts mentions in CODE
     * only, skipping comments and docblocks via `token_get_all` (#1822). I
     * assumed otherwise and reworded this comment to avoid the token — then
     * measured, and the gate reported 0 call sites either way. Restored, because
     * the class name is what makes this paragraph findable.
     *
     * Lives here because this class already OWNS the option→method mapping
     * (`legacyPaymentMethodCode()` + the internal-tender set). A separate
     * service would just be the legacy class under a new name.
     *
     * Returns null rather than aborting: a service does not decide the HTTP
     * response. Callers that need a hard failure say so themselves.
     */
    public function resolveMethodByCode(string $code, string $organizationId, string $branchId): ?PaymentMethod
    {
        return PaymentMethod::query()
            ->where('code', $code)
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->where(function ($query) use ($branchId): void {
                $query->whereNull('branch_id')
                    ->orWhere('branch_id', $branchId);
            })
            // Branch-specific row wins over the org-wide fallback.
            ->orderByRaw('CASE WHEN branch_id IS NOT NULL THEN 0 ELSE 1 END')
            ->first();
    }

    private function resolveLegacyMethod(string $code, Branch $shop): ?PaymentMethod
    {
        // Request-scoped memo (#1112) — enrich() + internalTenderOptions()
        // resolve the same (code, shop) pairs repeatedly per request.
        $cacheKey = $shop->id.':'.$code;
        if (array_key_exists($cacheKey, $this->legacyMethodCache)) {
            return $this->legacyMethodCache[$cacheKey];
        }

        // Branch carries only console_organization_id; payment_methods FKs the
        // LOCAL organizations.id, so the console id must be mapped first (the
        // previous `$shop->organization_id` read a column branches never had,
        // silently matching nothing).
        $consoleOrgId = (string) $shop->console_organization_id;
        if (! array_key_exists($consoleOrgId, $this->organizationIdCache)) {
            $this->organizationIdCache[$consoleOrgId] = Organization::query()
                ->where('console_organization_id', $consoleOrgId)
                ->value('id');
        }
        $organizationId = $this->organizationIdCache[$consoleOrgId];

        if ($organizationId === null) {
            return $this->legacyMethodCache[$cacheKey] = null;
        }

        return $this->legacyMethodCache[$cacheKey] = $this->resolveMethodByCode(
            $code,
            (string) $organizationId,
            (string) $shop->id,
        );
    }
}
