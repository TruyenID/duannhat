<?php

declare(strict_types=1);

/**
 * #2108 — architecture guard for the 税込/税抜 ruling (#2102, docs/guide/tax-types.md):
 *
 * > The prices_include_tax flag may only live at the product/menu tier (price
 * > display) and the order service (which direction the engine computes tax).
 * > Every other tier reads the number frozen onto the order
 * > (customer_orders.is_tax_included) — it must NEVER reinterpret the live
 * > branch flag (shop_order_settings.prices_include_tax).
 *
 * Why a guard: the flag answers a question about the INPUT price, answered
 * once at order creation and frozen. Any later read of the branch flag is a
 * path for the paper to disagree with the books the moment an admin flips the
 * setting (OrderPaidInvoiceMail had exactly that fallback before #2108).
 *
 * Mechanics: scan app/** (excluding generated app/Omnify/**) for the column
 * token `prices_include_tax` in CODE (comments/docblocks stripped, same as
 * BusinessTimeArchitectureTest — prose explaining the rule must not trip it).
 * `prices_include_tax_at_open` (the TillSession per-shift snapshot, itself a
 * frozen copy) is a different token and is excluded by lookahead.
 *
 * A file may reference the column only when listed below WITH its reason.
 * Adding an entry is a ruling question, not a formality — see #2102 first.
 */
const TAX_MODE_FLAG_PATTERN = '/prices_include_tax(?!_at_open)/';

/**
 * file (relative to base_path) => one-line reason the read/write is inside
 * the sanctioned tiers. Keep reasons honest — they are the review surface.
 */
const TAX_MODE_FLAG_ALLOWED = [
    // ── Flag owner: settings CRUD + admin surface ────────────────────────
    'app/Models/ShopOrderSetting.php' => 'column owner ($fillable)',
    'app/Services/Shop/ShopOrderSettingsService.php' => 'settings write funnel: PATCH guard (409 mid-shift), payload, #2108 create-only country default',
    'app/Http/Controllers/Api/V1/Shop/ShopOrderSettingsController.php' => 'settings GET/PATCH envelope for admin-web (display of the setting itself)',

    // ── Feeds DOWN to clients (the client\'s menu-display/pricing tier) ──
    'app/Http/Controllers/Api/V1/Workstation/BranchController.php' => 'sync-DOWN feed: workstation mirrors the flag to price NEW orders at creation',
    'app/Http/Controllers/Api/V1/Customer/CustomerBranchController.php' => 'customer-web menu display feed (tax-included price labels)',
    'app/Http/Controllers/Api/V1/Customer/CustomerTableController.php' => 'customer-web QR/table menu display feed (#1778)',

    // ── Order service: the ONE capture at creation + the locked gate ─────
    'app/Services/Order/Internal/EloquentBranchOrderSettingsLock.php' => 'BranchOrderSettingsLock impl — the locked read TillSessionService snapshots prices_include_tax_at_open through',
    'app/Services/Order/Internal/Concerns/WritesCustomerOrders.php' => 'order creation funnel — stamps customer_orders.is_tax_included once',
    'app/Services/Order/Internal/CustomerOrderPricingResolution.php' => 'order creation pricing (online path) — flag read at creation time only',
    'app/Services/Order/Internal/OfflineOrderEvidenceVerifier.php' => 'offline replay creation pricing — same creation-time read as the online path',

    // ── Ops commands that WRITE the setting (flag owner surface) ─────────
    'app/Console/Commands/TaxExemptBrand.php' => 'operator command flipping the setting itself (--off label)',
    'app/Console/Maintenance/TaxExemptBrandPersistence.php' => 'persistence for TaxExemptBrand — writes the setting',
];

/**
 * Chỉ giữ phần MÃ, bỏ comment và docblock — same rationale as
 * businessTimeCodeOnly() (#1921): documenting a fixed bug must not count as
 * the bug.
 */
function taxModeFlagCodeOnly(string $contents): string
{
    $out = '';

    foreach (@token_get_all($contents) as $token) {
        if (! is_array($token)) {
            $out .= $token;

            continue;
        }

        if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
            continue;
        }

        $out .= $token[1];
    }

    return $out;
}

it('blocks reading the branch prices_include_tax flag outside the sanctioned tiers (#2108)', function () {
    $root = base_path('app');

    $violations = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = ltrim(str_replace(base_path(), '', $file->getPathname()), '/');

        // Generated code (regen would fight a hand-edit; the editable models
        // above are the enforcement surface).
        if (str_starts_with($relative, 'app/Omnify/')) {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());
        $count = preg_match_all(TAX_MODE_FLAG_PATTERN, taxModeFlagCodeOnly($contents));
        if ($count > 0) {
            $violations[$relative] = $count;
        }
    }

    $offenders = [];
    foreach ($violations as $file => $count) {
        if (! array_key_exists($file, TAX_MODE_FLAG_ALLOWED)) {
            $offenders[] = "{$file}: {$count} reference(s)";
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'New reference to shop_order_settings.prices_include_tax outside the sanctioned',
        'tiers. The 税込/税抜 flag lives at menu display + order creation ONLY — every',
        'later tier must read the frozen customer_orders.is_tax_included snapshot',
        '(#2102 ruling, docs/guide/tax-types.md). If this read really belongs to a',
        'sanctioned tier, add the file to TAX_MODE_FLAG_ALLOWED with an honest reason.',
        ...$offenders,
    ]));

    // Shrink-only bookkeeping: an allowlisted file that no longer references
    // the flag should lose its entry, so the list mirrors reality.
    $stale = [];
    foreach (array_keys(TAX_MODE_FLAG_ALLOWED) as $file) {
        if (! array_key_exists($file, $violations)) {
            $stale[] = "{$file}: no longer references the flag — remove its entry";
        }
    }

    expect($stale)->toBe([], "Stale TAX_MODE_FLAG_ALLOWED entries:\n".implode("\n", $stale));
});

/*
 * #2108 companion rule — the fallback pattern itself. Even inside sanctioned
 * files, `$order->is_tax_included ?? <branch flag>` re-opens the hole this
 * guard exists for (the column is NOT NULL, so the fallback arm is dead code
 * that only ever fires as a bug). Ban the coalesce on the snapshot column when
 * the same statement mentions the branch flag.
 */
it('blocks re-introducing the is_tax_included ?? prices_include_tax fallback (#2108)', function () {
    $root = base_path('app');

    $offenders = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = ltrim(str_replace(base_path(), '', $file->getPathname()), '/');
        if (str_starts_with($relative, 'app/Omnify/')) {
            continue;
        }

        $code = taxModeFlagCodeOnly((string) file_get_contents($file->getPathname()));
        // One logical expression: snapshot ?? … branch flag within ~120 chars.
        if (preg_match('/is_tax_included\s*\?\?[^;]{0,120}prices_include_tax/s', $code)) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'Branch-flag fallback for a missing order snapshot detected. The column is',
        'NOT NULL — read (bool) $order->is_tax_included and nothing else (#2108).',
        ...$offenders,
    ]));
});
