<?php

declare(strict_types=1);

/**
 * plan-053 M5 (#1171) — the SVG preview renderer, the composer behind it and
 * the two preview endpoints (TR-32/TR-33).
 *
 * What these tests are actually protecting: the preview is what a brand
 * DECIDES from. If it disagrees with the slip about where a line breaks, or
 * about which blocks appear, the brand publishes something it never saw. So
 * the assertions here are about structural fidelity — block order, toggles,
 * locale resolution, wrapping at the real column count — not about the sample
 * figures, which are illustrative by design.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\PrintTemplate;
use App\Models\Role;
use App\Models\User;
use App\Services\Print\Enums\PrintTemplateKind;
use App\Services\Print\Enums\PrintTemplateScope;
use App\Services\Print\Renderer\Definition;
use App\Services\Print\Renderer\Escpos;
use App\Services\Print\Renderer\Layout;
use App\Services\Print\Renderer\PrintJobConfig;
use App\Services\Print\Renderer\PrintKindRegistry;
use App\Services\Print\Renderer\PrintLabels;
use App\Services\Print\Renderer\PrintRenderContext;
use App\Services\Print\Renderer\PrintRenderData;
use App\Services\Print\Renderer\PrintRenderDiscount;
use App\Services\Print\Renderer\PrintRenderOrder;
use App\Services\Print\Renderer\ReceiptTaxSummary;
use App\Services\Print\Renderer\SampleSlipData;
use App\Services\Print\Renderer\SlipComposer;
use App\Services\Print\Renderer\SvgRenderer;
use App\Services\Print\Renderer\TaxLabels;
use App\Services\Print\SystemTemplateDefaults;
use App\Services\Print\TemplateVersionService;
use Database\Seeders\IamSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'invoice_registration_number' => null,
    ]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'timezone' => 'Asia/Tokyo',
    ]);

    $this->composer = app(SlipComposer::class);
    $this->svg = app(SvgRenderer::class);
    $this->defaults = app(SystemTemplateDefaults::class);

    Definition::resetWarnings();
});

/** All composed lines of one kind as plain strings. */
function composedLines(PrintTemplateKind|string $kind, string $locale = 'ja', int $columns = 48): array
{
    $kindEnum = $kind instanceof PrintTemplateKind ? $kind : PrintTemplateKind::from($kind);
    $definition = app(SystemTemplateDefaults::class)->forKind($kindEnum);

    return array_map(
        fn (array $l): string => $l['text'],
        app(SlipComposer::class)->compose($definition, $kindEnum, $locale, $columns),
    );
}

// ─── the composer ─────────────────────────────────────────────────────────

it('R1: composes every kind at every locale and both papers without throwing', function () {
    // The preview must never be the thing that breaks the editor — a brand
    // that cannot preview cannot work, and there is no fallback here the way
    // the print path has one.
    foreach (PrintTemplateKind::cases() as $kind) {
        foreach (['ja', 'en', 'vi'] as $locale) {
            foreach ([32, 48] as $columns) {
                $lines = $this->composer->compose(
                    $this->defaults->forKind($kind),
                    $kind,
                    $locale,
                    $columns,
                );

                expect($lines)->toBeArray()
                    ->and($lines)->not->toBe([], "{$kind->value}/{$locale}/{$columns} composed nothing");
            }
        }
    }
});

it('R2: no composed line is wider than the paper, at either width', function () {
    // The one property a preview MUST have: it fits. A line wider than the
    // column count means the preview is showing a layout the printer cannot
    // produce, so the brand is designing against a fiction.
    foreach (PrintTemplateKind::cases() as $kind) {
        foreach (['ja', 'en', 'vi'] as $locale) {
            foreach ([32, 48] as $columns) {
                foreach ($this->composer->compose($this->defaults->forKind($kind), $kind, $locale, $columns) as $line) {
                    $width = Layout::displayWidth($line['text']);
                    expect($width)->toBeLessThanOrEqual(
                        $columns,
                        sprintf('%s/%s/%d: "%s" is %d columns', $kind->value, $locale, $columns, $line['text'], $width),
                    );
                }
            }
        }
    }
});

it('R3: a disabled block contributes nothing', function () {
    $definition = $this->defaults->forKind(PrintTemplateKind::Receipt);

    $withLogo = $definition;
    foreach ($withLogo['blocks'] as $i => $block) {
        if ($block['id'] === 'footer_text') {
            $withLogo['blocks'][$i] = array_replace($block, [
                'enabled' => true,
                'fallback' => true,
                'i18n' => ['ja' => 'ありがとうございました'],
            ]);
        }
    }

    $on = $this->composer->compose($withLogo, PrintTemplateKind::Receipt, 'ja', 48);
    $off = $this->composer->compose($definition, PrintTemplateKind::Receipt, 'ja', 48);

    expect(collect($on)->pluck('text'))->toContain('ありがとうございました');
    expect(collect($off)->pluck('text'))->not->toContain('ありがとうございました');
});

it('R4: block ORDER on the preview follows the definition, not the catalog', function () {
    // #1042 — the money footer's order is the whole reason #1181 existed. A
    // preview that silently re-sorted would hide exactly the mistake the
    // brand is looking for.
    $definition = $this->defaults->forKind(PrintTemplateKind::Receipt);
    $composed = $this->composer->compose($definition, PrintTemplateKind::Receipt, 'ja', 48);

    $blocks = collect($composed)->pluck('block')->unique()->values()->all();
    $totalAt = array_search('grand_total', $blocks, true);
    $taxAt = array_search('tax_breakdown', $blocks, true);

    expect($totalAt)->not->toBeFalse();
    expect($taxAt)->not->toBeFalse();
    expect($totalAt)->toBeLessThan($taxAt, '内税 must render BELOW the grand total (#1042)');
});

it('R5: each block type renders alone and all of them render together', function () {
    $base = [
        'schema' => 'tempo.print.v1',
        'paper' => ['columns_58mm' => 32, 'columns_80mm' => 48],
        'blocks' => [],
    ];

    $blocks = [
        'text' => ['id' => 'footer_text', 'type' => 'text', 'i18n' => ['ja' => 'ありがとう']],
        'params' => ['id' => 'order_meta', 'type' => 'params', 'fields' => ['order_no', 'table']],
        'line_items' => ['id' => 'items', 'type' => 'line_items', 'columns' => ['name', 'qty', 'amount']],
        'qr' => ['id' => 'qr_block', 'type' => 'qr', 'source' => 'order_url'],
        'image' => ['id' => 'logo', 'type' => 'image', 'source' => 'brand_logo'],
        'locked' => ['id' => 'grand_total', 'type' => 'locked'],
    ];

    foreach ($blocks as $type => $block) {
        $definition = $base;
        $definition['blocks'] = [$block];

        $lines = $this->composer->compose($definition, PrintTemplateKind::Receipt, 'ja', 48);
        expect($lines)->not->toBe([], "block type `{$type}` rendered nothing on its own");
    }

    // …and composed together, every one of them still contributes: a block
    // that renders alone but vanishes in company is the failure mode a
    // per-type test alone would miss.
    $all = $base;
    $all['blocks'] = array_values($blocks);
    $composed = $this->composer->compose($all, PrintTemplateKind::Receipt, 'ja', 48);

    expect(collect($composed)->pluck('block')->unique()->sort()->values()->all())
        ->toBe(collect($blocks)->pluck('id')->sort()->values()->all());
});

it('R6: an unknown block id is skipped rather than fatal', function () {
    // Cloud validates on publish (TR-14); the preview must survive anything
    // that reaches it anyway, including a definition from a newer Cloud.
    $definition = [
        'schema' => 'tempo.print.v1',
        'blocks' => [
            ['id' => 'from_the_future', 'type' => 'locked'],
            ['id' => 'grand_total', 'type' => 'locked'],
        ],
    ];

    $lines = $this->composer->compose($definition, PrintTemplateKind::Receipt, 'ja', 48);
    expect(collect($lines)->pluck('block'))->not->toContain('from_the_future');
    expect(collect($lines)->pluck('block'))->toContain('grand_total');
});

it('R7: a definition with no blocks composes to nothing instead of erroring', function () {
    expect($this->composer->compose(['schema' => 'tempo.print.v1'], PrintTemplateKind::Receipt, 'ja', 48))->toBe([]);
    expect($this->composer->compose(['blocks' => []], PrintTemplateKind::Receipt, 'ja', 48))->toBe([]);
    expect($this->composer->compose(['blocks' => ['nonsense']], PrintTemplateKind::Receipt, 'ja', 48))->toBe([]);
});

// ─── i18n (TR-19) ─────────────────────────────────────────────────────────

it('R8: the fallback chain is locale → ja → en', function () {
    $block = ['id' => 'x', 'type' => 'text', 'i18n' => ['ja' => 'あり', 'en' => 'Thanks']];

    // A Vietnamese reader gets JA, not EN — ja is the accounting locale.
    expect(Definition::resolveText($block, 'vi'))->toBe('あり');
    expect(Definition::resolveText($block, 'en'))->toBe('Thanks');
    expect(Definition::resolveText($block, 'ja'))->toBe('あり');
});

it('R9: an unknown or empty locale resolves to Japanese', function () {
    $block = ['id' => 'x', 'type' => 'text', 'i18n' => ['ja' => 'JA', 'en' => 'EN', 'vi' => 'VI']];

    foreach (['', 'ja-JP', 'fr', 'KO', '  '] as $locale) {
        expect(Definition::resolveText($block, $locale))->toBe('JA', "locale `{$locale}`");
    }
});

it('R10: any non-empty entry beats printing a blank line', function () {
    $block = ['id' => 'x', 'type' => 'text', 'i18n' => ['vi' => 'Cam on']];
    expect(Definition::resolveText($block, 'en'))->toBe('Cam on');
});

it('R11: an empty i18n table resolves to the empty string, not an error', function () {
    expect(Definition::resolveText(['id' => 'x', 'type' => 'text'], 'ja'))->toBe('');
    expect(Definition::resolveText(['id' => 'x', 'type' => 'text', 'i18n' => []], 'ja'))->toBe('');
    expect(Definition::resolveText(['id' => 'x', 'type' => 'text', 'i18n' => ['ja' => '']], 'ja'))->toBe('');
});

it('R12: the missing-locale warning fires ONCE per locale, not once per block or per render', function () {
    /*
     * A busy shop prints hundreds of slips an hour, and a brand that shipped a
     * half-translated template has many untranslated blocks. Warning per print
     * buries the log; warning per block repeats the same fact. Once per locale
     * is what somebody can act on.
     */
    Definition::resetWarnings();

    $blocks = [
        ['id' => 'a', 'type' => 'text', 'i18n' => ['ja' => 'あ']],
        ['id' => 'b', 'type' => 'text', 'i18n' => ['ja' => 'い']],
    ];

    for ($i = 0; $i < 5; $i++) {
        foreach ($blocks as $block) {
            Definition::resolveText($block, 'vi');
        }
    }

    expect(Definition::warnedLocales())->toBe(1);

    // A second locale warns once more — 2 total, not 2 per block.
    Definition::resolveText($blocks[0], 'en');
    expect(Definition::warnedLocales())->toBe(2);
});

it('R13: a fully translated block warns zero times', function () {
    Definition::resetWarnings();

    Definition::resolveText(
        ['id' => 'x', 'type' => 'text', 'i18n' => ['ja' => 'a', 'en' => 'b', 'vi' => 'c']],
        'vi',
    );

    expect(Definition::warnedLocales())->toBe(0);
});

it('R14: i18n_narrow is used below 42 columns and ignored at or above it', function () {
    // The VAT invoice already shortens its title on 58mm paper; expressing
    // that in the definition is what let the registry reproduce today's slip.
    $block = [
        'id' => 'title',
        'type' => 'text',
        'i18n' => ['ja' => 'HOA DON GIA TRI GIA TANG'],
        'i18n_narrow' => ['ja' => 'HOA DON GTGT'],
    ];

    expect(Definition::resolveText($block, 'ja', narrow: true))->toBe('HOA DON GTGT');
    expect(Definition::resolveText($block, 'ja', narrow: false))->toBe('HOA DON GIA TRI GIA TANG');
});

it('R15: the VAT invoice preview shows the short heading on 58mm and the long one on 80mm', function () {
    // Chỉ hỏi `vi`: nhánh `ja` của vat_invoice là chứng từ Nhật khác hẳn
    // (適格簡易請求書, layout riêng) chứ không phải bản dịch — xem G5. Bản cũ hỏi
    // `ja` rồi expect chuỗi Việt, đúng một cách tình cờ.
    $narrow = composedLines(PrintTemplateKind::VatInvoice, 'vi', 32);
    $wide = composedLines(PrintTemplateKind::VatInvoice, 'vi', 48);

    expect($narrow)->toContain('HOA DON GTGT')
        ->and($narrow)->not->toContain('HOA DON GIA TRI GIA TANG');

    expect($wide)->toContain('HOA DON GIA TRI GIA TANG')
        ->and($wide)->not->toContain('HOA DON GTGT');
});

// ─── the SVG ──────────────────────────────────────────────────────────────

it('R16: renders well-formed, self-contained SVG for every kind', function () {
    foreach (PrintTemplateKind::cases() as $kind) {
        $svg = $this->svg->render($this->defaults->forKind($kind), $kind, 'ja', 48);

        expect($svg)->toStartWith('<svg')->and($svg)->toEndWith('</svg>');

        // Parse it — a preview that does not parse is a broken image in the
        // editor, and the brand has no way to tell that from an empty slip.
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($svg);
        libxml_use_internal_errors($previous);
        expect($doc)->not->toBeFalse("{$kind->value} produced malformed SVG");

        // Strictly inert: a preview is embedded in the admin UI and must not
        // be able to fetch or execute anything.
        // The SVG namespace URI is an identifier, never fetched — strip it
        // before asserting that nothing else in the document points outward.
        $withoutNamespace = str_replace('http://www.w3.org/2000/svg', '', $svg);

        expect($svg)->not->toContain('<script')
            ->and($withoutNamespace)->not->toContain('http://')
            ->and($withoutNamespace)->not->toContain('https://')
            ->and($svg)->not->toContain('<image')
            ->and($svg)->not->toContain('xlink:href')
            ->and($svg)->not->toContain('@import');
    }
});

it('R17: authored text is XML-escaped so a brand cannot inject markup', function () {
    $definition = $this->defaults->forKind(PrintTemplateKind::Receipt);
    foreach ($definition['blocks'] as $i => $block) {
        if ($block['id'] === 'footer_text') {
            $definition['blocks'][$i] = array_replace($block, [
                'enabled' => true,
                'fallback' => true,
                'i18n' => ['ja' => '</text><script>alert(1)</script>'],
            ]);
        }
    }

    $svg = $this->svg->render($definition, PrintTemplateKind::Receipt, 'ja', 48);

    expect($svg)->not->toContain('<script>')
        ->and($svg)->toContain('&lt;script&gt;');
});

it('R18: the 58mm preview is narrower than the 80mm one', function () {
    $narrow = $this->svg->render($this->defaults->forKind(PrintTemplateKind::Receipt), PrintTemplateKind::Receipt, 'ja', 32);
    $wide = $this->svg->render($this->defaults->forKind(PrintTemplateKind::Receipt), PrintTemplateKind::Receipt, 'ja', 48);

    preg_match('/width="([\d.]+)"/', $narrow, $n);
    preg_match('/width="([\d.]+)"/', $wide, $w);

    expect((float) $n[1])->toBeLessThan((float) $w[1]);
});

it('R19: white-space is preserved — every column position is made of literal spaces', function () {
    $svg = $this->svg->render($this->defaults->forKind(PrintTemplateKind::Receipt), PrintTemplateKind::Receipt, 'ja', 48);

    // Without these, an SVG renderer collapses runs of spaces and every
    // right-aligned price silently shifts left.
    expect($svg)->toContain('xml:space="preserve"')
        ->and($svg)->toContain('white-space:pre');
});

// ─── the endpoints (TR-32, TR-37) ─────────────────────────────────────────

function previewActor(string $roleSlug, string $orgId): User
{
    if (! Permission::query()->exists()) {
        (new IamSeeder)->run();
    }

    $user = User::factory()->create(['console_organization_id' => $orgId]);
    $user->assignRole(Role::query()->where('slug', $roleSlug)->firstOrFail(), $orgId);

    return $user;
}

it('R20: HQ preview returns SVG for both papers and all three locales', function () {
    $user = previewActor('org-admin', $this->orgId);

    foreach (['58mm', '80mm'] as $paper) {
        foreach (['ja', 'en', 'vi'] as $locale) {
            $response = $this->actingAs($user)->get(
                "/api/v1/hq/{$this->brand->slug}/print-templates/receipt/preview?paper={$paper}&locale={$locale}",
            );

            $response->assertOk();
            expect($response->headers->get('Content-Type'))->toContain('image/svg+xml');
            expect($response->getContent())->toStartWith('<svg');
        }
    }
});

it('R21: a brand with nothing published previews the SYSTEM DEFAULT rather than 404ing', function () {
    // The common case on this screen (TR-01): the brand has published nothing
    // and still needs to see what it is starting from.
    $user = previewActor('org-admin', $this->orgId);

    $this->actingAs($user)
        ->get("/api/v1/hq/{$this->brand->slug}/print-templates/shift_report/preview")
        ->assertOk();
});

it('R22: the preview is never cached — a draft changes as it is edited', function () {
    $user = previewActor('org-admin', $this->orgId);

    $response = $this->actingAs($user)
        ->get("/api/v1/hq/{$this->brand->slug}/print-templates/receipt/preview");

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('R23: an unknown kind is a 422, not a 500', function () {
    $user = previewActor('org-admin', $this->orgId);

    $this->actingAs($user)
        ->get("/api/v1/hq/{$this->brand->slug}/print-templates/not_a_kind/preview")
        ->assertStatus(422)
        ->assertJsonPath('code', 'PRINT_TEMPLATE_KIND_UNKNOWN');
});

it('R24: an invalid paper or locale is rejected rather than silently defaulted', function () {
    // Silently falling back to 80mm would show a 58mm shop a slip that fits
    // when its own does not.
    $user = previewActor('org-admin', $this->orgId);

    $this->actingAs($user)
        ->getJson("/api/v1/hq/{$this->brand->slug}/print-templates/receipt/preview?paper=70mm")
        ->assertStatus(422);

    $this->actingAs($user)
        ->getJson("/api/v1/hq/{$this->brand->slug}/print-templates/receipt/preview?locale=fr")
        ->assertStatus(422);
});

it('R25: a cashier cannot reach the preview at all (TR-37)', function () {
    // The surface is INVISIBLE to a cashier, not merely read-only: they hold
    // neither menu.manage nor shop.manage.
    $cashier = previewActor('shop-staff', $this->orgId);

    $this->actingAs($cashier)
        ->getJson("/api/v1/hq/{$this->brand->slug}/print-templates/receipt/preview")
        ->assertForbidden();

    $this->actingAs($cashier)
        ->getJson("/api/v1/shops/{$this->branch->slug}/print-templates/receipt/preview")
        ->assertForbidden();
});

it('R26: the shop preview renders the RESOLVED slip', function () {
    $manager = previewActor('shop-manager', $this->orgId);

    $response = $this->actingAs($manager)
        ->get("/api/v1/shops/{$this->branch->slug}/print-templates/receipt/preview?paper=58mm&locale=vi");

    $response->assertOk();
    expect($response->getContent())->toStartWith('<svg');
});

it('R27: permission is checked BEFORE the kind is validated, so probing kinds leaks nothing', function () {
    $cashier = previewActor('shop-staff', $this->orgId);

    // A 422 here would tell an unauthorised caller which kinds exist.
    $this->actingAs($cashier)
        ->getJson("/api/v1/hq/{$this->brand->slug}/print-templates/not_a_kind/preview")
        ->assertForbidden();
});

it('R28: the preview shows the REAL reprint mark, not a lookalike (#1166 P-10b)', function () {
    // The sample figures above are illustrative on purpose — amounts, dates,
    // customer names. `reprint_marker` is not one of them: the block is `locked`
    // with no editable props, so its text is a SYSTEM CONSTANT the brand can
    // never author. A preview that shows 「*** 再発行 #2 ***」 promises a mark that
    // will never appear — on a slip whose whole purpose is telling a copy from
    // an original. It also mis-measures the line: the kanji version is far
    // wider, and column width is what a brand designs against.
    //
    // #2028 sharpens this: the mark is per-LOCALE, not one constant. The
    // printer emits ASCII `BAN IN #N` in en/vi (half the fleet has no kanji
    // ROM) and 「再印刷 #N」 in ja — `ReprintMarker` + `PrintLabels->reprintMark`.
    // Pinning only the ASCII form made the ja preview show a mark 2 columns
    // narrower than the real one.
    foreach (['en' => 'BAN IN #2', 'vi' => 'BAN IN #2', 'ja' => '再印刷 #2'] as $locale => $expected) {
        // `forKind()` returns params / items / locked / columns — the marker is
        // a LOCKED block, so it lives under `locked`, not beside the free params.
        $marker = SampleSlipData::forKind(PrintTemplateKind::Receipt, 32, $locale)['locked']['reprint_marker'][0];

        expect($marker['text'])->toBe($expected, "reprint mark at {$locale}")
            ->and($marker['align'] ?? null)->toBe(SlipComposer::ALIGN_RIGHT);
    }
});

/**
 * Sample rows whose label is still a literal in {@see SampleSlipData} rather
 * than a catalog lookup, with the reason each one is (see the `#2028-unmapped`
 * comments there). Everything NOT on this list must come from a print catalog,
 * which is what R29 enforces.
 *
 * Shrinking this list is the goal; growing it needs a reason written down in
 * both places.
 *
 * #2036 — `debt_slip` also carries literals of its own ("Tong" / "Da thanh
 * toan", beside the "GHI NO" already listed). They are deliberately NOT added
 * here: this list is keyed by BLOCK ID, so listing `grand_total`/`payments`
 * would exempt the bill family too, where those two labels must keep coming
 * from the catalog. R36 pins the debt slip's three by their exact strings
 * instead, which is stricter than an exemption, not looser.
 *
 * @return array<string, string>
 */
function unmappedSampleLabels(): array
{
    return [
        'staff_name' => 'no emitter prints a staff line on a bill',
        'cover_count' => 'guestUnit is a unit, not a row label',
        'customer_tax_code' => 'emitVatParties writes the VAT party block itself',
        'customer_address' => 'emitVatParties writes the VAT party block itself',
        'business_date' => '精算 prints a period, never a business date',
        'closed_at' => 'the real 精算 meta prints the closing time bare',
        'red_invoice_marker' => 'no emitter draws a 赤伝 marker at all',
        // `void_marker` đã RỜI danh sách này (#2045 đợt hai): sample giờ mang
        // đúng chuỗi ASCII của `emitVoidMarker` ("BIEN BAN HUY HOA DON") nên
        // R29 quét nó bình thường; chuỗi được R43 ghim theo từng byte.
        'debt_summary' => 'emitDebtOwed prints the ASCII literal "GHI NO" in every locale',
        // #2045 hạ HAI mục khỏi danh sách này, và cả hai bằng cách chữa nguyên
        // nhân chứ không bằng cách nới rào:
        //
        //   `invoice_number`  — mọi kind CÓ block này giờ đọc đúng chữ emitter
        //                       của nó vẽ (`$vatRows` / `$vatJaRows` / `$voidRows`),
        //                       còn họ bill không có emitter nên không có dòng
        //                       nào để miễn trừ (R40).
        //   `tax_breakdown`   — dòng theo mức trung thực KHÔNG vừa 58mm, và nó
        //                       lọt R2 chỉ vì sample dùng chuỗi tự chế ngắn hơn.
        //                       #2035 sửa BỐ CỤC (xuống dòng, không mất số liệu)
        //                       nên bản trung thực vừa giấy và sample dựng thẳng
        //                       từ emitter (R41).
    ];
}

/**
 * Kinds whose PAPER is Japanese at every locale, so a Japanese label in the
 * sample is the faithful reading rather than the #2028 defect (#2039).
 *
 * There is exactly one, and it is not a locale question: `japaneseDoc` is a
 * property of the KIND (#1493 — a Vietnamese shop running a Japanese UI must
 * not get a Japanese document, and a 適格簡易請求書 printed on a machine set to
 * `vi` is still a Japanese document). {@see VatInvoiceJa} hard-codes 小計 /
 * 合計 / お支払 with no catalog behind them, so R29's "read it from a print
 * catalog" instruction has nothing to point at here.
 *
 * This is deliberately NOT an entry in `unmappedSampleLabels()`: that list is
 * keyed by BLOCK ID, and `subtotal`/`grand_total`/`payments` listed there would
 * exempt the bill family too — where those three must keep coming from the
 * catalog — and hollow R29 out. Keyed by kind, and only the `locked` rows are
 * skipped (`params` are shared across kinds and stay policed).
 *
 * The exemption is not the guarantee: R38 pins every one of these strings
 * exactly, which is stricter than an exemption rather than looser.
 *
 * @return array<string, string>
 */
function japaneseByConstructionKinds(): array
{
    return [
        'qualified_simplified_invoice' => 'VatInvoiceJa prints 小計 / 合計 / お支払 at every locale — japaneseDoc rides the KIND (#1493)',
    ];
}

it('R29: no sample LABEL is a Japanese literal outside the documented unmapped set (#2028)', function () {
    // The bug this replaces: `forKind()` took no locale and carried 54 Japanese
    // literals, so an en/vi preview showed 伝票 / 小計 / お預かり / 割り勘 while
    // the printer translated all of them. Labels on `locked` and `params` rows
    // are system constants the brand cannot author — the R28 rule — so they must
    // come from the SAME catalog the emitter reads, not from a second table
    // here.
    //
    // Asserting on the sample rather than on composed lines is deliberate: the
    // sample's VALUES are legitimately Japanese (店名「ベト屋」, item 「緑茶」, staff
    // 「田中」) and always will be. Only the label side is a promise.
    $allowed = unmappedSampleLabels();
    $cjk = '/[\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}]/u';

    foreach (PrintTemplateKind::cases() as $kind) {
        $sample = SampleSlipData::forKind($kind, 48, 'vi');

        foreach ($sample['params'] as $key => $entry) {
            if (isset($allowed[$key])) {
                continue;
            }

            expect($entry['label'])->not->toMatch(
                $cjk,
                sprintf('%s: params.%s is hardcoded Japanese — read it from a print catalog', $kind->value, $key),
            );
        }

        // #2039 — one kind's paper is Japanese in every locale, so its locked
        // rows are exempt as a KIND. See `japaneseByConstructionKinds()` for why
        // this is not an `unmappedSampleLabels()` entry, and R38 for the pin
        // that keeps it honest.
        if (isset(japaneseByConstructionKinds()[$kind->value])) {
            continue;
        }

        foreach ($sample['locked'] as $id => $rows) {
            if (isset($allowed[$id])) {
                continue;
            }

            foreach ($rows as $row) {
                foreach (['label', 'text'] as $field) {
                    expect((string) ($row[$field] ?? ''))->not->toMatch(
                        $cjk,
                        sprintf('%s: locked.%s.%s is hardcoded Japanese — read it from a print catalog', $kind->value, $id, $field),
                    );
                }
            }
        }
    }
});

it('R30: the locale reaches the sample, so a vi preview is drawn in Vietnamese (#2028)', function () {
    // R29 proves the sample has no Japanese left; this proves the composer
    // actually HANDS it the locale. `compose()` held `$locale` and dropped it on
    // the floor at the `SampleSlipData::forKind` call — one missing argument,
    // and every locked block silently fell back to Japanese.
    $vi = composedLines(PrintTemplateKind::Receipt, 'vi');
    $ja = composedLines(PrintTemplateKind::Receipt, 'ja');

    expect($vi)->toContain('Tam tinh                                  ¥3,300')
        ->and($ja)->toContain('小計                                      ¥3,300');

    // The split banner is the shape the printer draws, not a one-liner: bar,
    // title with the (n/m) suffix, mode line, bar (`emitSplitBanner`).
    expect($vi)->toContain('HOA DON CHIA (1/2)')
        ->and($vi)->toContain('Chia deu - 2 nguoi')
        ->and($ja)->toContain('分割会計 (1/2)')
        ->and($ja)->toContain('均等割 - 2 名')
        ->and($vi)->not->toContain('割り勘 1/2')
        ->and($ja)->not->toContain('割り勘 1/2');
});

/*
 * R34–R36 (#2036) — ba chỗ bản xem trước còn hứa khác tờ giấy, cùng điều luật
 * R28 và cùng họ với #2028.
 *
 * Đánh số tiếp từ R33 chứ không phải R31: khối "unsaved-state" bên dưới đã dùng
 * lại R29–R33 một lần nữa, nên số nhỏ hơn sẽ trùng tên với một bài khác trong
 * CÙNG file và khiến `--filter` chạy nhầm bài.
 */

it('R34: the preview carries the 法人名 line the printer now prints (#2000 bước 6)', function () {
    // `store_organization` vào `store_info.fields` mặc định ở #2000 bước 6, và
    // `StoreInfoBlock` in nó thật. Sample không có ô ấy thì `emitParams` không
    // tìm thấy khoá và bỏ qua field — bản xem trước nuốt một dòng CÓ THẬT, im
    // lặng. Đây là dòng danh tính đứng đầu hoá đơn: 登録番号 T+13 thuộc pháp
    // nhân, nên đúng nó là dòng không được phép thiếu.
    $params = SampleSlipData::forKind(PrintTemplateKind::Receipt, 48, 'ja')['params'];

    expect($params)->toHaveKey('store_organization')
        ->and($params['store_organization']['value'])->not->toBe('');

    // Và nó phải ĐI ĐƯỢC tới bản xem trước, không chỉ tồn tại trong sample.
    expect(composedLines(PrintTemplateKind::Receipt, 'ja'))
        ->toContain($params['store_organization']['value']);
});

it('R35: store lines are drawn BARE, exactly as StoreInfoBlock prints them (#2036)', function () {
    // `StoreInfoBlock::emit()` phát `$ctx->encoder->line($value)` — giá trị
    // trần ở lề trái, không cột nhãn, ở cả ba họ phiếu (bill · docs · shift).
    // Sample gắn nhãn `TEL` cho mỗi `store_phone`: một cột tờ giấy không có, và
    // một phép đo bề rộng sai theo.
    //
    // `row()` cũng phải hiểu dòng không nhãn KHÔNG phải dòng hai cột — căng một
    // nhãn rỗng ra khổ giấy sẽ đẩy cả năm dòng cửa hàng sang mép PHẢI của bản
    // xem trước trong khi máy in đặt chúng ở mép trái.
    $sample = SampleSlipData::forKind(PrintTemplateKind::Receipt, 48, 'ja');
    $lines = composedLines(PrintTemplateKind::Receipt, 'ja');

    $storeFields = ['store_organization', 'store_sub_name', 'store_name', 'store_address', 'store_phone'];

    foreach ($storeFields as $field) {
        expect($sample['params'][$field]['label'])->toBe('', "{$field} phải không nhãn");
        expect($lines)->toContain($sample['params'][$field]['value']);
    }

    expect(collect($lines)->filter(fn (string $l): bool => str_starts_with($l, 'TEL')))->toBeEmpty();
});

it('R36: the debt slip previews ITS OWN money words, not the bill\'s (#2036)', function () {
    // `DocsKindPlans::debtSlipPlan()` đăng ký lại `grand_total` → `emitDebtTotal`
    // ("Tong") và `payments` → `emitDebtPaid` ("Da thanh toan"), ASCII ở mọi
    // locale — phiếu ghi nợ là chứng từ tiếng Việt theo cấu tạo. Map `locked`
    // của sample chỉ rẽ theo kind cho họ shift, nên bản xem trước phiếu nợ mượn
    // nhãn của phiếu bill: 「合計」/「支払済」/「支払方法」 ở ja.
    $bill = PrintLabels::forLocale('ja');

    foreach (['ja', 'en', 'vi'] as $locale) {
        $locked = SampleSlipData::forKind(PrintTemplateKind::DebtSlip, 48, $locale)['locked'];

        expect($locked['grand_total'][0]['label'])->toBe('Tong', "grand_total tại {$locale}")
            // MỘT dòng: `emitDebtPaid` không in phương thức thanh toán.
            ->and($locked['payments'])->toHaveCount(1)
            ->and($locked['payments'][0]['label'])->toBe('Da thanh toan', "payments tại {$locale}")
            ->and($locked['debt_summary'][0]['label'])->toBe('GHI NO', "debt_summary tại {$locale}");

        $lines = composedLines(PrintTemplateKind::DebtSlip, $locale);

        foreach (['Tong', 'Da thanh toan', 'GHI NO'] as $expected) {
            expect(collect($lines)->filter(fn (string $l): bool => str_starts_with($l, $expected)))
                ->not->toBeEmpty("{$locale}: thiếu dòng bắt đầu bằng {$expected}");
        }

        foreach ([$bill->total, $bill->paidAmount, $bill->paymentMethod, 'CON NO'] as $borrowed) {
            expect(collect($lines)->filter(fn (string $l): bool => str_starts_with($l, $borrowed)))
                ->toBeEmpty("{$locale}: còn mượn nhãn phiếu bill «{$borrowed}»");
        }
    }

    // Và họ bill KHÔNG bị kéo theo: `grand_total` của nó vẫn đọc catalog.
    expect(SampleSlipData::forKind(PrintTemplateKind::Receipt, 48, 'ja')['locked']['grand_total'][0]['label'])
        ->toBe($bill->total);
});

/*
 * R37–R38 (#2039) — hoá đơn GTGT, cùng điều luật R28/R36 nhưng HAI nhánh.
 *
 * Đánh số tiếp từ R36. R29–R33 đã bị khối "unsaved-state" bên dưới dùng lại và
 * R34–R36 là của #2036, nên số nhỏ hơn sẽ trùng tên trong CÙNG file.
 */

it('R37: the VAT invoice previews ITS OWN money words, not the bill\'s (#2039)', function () {
    // `DocsKindPlans::vatPlan()` đăng ký lại ba block sang emitter riêng, và cả
    // ba in ASCII ở MỌI locale: `emitVatSubtotal` (:497 "Tam tinh"),
    // `emitVatGrandTotal` (:573 "Tong cong"), `emitVatPaymentMethod` (:623
    // "Hinh thuc TT"). Map `locked` của sample chỉ rẽ theo kind cho họ shift và
    // phiếu nợ, nên hoá đơn GTGT mượn nhãn phiếu bill: 「小計」/「合計」/
    // 「支払方法」 ở ja.
    //
    // Hỏi nhãn ja để đối chứng ở CẢ ba locale: ở `vi` nhãn bill và nhãn hoá đơn
    // trùng chữ ("Tam tinh"), nên chỉ chữ Nhật mới phân biệt được hai nguồn.
    $bill = PrintLabels::forLocale('ja');

    foreach (['ja', 'en', 'vi'] as $locale) {
        $locked = SampleSlipData::forKind(PrintTemplateKind::VatInvoice, 48, $locale)['locked'];

        expect($locked['subtotal'][0]['label'])->toBe('Tam tinh', "subtotal tại {$locale}")
            ->and($locked['grand_total'][0]['label'])->toBe('Tong cong', "grand_total tại {$locale}")
            // MỘT dòng: `emitVatPaymentMethod` in phương thức, không in số đã trả.
            ->and($locked['payments'])->toHaveCount(1)
            ->and($locked['payments'][0]['label'])->toBe('Hinh thuc TT', "payments tại {$locale}")
            // #1224 — `emitVatRegistrationNumber` là thân RỖNG có chủ ý; mã số
            // thuế người bán in trong khối NGUOI BAN. Một dòng ở đây là dòng
            // máy in không vẽ.
            ->and($locked['registration_number'])->toBe([], "registration_number tại {$locale}");

        $lines = composedLines(PrintTemplateKind::VatInvoice, $locale);

        foreach (['Tam tinh', 'Tong cong', 'Hinh thuc TT', 'So HD: '] as $expected) {
            expect(collect($lines)->filter(fn (string $l): bool => str_starts_with($l, $expected)))
                ->not->toBeEmpty("{$locale}: thiếu dòng bắt đầu bằng {$expected}");
        }

        foreach ([$bill->subtotal, $bill->total, $bill->paymentMethod, $bill->paidAmount, '請求書番号'] as $borrowed) {
            expect(collect($lines)->filter(fn (string $l): bool => str_starts_with($l, $borrowed)))
                ->toBeEmpty("{$locale}: còn mượn nhãn phiếu bill «{$borrowed}»");
        }

        // Và khối 登録番号 không được xuất hiện ở đâu trên tờ giấy này.
        expect(collect($lines)->filter(fn (string $l): bool => str_contains($l, 'T1234567890123')))
            ->toBeEmpty("{$locale}: preview vẽ dòng đăng ký mà emitter cố ý không vẽ (#1224)");
    }

    // Họ bill KHÔNG bị kéo theo — ba nhãn ấy vẫn đọc catalog trên phiếu bill.
    $receipt = SampleSlipData::forKind(PrintTemplateKind::Receipt, 48, 'ja')['locked'];

    expect($receipt['subtotal'][0]['label'])->toBe($bill->subtotal)
        ->and($receipt['grand_total'][0]['label'])->toBe($bill->total)
        ->and($receipt['payments'][1]['label'])->toBe($bill->paymentMethod);
});

it('R38: 適格簡易請求書 previews the JAPANESE document at every locale (#2039 / #1493)', function () {
    // Kind này dùng CHUNG plan với `vat_invoice` và chỉ lật cờ `japaneseDoc`,
    // nhưng cờ ấy rẽ vào {@see VatInvoiceJa} — một tập nhãn khác hẳn, không
    // phải bản dịch. Gộp hai kind vào một khối sample là hứa sai cho một trong
    // hai, và trước #2039 cả hai cùng mượn nhãn phiếu bill.
    //
    // Chữ Nhật ở locale `vi` là ĐÚNG ở đây, không phải lỗi #2028: `japaneseDoc`
    // đến từ KIND (#1493). Đây là bài ghim mà `japaneseByConstructionKinds()`
    // trỏ tới — miễn trừ của R29 chỉ hợp lệ chừng nào từng chuỗi còn bị khoá ở
    // đây.
    $kind = PrintTemplateKind::QualifiedSimplifiedInvoice;

    foreach (['ja', 'en', 'vi'] as $locale) {
        $locked = SampleSlipData::forKind($kind, 48, $locale)['locked'];

        // `subtotal` HAI dòng: 小計 rồi dòng điều chỉnh ròng — kind này không có
        // block `service_charge`/`discounts`, nên đó là chỗ DUY NHẤT phí phục vụ
        // xuất hiện ({@see VatInvoiceJa::subtotal}).
        expect($locked['subtotal'])->toHaveCount(2)
            ->and($locked['subtotal'][0]['label'])->toBe(' 小計', "subtotal tại {$locale}")
            ->and($locked['subtotal'][1]['label'])->toBe(' サービス料', "adjustment tại {$locale}")
            ->and($locked['grand_total'][0]['label'])->toBe(' 合計', "grand_total tại {$locale}")
            // Một dòng CHỮ, không phải hàng hai cột: `VatInvoiceJa::payment`.
            ->and($locked['payments'])->toHaveCount(1)
            ->and($locked['payments'][0]['text'])->toBe(' お支払: cash', "payments tại {$locale}")
            ->and($locked['registration_number'])->toBe([], "registration_number tại {$locale}");

        $lines = composedLines($kind, $locale);

        foreach ([' 小計', ' サービス料', ' 合計', ' お支払: cash', 'No.HN1-'] as $expected) {
            expect(collect($lines)->filter(fn (string $l): bool => str_starts_with($l, $expected)))
                ->not->toBeEmpty("{$locale}: thiếu dòng bắt đầu bằng «{$expected}»");
        }

        // Không được mượn NGƯỢC LẠI nhánh Việt của cùng plan.
        foreach (['Tam tinh', 'Tong cong', 'Hinh thuc TT', 'So HD: '] as $viWord) {
            expect(collect($lines)->filter(fn (string $l): bool => str_starts_with($l, $viWord)))
                ->toBeEmpty("{$locale}: 適格簡易請求書 mượn chữ của hoá đơn GTGT Việt «{$viWord}»");
        }
    }

    // …và hoá đơn GTGT Việt không mượn ngược lại chữ Nhật, kể cả ở locale ja.
    $viLines = composedLines(PrintTemplateKind::VatInvoice, 'ja');

    foreach (['小計', '合計', 'お支払'] as $jaWord) {
        expect(collect($viLines)->filter(fn (string $l): bool => str_contains($l, $jaWord)))
            ->toBeEmpty("vat_invoice/ja mượn chữ của chứng từ Nhật «{$jaWord}»");
    }
});

/*
 * R39 (#2045 mục 2) — biên bản huỷ. Đánh số tiếp từ R38: R29–R33 đã bị khối
 * "unsaved-state" bên dưới dùng lại, R34–R36 là của #2036, R37–R38 của #2039.
 *
 * Mục 1 (`invoice_number`/`discounts` là khối `locked` mặc định bật mà họ bill
 * chưa từng có emitter) và mục 3 (dấu `※` trên dòng `8%対象`, chặn bởi #2035)
 * CỐ Ý không đụng ở đây.
 */

it('R39: the void notice previews the line its OWN emitter prints (#2045)', function () {
    // `DocsKindPlans::voidNoticePlan()` (:128) đăng ký `invoice_number` sang
    // `emitVoidInvoiceNumber` (:688), và emitter ấy phát MỘT lệnh duy nhất:
    // `line('So HD bi huy: '.$invoiceNo)` — ASCII ở mọi locale. Map `locked`
    // của sample chỉ rẽ theo kind cho họ shift, phiếu nợ và hai kind VAT, nên
    // biên bản huỷ mượn hàng của họ bill và bản xem trước vẽ 「請求書番号」.
    //
    // Hai chỗ sai, và chỗ thứ hai là chỗ R28 sinh ra để chặn: hàng mượn là hàng
    // HAI CỘT (`label` + `value`), nên preview đo một dòng có 22 cột trống ở
    // giữa trong khi tờ giấy chỉ có một dòng chữ liền.
    $kind = PrintTemplateKind::VoidNotice;

    foreach (['ja', 'en', 'vi'] as $locale) {
        $rows = SampleSlipData::forKind($kind, 48, $locale)['locked']['invoice_number'];

        expect($rows)->toHaveCount(1, "invoice_number tại {$locale}")
            ->and($rows[0]['text'] ?? null)->toBe(
                'So HD bi huy: HN1-202607-00042',
                "invoice_number tại {$locale}",
            )
            // MỘT dòng chữ, không phải hàng hai cột — nếu quay lại `label`/
            // `value` thì chuỗi đúng vẫn vẽ ra hình dạng sai.
            ->and($rows[0])->not->toHaveKey('label', "invoice_number tại {$locale} phải là dòng chữ liền");

        $lines = composedLines($kind, $locale);

        expect(collect($lines)->filter(fn (string $l): bool => $l === 'So HD bi huy: HN1-202607-00042'))
            ->not->toBeEmpty("{$locale}: thiếu dòng «So HD bi huy: …» đúng như emitter vẽ");

        expect(collect($lines)->filter(fn (string $l): bool => str_contains($l, '請求書番号')))
            ->toBeEmpty("{$locale}: biên bản huỷ còn mượn nhãn 「請求書番号」 của họ bill");
    }

    // Họ bill KHÔNG bị kéo theo — chuỗi của biên bản huỷ không được rò sang.
    // Cố ý KHÔNG ghim chuỗi mà phiếu bill đang hiện: hàng `invoice_number` của
    // họ bill là một dòng MA (không emitter nào vẽ, #2045 mục 1) và số phận của
    // nó là quyết định nghiệp vụ, không phải việc của rào này.
    foreach ([PrintTemplateKind::Receipt, PrintTemplateKind::VatInvoice] as $other) {
        expect(collect(composedLines($other, 'vi'))->filter(
            fn (string $l): bool => str_contains($l, 'So HD bi huy'),
        ))->toBeEmpty("{$other->value} mượn chữ của biên bản huỷ");
    }
});

/*
 * R43 (#2045 đợt hai) — hai dòng nữa của biên bản huỷ, cùng điều luật R39.
 * Đánh số tiếp từ R42 (#2071).
 */

it('R44: debt_slip issued_at đúng chuỗi emitter vẽ — ngày trái + #code phải (#2286)', function () {
    // `emitDebtIssuedAt` in `Y/m/d H:i` căn trái và `#` + hậu tố mã đơn căn phải
    // trên CÙNG một dòng. Sample cũ mượn mốc thời gian trần của họ bill.
    $kind = PrintTemplateKind::DebtSlip;
    $issuedAt = '2026/07/20 14:32';
    $code = '#004';

    foreach (['ja', 'en', 'vi'] as $locale) {
        foreach ([32, 48] as $columns) {
            $issued = SampleSlipData::forKind($kind, $columns, $locale)['locked']['issued_at'];
            $expected = Layout::padRight($issuedAt, max($columns - Layout::runeLength($code), 1)).$code;

            expect($issued)->toHaveCount(1, "issued_at tại {$locale}/{$columns}")
                ->and($issued[0]['text'] ?? null)->toBe($expected, "issued_at tại {$locale}/{$columns}");

            $lines = composedLines($kind, $locale, $columns);

            expect(collect($lines)->contains($expected))
                ->toBeTrue("{$locale}/{$columns}: thiếu dòng ngày+#code đúng như emitter vẽ");
        }
    }
});

it('R43: void_marker + issued_at của biên bản huỷ đúng chuỗi emitter vẽ (#2045)', function () {
    // `emitVoidMarker` in headline ASCII đậm canh giữa "BIEN BAN HUY HOA DON"
    // ở MỌI locale; `emitVoidVoidedAt` in "Thoi diem huy: {ts}". Sample cũ vẽ
    // 「*** 取消 ***」 và một mốc thời gian trần — hai dòng preview hứa mà máy
    // in không bao giờ vẽ.
    $kind = PrintTemplateKind::VoidNotice;

    foreach (['ja', 'en', 'vi'] as $locale) {
        $rows = SampleSlipData::forKind($kind, 48, $locale)['locked'];

        $marker = $rows['void_marker'];
        expect($marker)->toHaveCount(1, "void_marker tại {$locale}")
            ->and($marker[0]['text'] ?? null)->toBe('BIEN BAN HUY HOA DON', "void_marker tại {$locale}")
            ->and($marker[0]['align'] ?? null)->toBe(SlipComposer::ALIGN_CENTER, "void_marker tại {$locale}")
            ->and($marker[0]['bold'] ?? null)->toBeTrue("void_marker tại {$locale}");

        $issued = $rows['issued_at'];
        expect($issued)->toHaveCount(1, "issued_at tại {$locale}")
            ->and($issued[0]['text'] ?? null)->toBe('Thoi diem huy: 2026/07/20 14:32', "issued_at tại {$locale}");

        $lines = composedLines($kind, $locale);

        expect(collect($lines)->filter(fn (string $l): bool => trim($l) === 'BIEN BAN HUY HOA DON'))
            ->not->toBeEmpty("{$locale}: thiếu headline «BIEN BAN HUY HOA DON» đúng như emitter vẽ");

        expect(collect($lines)->filter(fn (string $l): bool => str_contains($l, '取消')))
            ->toBeEmpty("{$locale}: biên bản huỷ còn vẽ dấu 「取消」 mà không emitter nào in");

        expect(collect($lines)->filter(fn (string $l): bool => str_contains($l, 'Thoi diem huy: 2026/07/20 14:32')))
            ->not->toBeEmpty("{$locale}: thiếu dòng «Thoi diem huy: …» đúng như emitter vẽ");
    }
});

// ─── the UNSAVED-state preview (TR-32, T4.3) ──────────────────────────────
//
// The GET endpoints render what is STORED. An editor's whole job is the state
// that is not stored yet, so admin-web kept its own TypeScript renderer to draw
// it — a second implementation of the same layout rules, and the preview a
// brand approves from. These tests exist so that copy can be deleted: what the
// editor holds must be renderable by the SAME renderer the printer uses.

/**
 * The system default for a kind with one authored footer line replaced, so the
 * assertion can tell "the definition I sent" from "the definition on file".
 *
 * @return array<string, mixed>
 */
function previewDefinitionWithFooter(string $text, PrintTemplateKind $kind = PrintTemplateKind::Receipt): array
{
    $definition = app(SystemTemplateDefaults::class)->forKind($kind);

    foreach ($definition['blocks'] as $i => $block) {
        if (($block['id'] ?? null) === 'footer_text') {
            $definition['blocks'][$i] = array_replace($block, [
                'enabled' => true,
                'fallback' => true,
                'i18n' => ['ja' => $text, 'en' => $text, 'vi' => $text],
            ]);
        }
    }

    return $definition;
}

it('R29: POSTing a definition renders THAT definition, not the one on file', function () {
    // The bug this forbids: the editor shows unsaved edits, the server renders
    // the saved draft, and the two disagree without saying so.
    $user = previewActor('org-admin', $this->orgId);

    app(TemplateVersionService::class)->saveDraft(
        PrintTemplateKind::Receipt,
        PrintTemplateScope::Brand,
        $this->brand,
        null,
        previewDefinitionWithFooter('SAVED DRAFT LINE'),
    );

    $response = $this->actingAs($user)->post(
        "/api/v1/hq/{$this->brand->slug}/print-templates/receipt/preview?paper=80mm&locale=ja",
        ['definition' => previewDefinitionWithFooter('UNSAVED EDITOR LINE')],
    );

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('image/svg+xml');
    expect($response->getContent())
        ->toContain('UNSAVED EDITOR LINE')
        ->and($response->getContent())->not->toContain('SAVED DRAFT LINE');
});

it('R30: previewing the editor state stores NOTHING', function () {
    /*
     * Why this is worth a test rather than an assumption: the obvious way to
     * preview unsaved work is to save it first, and that bumps the draft's
     * optimistic-lock token — which 409s the second tab (TR-09) and rewrites
     * history the author never asked to write. A preview is a read.
     */
    $user = previewActor('org-admin', $this->orgId);
    $before = PrintTemplate::query()->count();

    $this->actingAs($user)->post(
        "/api/v1/hq/{$this->brand->slug}/print-templates/receipt/preview",
        ['definition' => previewDefinitionWithFooter('EPHEMERAL')],
    )->assertOk();

    expect(PrintTemplate::query()->count())->toBe($before);
});

it('R31: a malformed posted definition is a 422, not a blank slip', function () {
    // A definition with no `blocks` composes to nothing (R7). Rendering that as
    // an empty preview would read as "your template prints nothing" — the one
    // answer a broken request must not be allowed to give.
    $user = previewActor('org-admin', $this->orgId);
    $url = "/api/v1/hq/{$this->brand->slug}/print-templates/receipt/preview";

    $this->actingAs($user)->postJson($url, ['definition' => ['schema' => 'tempo.print.v1']])
        ->assertStatus(422);

    $this->actingAs($user)->postJson($url, ['definition' => 'not-a-document'])
        ->assertStatus(422);

    // Bounded work: a preview is a rendering job, not an open-ended one.
    $this->actingAs($user)->postJson($url, [
        'definition' => ['blocks' => array_fill(0, 201, ['id' => 'footer_text', 'type' => 'text'])],
    ])->assertStatus(422);
});

it('R32: the shop preview filters posted edits through the brand allow-list', function () {
    /*
     * The shop editor holds the whole RESOLVED slip, so an edit to a field the
     * brand never delegated can reach this endpoint. Publish would strip it
     * (TR-03/TR-04). If the preview showed it anyway, the shop would approve a
     * slip its own publish cannot produce — the exact disagreement this task
     * exists to remove, rebuilt one layer down.
     */
    $manager = previewActor('shop-manager', $this->orgId);

    // Brand delegates the greeting and nothing else.
    app(TemplateVersionService::class)->saveDraft(
        PrintTemplateKind::Receipt,
        PrintTemplateScope::Brand,
        $this->brand,
        null,
        app(SystemTemplateDefaults::class)->forKind(PrintTemplateKind::Receipt),
        ['greeting'],
    );
    app(TemplateVersionService::class)->publish(
        PrintTemplateKind::Receipt,
        PrintTemplateScope::Brand,
        $this->brand,
        null,
    );

    $posted = previewDefinitionWithFooter('SHOP EDIT OUTSIDE THE ALLOW LIST');
    foreach ($posted['blocks'] as $i => $block) {
        if (($block['id'] ?? null) === 'greeting') {
            $posted['blocks'][$i] = array_replace($block, [
                'enabled' => true,
                'fallback' => true,
                'i18n' => ['ja' => 'SHOP EDIT INSIDE THE ALLOW LIST'],
            ]);
        }
    }

    $response = $this->actingAs($manager)->post(
        "/api/v1/shops/{$this->branch->slug}/print-templates/receipt/preview?locale=ja",
        ['definition' => $posted],
    );

    $response->assertOk();
    expect($response->getContent())
        ->toContain('SHOP EDIT INSIDE THE ALLOW LIST')
        ->and($response->getContent())->not->toContain('SHOP EDIT OUTSIDE THE ALLOW LIST');
});

it('R33: POST is gated by the same permission as GET (TR-37)', function () {
    // A new verb is a new door. It gets the same lock.
    $cashier = previewActor('shop-staff', $this->orgId);
    $body = ['definition' => previewDefinitionWithFooter('x')];

    $this->actingAs($cashier)
        ->postJson("/api/v1/hq/{$this->brand->slug}/print-templates/receipt/preview", $body)
        ->assertForbidden();

    $this->actingAs($cashier)
        ->postJson("/api/v1/shops/{$this->branch->slug}/print-templates/receipt/preview", $body)
        ->assertForbidden();
});

/*
 * R39–R41 (#2045) — ba dòng MA của bản xem trước, cùng điều luật R28.
 *
 * Đánh số tiếp từ R38. R29–R33 đã bị khối "unsaved-state" ở trên dùng lại một
 * lần nữa, nên số nhỏ hơn sẽ trùng tên với một bài khác trong CÙNG file và làm
 * `--filter` chạy nhầm bài.
 *
 * Khác #2028/#2036/#2039 ở chỗ nặng hơn "nhãn sai": hai trong ba ca dưới đây là
 * dòng mà KHÔNG máy in nào vẽ — brand đo bề rộng cột theo một khối không tồn
 * tại.
 */

it('R39: biên bản huỷ xem trước ĐÚNG chữ emitter vẽ, không phải 請求書番号 (#2045)', function () {
    // `DocsKindPlans::emitVoidInvoiceNumber` in `So HD bi huy: {n}` — ASCII ở
    // MỌI locale, như mọi dòng khác của biên bản huỷ (chứng từ Việt theo cấu
    // tạo). Sample lấy dòng `invoice_number` của họ bill: 「請求書番号」 — vừa
    // sai chữ, vừa sai bề rộng (4 chữ Nhật = 8 cột).
    foreach (['ja', 'en', 'vi'] as $locale) {
        $rows = SampleSlipData::forKind(PrintTemplateKind::VoidNotice, 48, $locale)['locked']['invoice_number'];

        expect($rows)->toHaveCount(1, "invoice_number tại {$locale}");

        $text = (string) ($rows[0]['text'] ?? $rows[0]['label'] ?? '');

        expect($text)->toStartWith('So HD bi huy: ');

        $lines = composedLines(PrintTemplateKind::VoidNotice, $locale);

        expect(collect($lines)->filter(fn (string $l): bool => str_starts_with($l, 'So HD bi huy: ')))
            ->not->toBeEmpty("{$locale}: thiếu dòng số hoá đơn bị huỷ");
        expect(collect($lines)->filter(fn (string $l): bool => str_contains($l, '請求書番号')))
            ->toBeEmpty("{$locale}: còn mượn nhãn 請求書番号 của họ bill");
    }
});

it('R40: bản xem trước KHÔNG vẽ khối nào đang nằm trong renderable_debt (#2045)', function () {
    // `print_blocks.renderable_debt` là danh sách khối CÓ trong catalog mà
    // KHÔNG renderer nào vẽ (#1949), và `CatalogRenderableRatchetTest` cưỡng
    // chế nó theo CẢ HAI chiều — thêm nợ mới thì đỏ, trả nợ mà quên hạ cũng
    // đỏ. Nên nó là phép đo đúng cho câu hỏi "khối này có ra giấy không", chứ
    // không phải một danh sách chép tay thứ hai.
    //
    // Hai ca của #2045: `receipt.discounts` và `receipt.invoice_number` là
    // block `locked`, mặc định BẬT, chưa từng có emitter trong
    // `BillKindPlans::BLOCKS`. Sample vẫn cấp dòng cho cả hai ⇒ brand thiết kế
    // mẫu nhìn thấy hai dòng sẽ không bao giờ ra giấy.
    //
    // Buộc dòng biến mất KHI VÀ CHỈ KHI khối còn nợ, chứ không xoá cứng: ngày
    // ai đó viết emitter, nợ hạ và dòng tự trở lại.
    /** @var array<string, list<string>|string> $debt */
    $debt = config('print_blocks.renderable_debt', []);

    foreach (PrintTemplateKind::cases() as $kind) {
        $owed = $debt[$kind->value] ?? [];

        // `'__NO_PLAN__'` (kitchen) là nợ ở tầng khác — chưa có plan nào bên
        // PHP — nên không có phép đo nào ở đây để áp.
        if (! is_array($owed)) {
            continue;
        }

        foreach (['ja', 'en', 'vi'] as $locale) {
            $locked = SampleSlipData::forKind($kind, 48, $locale)['locked'];

            foreach ($owed as $block) {
                expect($locked[$block] ?? [])->toBe(
                    [],
                    sprintf('%s/%s: sample vẽ «%s» mà không emitter nào vẽ', $kind->value, $locale, $block),
                );
            }
        }
    }
});

/**
 * Bản tổng hợp thuế dựng từ CHÍNH bộ số của sample — {@see SampleSlipData::TAX_BREAKDOWN}.
 */
function taxBreakdownSampleSnapshot(): ReceiptTaxSummary
{
    return ReceiptTaxSummary::fromBreakdown(['by_rate' => SampleSlipData::TAX_BREAKDOWN]);
}

/**
 * Dòng mà `BillKindPlans::emitTaxBreakdown` THẬT SỰ vẽ, đọc lại thành UTF-8.
 *
 * Chạy `prologue` thật rồi cắt theo ĐỘ DÀI sau prologue: byte của prologue là
 * tiền đề, không phải sản phẩm của block đang đo, và cắt theo độ dài thì mọi
 * lệnh prologue thêm về sau tự nằm ngoài phép đo.
 *
 * Encoder phát Shift_JIS, nơi ¥ LÀ 0x5C, nên đọc ngược ra UTF-8 sẽ cho dấu `\`.
 * Đổi lại thành ¥ để so được với bản xem trước (thuần UTF-8) — không ký tự nào
 * khác trong bộ số mẫu là `\`.
 *
 * @return list<string>
 */
function renderedTaxBreakdownLines(string $locale, int $columns, ReceiptTaxSummary $snapshot): array
{
    $data = new PrintRenderData(
        kind: 'receipt',
        config: new PrintJobConfig(currency: '¥'),
        order: new PrintRenderOrder(orderCode: 'HCM-2026-A1B2', orderType: 'dine_in'),
    );

    $encoder = new Escpos;
    $ctx = new PrintRenderContext(
        encoder: $encoder,
        definition: ['blocks' => [['id' => 'tax_breakdown']]],
        data: $data,
        config: $data->config,
        locale: $locale,
        width: $columns,
        japaneseDoc: false,
        labels: PrintLabels::forLocale($locale),
        tax: TaxLabels::forLocale($locale),
        taxBreakdown: $snapshot,
    );

    $plan = app(PrintKindRegistry::class)->planFor('receipt');
    ($plan->prologue)($ctx);
    $prologueLength = $encoder->length();

    $plan->emitters['tax_breakdown']($ctx, ['id' => 'tax_breakdown']);

    $utf8 = (string) mb_convert_encoding(substr($encoder->bytes(), $prologueLength), 'UTF-8', 'SJIS');

    return array_values(array_filter(
        array_map(
            static fn (string $l): string => str_replace('\\', '¥', rtrim($l, "\r")),
            explode("\n", $utf8),
        ),
        static fn (string $l): bool => trim($l) !== '',
    ));
}

it('R41: khối thuế xem trước là ĐÚNG byte emitter vẽ, ở cả 32 và 48 cột (#2045 mục 3)', function () {
    // Trước #2035 dòng này mang chuỗi tự chế 「内税」 + dấu ※ mà emitter không in,
    // và nó lọt R2 CHỈ vì chuỗi tự chế ngắn hơn chuỗi thật — một lỗi tài liệu
    // hoá dữ liệu che một lỗi dàn trang thật.
    //
    // So thẳng với đầu ra của renderer thay vì gõ lại chuỗi kỳ vọng: gõ lại là
    // dựng bản thứ hai của cùng bố cục, đúng thứ mà cả họ bài R28 tồn tại để
    // xoá bỏ.
    $snapshot = taxBreakdownSampleSnapshot();

    foreach (['ja', 'en', 'vi'] as $locale) {
        foreach ([32, 48] as $columns) {
            $sample = SampleSlipData::forKind(PrintTemplateKind::Receipt, $columns, $locale);

            $preview = array_map(
                fn (array $l): string => $l['text'],
                app(SlipComposer::class)->compose(
                    ['blocks' => [['id' => 'tax_breakdown', 'type' => 'locked', 'enabled' => true]]],
                    PrintTemplateKind::Receipt,
                    $locale,
                    $columns,
                    $sample,
                ),
            );

            expect($preview)->toBe(
                renderedTaxBreakdownLines($locale, $columns, $snapshot),
                "tax_breakdown {$locale}/{$columns}",
            );

            // …và dấu ※ không được mọc lại: nó thuộc dòng MÓN và chú thích chân
            // phiếu, `BillKindPlans::rateBlockLine` cố ý không in.
            expect(implode("\n", $preview))->not->toContain('※');
        }
    }
});

/**
 * Dòng mà `BillKindPlans::emitDiscounts` THẬT SỰ vẽ, đọc lại thành UTF-8 —
 * cùng khuôn với {@see renderedTaxBreakdownLines} (R41), cùng lý do: bản xem
 * trước so với ĐẦU RA của emitter, không so với một bản gõ lại của bố cục.
 *
 * @param  list<PrintRenderDiscount>  $rows
 * @return list<string>
 */
function renderedDiscountLines(string $locale, int $columns, array $rows): array
{
    $data = new PrintRenderData(
        kind: 'receipt',
        config: new PrintJobConfig(currency: '¥'),
        order: new PrintRenderOrder(orderCode: 'HCM-2026-A1B2', orderType: 'dine_in', discounts: $rows),
    );

    $encoder = new Escpos;
    $ctx = new PrintRenderContext(
        encoder: $encoder,
        definition: ['blocks' => [['id' => 'discounts']]],
        data: $data,
        config: $data->config,
        locale: $locale,
        width: $columns,
        japaneseDoc: false,
        labels: PrintLabels::forLocale($locale),
        tax: TaxLabels::forLocale($locale),
    );

    $plan = app(PrintKindRegistry::class)->planFor('receipt');
    ($plan->prologue)($ctx);
    $prologueLength = $encoder->length();

    $plan->emitters['discounts']($ctx, ['id' => 'discounts']);

    $utf8 = (string) mb_convert_encoding(substr($encoder->bytes(), $prologueLength), 'UTF-8', 'SJIS');

    return array_values(array_filter(
        array_map(
            static fn (string $l): string => str_replace('\\', '¥', rtrim($l, "\r")),
            explode("\n", $utf8),
        ),
        static fn (string $l): bool => trim($l) !== '',
    ));
}

it('R42: khối giảm giá xem trước là ĐÚNG byte emitter vẽ — mỗi dòng sổ một dòng, kèm nhóm mức (#2071)', function () {
    // Khối `discounts` vừa có emitter (#2071 trả nợ #2045 mục 1): mỗi dòng
    // `order_conditions` (type='discount') một dòng giấy, nhãn từ catalog
    // (`PrintLabels::$discount`) + suffix nhóm mức, số tiền NGUYÊN VĂN dấu âm.
    // Sample của bản xem trước phải là đúng các dòng đó — bản xem trước không
    // được hứa khác giấy (họ luật R28), và trước #2071 dòng sample còn mượn
    // nhãn 精算 (`discountGeneric`) với một con số TỔNG mà emitter không in.
    $rows = [
        new PrintRenderDiscount(rate: 8.0, amount: -9),
        new PrintRenderDiscount(rate: 10.0, amount: -91),
    ];

    foreach (['ja', 'en', 'vi'] as $locale) {
        foreach ([32, 48] as $columns) {
            $sample = SampleSlipData::forKind(PrintTemplateKind::Receipt, $columns, $locale);

            $preview = array_map(
                fn (array $l): string => $l['text'],
                app(SlipComposer::class)->compose(
                    ['blocks' => [['id' => 'discounts', 'type' => 'locked', 'enabled' => true]]],
                    PrintTemplateKind::Receipt,
                    $locale,
                    $columns,
                    $sample,
                ),
            );

            expect($preview)->toBe(
                renderedDiscountLines($locale, $columns, $rows),
                "discounts {$locale}/{$columns}",
            );

            // Hai dòng, không phải một dòng tổng — đó là toàn bộ điểm của #2071.
            expect($preview)->toHaveCount(2);
        }
    }
});
