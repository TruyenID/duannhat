<?php

declare(strict_types=1);

/**
 * plan-053 TR-28 (#1171) — the provenance stamp on `print_jobs.template_version`.
 *
 * One distinction carries this whole feature: a row that records NO layout must
 * stay tellable apart from a row that records one. Collapse them and a reprint
 * of a legacy-formatter sheet goes looking for a template that never drew it —
 * the customer gets a different document, and nothing anywhere says so.
 *
 * The stamp is written by two independent producers (the Go workstation and
 * Cloud's own CloudPRNT enqueue), so its shape is a contract, not an
 * implementation detail.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Services\Print\Enums\PrintTemplateKind;
use App\Services\Print\Enums\PrintTemplateScope;
use App\Services\Print\TemplateResolver;
use App\Services\Print\TemplateStamp;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

it('parses scope AND version, because a number alone names two different documents', function () {
    $brand = TemplateStamp::parse('brand:7');
    Assert::assertNotNull($brand);
    Assert::assertSame(PrintTemplateScope::Brand, $brand->scope);
    Assert::assertSame(7, $brand->version);

    $shop = TemplateStamp::parse('shop:7');
    Assert::assertNotNull($shop);
    Assert::assertSame(PrintTemplateScope::Shop, $shop->scope);
    Assert::assertSame(7, $shop->version);

    // Same version number, different definition. If the stamp carried only the
    // integer, these two would be indistinguishable and `forVersion()` would
    // have to guess which layer a reprint came from.
    Assert::assertNotSame($brand->scope, $shop->scope);
});

it('reads NULL and blank as "no layout recorded", never as the system default', function () {
    foreach ([null, '', '   '] as $absent) {
        Assert::assertNull(
            TemplateStamp::parse($absent),
            'an absent stamp means the legacy formatter drew the sheet; resolving it to a template '
            .'would send a reprint to a definition that never touched it',
        );
    }
});

it('refuses a malformed stamp instead of half-reading it', function () {
    foreach (['brand', 'brand:', ':7', 'brand:v7', '7', 'brand:7:9x'] as $junk) {
        Assert::assertNull(
            TemplateStamp::parse($junk),
            sprintf('[%s] parsed — a half-understood provenance is worse than an admitted unknown', $junk),
        );
    }
});

it('keeps the version of an unknown scope rather than claiming `system`', function () {
    // `unknown:N` is the workstation's word for a cache row whose scope column
    // was never filled. Mapping it onto `system` would assert the binary's
    // default drew a sheet a published template drew.
    $stamp = TemplateStamp::parse('unknown:4');

    Assert::assertNotNull($stamp);
    Assert::assertNull($stamp->scope);
    Assert::assertSame(4, $stamp->version);
    Assert::assertFalse($stamp->isSystemDefault());
});

it('names the code-shipped layer 0 `system:0`, whose 0 is a name and not a placeholder', function () {
    $stamp = TemplateStamp::parse('system:0');

    Assert::assertNotNull($stamp);
    Assert::assertTrue($stamp->isSystemDefault());
    Assert::assertSame('system:0', $stamp->toString());
});

it('stamps a resolved template in the same shape the workstation sends', function () {
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
        'timezone' => 'Asia/Tokyo',
    ]);

    // Nothing published → layer 0. The Go side reports exactly `system:0` here
    // (`PrintTemplateSource.Stamp`), and a drift between the two would make the
    // column unreadable across the fleet.
    $resolved = app(TemplateResolver::class)->forBranch(PrintTemplateKind::Receipt, $branch->id);

    Assert::assertSame('system:0', TemplateStamp::of($resolved));
    Assert::assertLessThanOrEqual(TemplateStamp::MAX_LENGTH, strlen(TemplateStamp::of($resolved)));
});
