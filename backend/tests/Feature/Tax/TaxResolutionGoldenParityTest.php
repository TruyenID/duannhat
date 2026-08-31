<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\ShopOrderSetting;
use App\Models\TaxType;
use App\Services\Customer\TaxResolver;
use Illuminate\Support\Str;

/*
 * Cross-language contract for tax resolution (#1099).
 *
 * WHY THIS EXISTS. Two implementations decide what tax a line carries: Cloud's
 * TaxResolver, and the workstation's Go mirror (internal/service/tax_resolver.go)
 * which must resolve the SAME rate while offline — a register cannot ask Cloud
 * what tax to charge when the internet is down, and the receipt it prints is
 * handed to a customer before Cloud ever sees the order. If the two walks
 * disagree, the shop collects one amount and books another, and the difference
 * shows up as an unexplained 過不足 in the shift report rather than as an error.
 *
 * Prose in two repos cannot enforce that. So the contract is a DATA file —
 * tests/Fixtures/tax_resolution_golden.json, byte-identical to
 * workstation/internal/service/testdata/tax_resolution_golden.json — and
 * each side asserts the same expectations against its own resolver, exactly as
 * the offline signing golden does for signatures.
 *
 * Cloud is authoritative: the fixture states what Cloud does. When Cloud's rules
 * change, this test fails FIRST, and the same commit must carry the fixture to
 * the workstation repo. The digest check below is what makes a one-sided edit
 * impossible to miss.
 */

/**
 * Read without base_path(): Pest resolves datasets before the application is
 * booted, and the cases below are a dataset so each one gets a clean database.
 *
 * @return array<string, mixed>
 */
function taxGolden(): array
{
    $path = __DIR__.'/../../Fixtures/tax_resolution_golden.json';

    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * The canonical text form the digest is taken over.
 *
 * Deliberately a DELIMITED string, not canonical JSON: both languages must
 * produce the identical bytes, and delimited fields are the one encoding where
 * that is obvious by inspection (key order, whitespace and float formatting in
 * JSON are not). Same reasoning as OfflineOrderSigningMessage.
 */
function taxGoldenDigestMessage(array $cases): string
{
    $absent = '~';
    $lines = [];

    foreach ($cases as $case) {
        $lines[] = implode('|', [
            $case['name'],
            $case['menu_tax_type_id'] ?? $absent,
            $case['product_tax_type_id'] ?? $absent,
            $case['branch_default_tax_type_id'] ?? $absent,
            number_format((float) $case['legacy_branch_tax_rate'], 2, '.', ''),
            $case['expect']['tax_type_id'] ?? $absent,
            number_format((float) $case['expect']['rate'], 2, '.', ''),
            $case['expect']['has_snapshot'] ? 'true' : 'false',
        ]);
    }

    return implode("\n", $lines);
}

/**
 * Build the world one case describes and resolve one line in it.
 *
 * @return array{tax_type_id: ?string, rate: float, has_snapshot: bool}
 */
function resolveTaxGoldenCase(array $golden, array $case): array
{
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
        'is_active' => true,
    ]);

    // A brand with zero tax types is the only way to reach the no-snapshot
    // outcome, so that case suppresses the catalog entirely.
    $noBrandDefault = (bool) ($case['no_brand_default'] ?? false);
    $catalog = $noBrandDefault
        ? []
        : array_merge($golden['tax_types'], $case['extra_tax_types'] ?? []);

    foreach ($catalog as $row) {
        TaxType::factory()->create([
            'id' => $row['id'],
            'organization_id' => $orgId,
            'brand_id' => $brand->id,
            'code' => $row['code'],
            'rate' => $row['rate'],
            'is_default' => $row['is_default'],
            'is_active' => $row['is_active'],
        ]);
    }

    // A dangling branch default is unreachable through the FK on Cloud, so the
    // equivalent Cloud state is "the setting points at nothing resolvable" —
    // modelled by leaving the column null once the id names no existing row.
    $branchDefault = $case['branch_default_tax_type_id'];
    $branchDefaultExists = $branchDefault !== null
        && collect($catalog)->contains(fn (array $r): bool => $r['id'] === $branchDefault);

    ShopOrderSetting::factory()->create([
        'branch_id' => $branch->id,
        'default_tax_type_id' => $branchDefaultExists ? $branchDefault : null,
        'prices_include_tax' => false,
        'currency_code' => 'JPY',
    ]);

    $productType = ProductType::factory()->create(['organization_id' => $orgId, 'brand_id' => $brand->id]);

    $productTypeId = $case['product_tax_type_id'];
    $productTypeExists = $productTypeId !== null
        && collect($catalog)->contains(fn (array $r): bool => $r['id'] === $productTypeId);

    $product = Product::factory()->active()->create([
        'organization_id' => $orgId,
        'brand_id' => $brand->id,
        'product_type_id' => $productType->id,
        'tax_type_id' => $productTypeExists ? $productTypeId : null,
    ]);

    // Tier 1 arrives as an already-resolved model (the MenuProduct relation).
    // A menu line naming a type that no longer resolves — soft-deleted on Cloud,
    // not-yet-synced on the workstation — presents as null, which is what makes
    // the walk continue instead of bailing out.
    $menuTypeId = $case['menu_tax_type_id'];
    $menuTaxType = $menuTypeId === null ? null : TaxType::find($menuTypeId);

    $resolution = (new TaxResolver)->resolveForLine(
        $product->fresh(['taxType']),
        $menuTaxType,
        (string) $branch->id,
        (string) $brand->id,
    );

    return [
        'tax_type_id' => $resolution->taxTypeId === null ? null : (string) $resolution->taxTypeId,
        'rate' => (float) $resolution->rate,
        'has_snapshot' => $resolution->taxTypeId !== null,
    ];
}

it('resolves a golden case exactly as the shared contract states', function (array $case) {
    // One test per case, so each gets a clean database: the fixture pins LITERAL
    // uuids (they are the same rows the Go mirror seeds) and reusing them inside
    // a single test would collide on the primary key.
    $actual = resolveTaxGoldenCase(taxGolden(), $case);

    expect($actual['tax_type_id'])->toBe(
        $case['expect']['tax_type_id'],
        "wrong tax TYPE. {$case['why']}",
    );
    expect($actual['rate'])->toBe(
        (float) $case['expect']['rate'],
        "wrong RATE. {$case['why']}",
    );
    expect($actual['has_snapshot'])->toBe(
        $case['expect']['has_snapshot'],
        "wrong snapshot flag — an explicit 0% and an unresolved line must stay distinguishable. {$case['why']}",
    );
})->with(fn () => collect(taxGolden()['cases'])
    ->mapWithKeys(fn (array $case): array => [$case['name'] => [$case]])
    ->all());

it('runs every case the fixture declares', function () {
    $golden = taxGolden();

    expect($golden['version'])->toBe('tempo-tax-resolution-v1')
        ->and($golden['cases'])->toHaveCount($golden['case_count']);
});

it('pins the fixture digest so the file cannot be edited in only one repo', function () {
    $golden = taxGolden();
    $digest = hash('sha256', taxGoldenDigestMessage($golden['cases']));

    // The workstation test computes this digest over the same delimited form
    // from its own copy of the file. Both assert it against the value STORED IN
    // the fixture, so a case edited on one side alone fails on that side
    // immediately — the digest and the cases can only be changed together, and
    // then only by carrying the same file to the other repo.
    expect($digest)->toBe(
        $golden['digest'],
        "The golden fixture's cases and its stored digest disagree. If you changed a case on purpose, put this digest in the `digest` field AND copy the whole file to workstation/internal/service/testdata/tax_resolution_golden.json in the same commit: {$digest}",
    );
});

it('states a rate for every case a real shop can reach', function () {
    // Guards the fixture itself: a case with no `why` is a case nobody can
    // review, and coverage of the four tiers plus both no-snapshot shapes is
    // what makes the contract meaningful rather than decorative.
    $golden = taxGolden();

    foreach ($golden['cases'] as $case) {
        expect($case['why'] ?? '')->not->toBe('', "case '{$case['name']}' has no rationale");
        expect($case)->toHaveKeys(['menu_tax_type_id', 'product_tax_type_id', 'branch_default_tax_type_id', 'legacy_branch_tax_rate', 'expect']);
    }

    $names = collect($golden['cases'])->pluck('name')->implode(' ');
    expect($names)->toContain('tier1')
        ->and($names)->toContain('tier2')
        ->and($names)->toContain('tier3')
        ->and($names)->toContain('tier4')
        ->and($names)->toContain('nothing resolves');
});

/**
 * The digest above proves the fixture is internally consistent — its cases
 * match its own stored hash. It does NOT prove the workstation is looking at
 * the same bytes, and the docblock's "byte-identical to
 * workstation/internal/service/testdata/tax_resolution_golden.json" was
 * prose, not a check: updating a case + its digest on this side only, and
 * forgetting to copy the file, left both suites green while Cloud and the
 * register resolved tax differently. That is precisely the silent drift the
 * fixture exists to prevent.
 *
 * The offline signing golden already gates this way; tax now does too.
 */
it('keeps the workstation copy of the tax fixture byte-identical (skipped when the submodule is absent)', function () {
    $ours = base_path('tests/Fixtures/tax_resolution_golden.json');
    $theirs = base_path('../workstation/internal/service/testdata/tax_resolution_golden.json');

    if (! file_exists($theirs)) {
        // A standalone backend checkout has no submodule — the Go repo's own
        // test still guards its side.
        //
        // This used to be `expect(true)->toBeTrue(); return;`, which reported
        // PASSED for a comparison that never ran (#2089). markTestSkipped is the
        // same decision stated honestly, and the whole-repo version of this
        // check — hard-failing on CI — is in
        // tests/Feature/Architecture/SharedFixturesAgreeTest.php.
        test()->markTestSkipped('nguồn workstation vắng mặt trong cây (in-tree từ #2306) — cổng parity bị bỏ qua');
    }

    expect(hash_file('sha256', $theirs))->toBe(
        hash_file('sha256', $ours),
        'the two tax golden fixtures diverged — copy this file to workstation/internal/service/testdata/tax_resolution_golden.json so both repos gate on the same bytes',
    );
});
