<?php

declare(strict_types=1);

/**
 * #1181 — the Cloud catalog must describe the slip the shop is ALREADY printing.
 *
 * The workstation's hard-coded `Format*` functions are the source of truth here:
 * their output is the paper in the till, and the TR-40 migration gate pins the
 * workstation's own layer 0 against them byte-for-byte across 117 combinations.
 * Cloud's layer 0 had never been held to that standard, and all thirteen kinds
 * had drifted from it.
 *
 * That drift was a BLOCKER rather than a tidy-up because of where Cloud's
 * default is consumed: the admin UI (#1171 M4) shows a brand the Cloud default
 * and lets it edit from there, so pressing Publish once would have pushed
 * Cloud's idea of a receipt over the shop's real one — the exact outcome
 * plan-053 promises can never happen.
 *
 * Two layers of proof:
 *
 *   - HERE (PHP): the committed cross-repo fixture still matches the live
 *     config, and each individual gap stays closed with a named assertion, so a
 *     regression says WHICH rule broke instead of "a hash moved".
 *   - `workstation/internal/service/print_cloud_parity_test.go`: the same
 *     fixture rendered by the REAL Go renderer, hashed against
 *     `testdata/print_golden.json`. That is the assertion that actually proves
 *     the bytes; this file is what keeps its input honest.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\PrintTemplate;
use App\Services\Print\BlockCatalog;
use App\Services\Print\DefinitionNormalizer;
use App\Services\Print\Enums\PrintTemplateKind;
use App\Services\Print\Enums\PrintTemplateScope;
use App\Services\Print\Enums\PrintTemplateStatus;
use App\Services\Print\SystemTemplateDefaults;
use App\Services\Print\TemplateResolver;
use Illuminate\Support\Str;

/** Path of the fixture the Go parity test reads. */
function cloudParityFixturePath(): string
{
    return base_path('../workstation/internal/service/testdata/cloud_print_templates_default.json');
}

/** Path of the workstation's own embedded layer 0. */
function workstationDefaultsPath(): string
{
    return base_path('../workstation/internal/service/print_templates_default.json');
}

/** @return array<string, mixed> */
function readJsonFixture(string $path): array
{
    expect(file_exists($path))->toBeTrue("missing fixture: {$path}");
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    expect($decoded)->toBeArray();

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

/** Ordered block ids of one kind's system default. */
function defaultBlockIds(PrintTemplateKind|string $kind): array
{
    $definition = app(SystemTemplateDefaults::class)->forKind($kind);

    return array_map(fn (array $b): string => (string) $b['id'], $definition['blocks']);
}

/** One block of one kind's system default. */
function defaultBlock(PrintTemplateKind|string $kind, string $id): ?array
{
    foreach (app(SystemTemplateDefaults::class)->forKind($kind)['blocks'] as $block) {
        if (($block['id'] ?? null) === $id) {
            return $block;
        }
    }

    return null;
}

/** Assert $before appears before $after in the kind's block order. */
function expectOrder(PrintTemplateKind|string $kind, string $before, string $after): void
{
    $name = $kind instanceof PrintTemplateKind ? $kind->value : $kind;
    $ids = defaultBlockIds($kind);
    $i = array_search($before, $ids, true);
    $j = array_search($after, $ids, true);

    expect($i)->not->toBeFalse("{$name} has no `{$before}` block");
    expect($j)->not->toBeFalse("{$name} has no `{$after}` block");
    expect($i)->toBeLessThan($j, "expected `{$before}` above `{$after}` on {$name}");
}

// ─── the cross-repo fixture ───────────────────────────────────────────────

/*
 * Cross-REPO gate: this suite reads golden fixtures out of the
 * `workstation/` IN-TREE (#2306, trước là submodule anh em). Trên CI cây là
 * separate PRIVATE repos that GITHUB_TOKEN cannot clone, so the files are
 * simply absent — skip the whole file loudly there instead of reporting a
 * parity failure that is really a missing checkout. Wherever the submodule
 * IS present (every dev machine, and CI once a cross-repo credential
 * exists) the parity contract is enforced exactly as before.
 */
beforeEach(function (): void {
    if (! is_dir(base_path('../workstation/internal/service'))) {
        test()->markTestSkipped('nguồn workstation vắng mặt trong cây (in-tree từ #2306) — cổng parity bị bỏ qua');
    }
});

it('P1: the committed Go parity fixture still matches the live Cloud config', function () {
    /*
     * The Go gate reads a COMMITTED export, not the running config, because Go
     * cannot boot Laravel. That makes the export a cache, and a stale cache
     * would let the catalog drift while the gate stayed green — the failure
     * mode this test exists to make impossible.
     *
     * Regenerate with:
     *   php artisan print-templates:export-defaults \
     *     --out=../workstation/internal/service/testdata/cloud_print_templates_default.json
     */
    $fixture = readJsonFixture(cloudParityFixturePath());
    $live = app(SystemTemplateDefaults::class)->all();

    expect($fixture)->toEqual(
        json_decode((string) json_encode($live), true),
        'The exported parity fixture is stale — re-run `php artisan print-templates:export-defaults`.',
    );
});

it('P2: Cloud block order matches the workstation layer 0 for every kind (TR-40)', function () {
    $go = readJsonFixture(workstationDefaultsPath());

    foreach (PrintTemplateKind::cases() as $kind) {
        expect($go)->toHaveKey($kind->value);

        $goIds = array_map(fn (array $b): string => (string) $b['id'], $go[$kind->value]['blocks']);

        expect(defaultBlockIds($kind))->toBe(
            $goIds,
            "block order for `{$kind->value}` disagrees with the workstation's formatter",
        );
    }
});

it('P3: every kind covers exactly the 13 formatters, both ways', function () {
    $go = readJsonFixture(workstationDefaultsPath());

    $cloudKinds = array_map(fn (PrintTemplateKind $k): string => $k->value, PrintTemplateKind::cases());
    $goKinds = array_keys($go);
    sort($cloudKinds);
    sort($goKinds);

    expect($cloudKinds)->toBe($goKinds);
});

// ─── gap 1 (#1042): the money footer order ────────────────────────────────

it('G1: the per-rate 内税 breakdown sits BELOW the grand total (#1042)', function () {
    /*
     * The catalog listed `tax_legend, subtotal, …, tax_breakdown, grand_total`.
     * The slip prints subtotal → service charge → TOTAL → per-rate 内税 →
     * legend → 登録番号: the tax is ALREADY inside the total, so it reads as an
     * informational split underneath it, indented three columns.
     *
     * Publishing the catalog's order would have moved the tax block above the
     * total on every receipt in the fleet.
     */
    foreach (['receipt', 'runner', 'delta_qr', 'remaining', 'red_invoice', 'kitchen'] as $kind) {
        if ($kind !== 'kitchen') {
            expectOrder($kind, 'grand_total', 'tax_breakdown');
        }
        expectOrder($kind, 'tax_breakdown', 'tax_legend');
        expectOrder($kind, 'tax_legend', 'registration_number');
    }
});

it('G1b: subtotal and service charge stay ABOVE the grand total', function () {
    foreach (['receipt', 'runner', 'remaining', 'red_invoice'] as $kind) {
        expectOrder($kind, 'subtotal', 'grand_total');
        expectOrder($kind, 'service_charge', 'grand_total');
    }
});

// ─── gap 2: `remaining` on the paid ticket ────────────────────────────────

it('G2: `remaining` is present on the receipt — a partial settle prints 残額', function () {
    expect(defaultBlockIds(PrintTemplateKind::Receipt))->toContain('remaining');
    expect(defaultBlockIds(PrintTemplateKind::RedInvoice))->toContain('remaining');

    // It follows the tender rows: you cannot state what is left before saying
    // what was taken.
    expectOrder('receipt', 'payments', 'remaining');
    expectOrder('receipt', 'change_due', 'remaining');
});

// ─── gap 3: the EN column header ──────────────────────────────────────────

it('G3: the item column header matches printLabels, wording included', function () {
    // The point is not the literal string — it is that the CATALOG and the Go
    // labels agree. They drifted once ("Amount" here vs "Price" on the slip),
    // and a brand's first publish would have silently reworded every receipt.
    //
    // The wording moved to the shop's own slip: the money column is headed
    // 合計 / Total / Tong, matching `printLabels.Price` on the Go side.
    foreach (['receipt', 'kitchen', 'runner', 'delta_qr', 'remaining', 'red_invoice'] as $kind) {
        $header = defaultBlock($kind, 'column_header');

        expect($header)->not->toBeNull("{$kind} has no column_header");
        expect($header['i18n']['en'])->toBe('Item                        Total');
        expect($header['i18n']['ja'])->toBe('商品                          合計');
        expect($header['i18n']['vi'])->toBe('San pham                      Tong');
    }
});

it('G3b: the debt slip keeps its Vietnamese column header in every locale', function () {
    $header = defaultBlock(PrintTemplateKind::DebtSlip, 'column_header');

    foreach (['ja', 'en', 'vi'] as $locale) {
        expect($header['i18n'][$locale])->toBe('San pham                Thanh tien');
    }
});

// ─── gap 4: the reprint marker's position ─────────────────────────────────

it('G4: the reprint marker prints near the TOP on vat_invoice and debt_slip', function () {
    // 「Bản in #N」 is a warning about the document in the reader's hand, so it
    // belongs where the eye lands — under the store sub-name — not after the
    // money at the very end.
    expectOrder('vat_invoice', 'reprint_marker', 'invoice_number');
    // `issued_at` từng là mốc thứ hai ở đây, nhưng nó đã bị gỡ khỏi kind này:
    // ngày tháng in CHUNG MỘT DÒNG với số hoá đơn (`VatInvoiceJa::invoiceNumber`),
    // nên một khối `issued_at` riêng là nút bật/tắt không điều khiển gì.
    // Dòng trên đã ghim đúng cùng ý bằng cái mốc còn thật.
    expectOrder('debt_slip', 'reprint_marker', 'issued_at');
    expectOrder('debt_slip', 'reprint_marker', 'order_meta');
});

it('G4b: the bill family keeps its reprint marker at the END', function () {
    foreach (['receipt', 'runner', 'delta_qr', 'remaining', 'red_invoice'] as $kind) {
        expectOrder($kind, 'grand_total', 'reprint_marker');
    }
});

it('G4c: kinds whose formatter prints no reprint marker do not declare one', function () {
    // Declaring it would have made the honest definition unpublishable — the
    // catalog listed it as REQUIRED on kinds that never printed it.
    foreach (['kitchen', 'void_notice', 'table_paid', 'shift_open', 'shift_report', 'chain_report'] as $kind) {
        expect(defaultBlockIds($kind))->not->toContain('reprint_marker');
        expect(app(BlockCatalog::class)->requiredBlocks($kind))->not->toContain('reprint_marker');
    }
});

// ─── gap 5: the VAT invoice title is not localised ────────────────────────

it('G5: tên chứng từ luật định không dịch — kể cả nhánh ja của vat_invoice (#1494)', function () {
    /*
     * Tiêu đề là TÊN CỦA MẪU, không phải chuỗi UI — nên nó không dịch. Luật ấy
     * áp cho các kind CÒN LÀ chứng từ luật định (`vat_invoice`, `debt_slip`).
     * `red_invoice` đã ra khỏi nhóm đó ở #2062 — nó không còn mang tên mẫu nào,
     * nên không có gì để "không dịch"; xem ca riêng bên dưới.
     *
     * `vat_invoice.ja` TỪNG là ngoại lệ, giữ 適格簡易請求書 — và ngoại lệ ấy nay
     * ĐÃ GỠ (#1493/#1494). Lý do giữ nó là: workstation chưa biết quốc gia của
     * shop, nên trục locale là đường DUY NHẤT để quán Nhật lấy được chứng từ của
     * mình; cắt lúc đó là quán Nhật mất chứng từ luật định.
     *
     * Điều kiện ấy hết hiệu lực khi #1490 đưa `operating_country` xuống thiết bị,
     * #1492 dựng kind `qualified_simplified_invoice`, và #1493 đổi trục rẽ trong
     * Go từ locale sang quốc gia. Quán Nhật nay nhận chứng từ Nhật qua kind của
     * chính nó, nên `vat_invoice.ja` không còn là đường sống của ai — giữ tiếng
     * Nhật ở đó chỉ tạo ra một tờ LAI: layout Việt, tiêu đề Nhật. Cổng TR-40 bắt
     * được đúng tờ lai đó và là lý do ba ô `vat_invoice|ja|*` trong
     * `print_golden.json` thay đổi.
     *
     * "Quốc gia nào ngôn ngữ đó" được thoả ở tầng CATALOG — `countries()` — chứ
     * không phải bằng cách dịch tên chứng từ.
     */
    $vat = defaultBlock(PrintTemplateKind::VatInvoice, 'title');
    $red = defaultBlock(PrintTemplateKind::RedInvoice, 'title');

    /*
     * #2062 — `red_invoice` KHÔNG CÒN mang tên một chứng từ luật định ở locale
     * nào, và đó là ruling mới chứ không phải rào bị nới.
     *
     * Lập luận "tên biểu mẫu không dịch" (#1445, ở trên) vẫn đúng — và chính vì
     * nó đúng mà 'HOA DON DO' phải đi. Sau #1779 tờ này không còn là hoá đơn
     * GTGT: không số, không mẫu số/ký hiệu, không ký số, không mã CQT, không
     * truyền CQT. Quy tắc "tên là một phần của mẫu" áp vào một tờ KHÔNG PHẢI
     * mẫu ấy thì nó không bảo vệ tên mẫu nữa — nó biến tờ giấy thành một tuyên
     * bố sai. `ja` đã trung thực từ #1890 (領収書 = biên lai); vi/en nay theo.
     *
     * Rào giữ HAI điều, và cả hai đều load-bearing:
     *
     *  1. KHÔNG locale nào được mang lại tên chứng từ luật định. Danh sách cấm
     *     dưới đây là thứ chặn một lượt "khôi phục cho quen mắt" — đúng kiểu
     *     thay đổi trông vô hại vì nó chỉ là một chuỗi.
     *  2. `vi`/`en` VẪN ghim ASCII: nửa fleet dùng máy in không có ROM kanji,
     *     in tiếng Nhật ra là một hàng ô vuông ở chỗ đáng lẽ là tên tờ giấy.
     *
     * `vat_invoice` và `debt_slip` (G5b) không đổi gì — chúng VẪN là chứng từ
     * luật định thật, nên vẫn giữ tên mẫu.
     */
    expect($red['i18n']['ja'])->toBe('領収書');
    expect($red['i18n']['en'])->toBe('PAYMENT RECEIPT');
    expect($red['i18n']['vi'])->toBe('PHIEU THANH TOAN');

    foreach (['ja', 'en', 'vi'] as $locale) {
        expect($red['i18n'][$locale])->not->toContain('HOA DON');
        expect($red['i18n'][$locale])->not->toContain('INVOICE');
        expect($red['i18n'][$locale])->not->toContain('請求書');
    }

    foreach (['en', 'vi'] as $locale) {
        expect(preg_match('/^[\x20-\x7e]+$/', $red['i18n'][$locale]))->toBe(
            1,
            "red_invoice title for `{$locale}` must stay ASCII (kanji-less printers)",
        );
    }

    // Cả BA locale, không còn ngoại lệ nào.
    foreach (['ja', 'en', 'vi'] as $locale) {
        expect($vat['i18n'][$locale])->toBe('HOA DON GIA TRI GIA TANG');
        expect($vat['i18n_narrow'][$locale])->toBe('HOA DON GTGT');
    }

    // Và chứng từ Nhật thì mang tên Nhật ở mọi locale — cùng một luật, chiều kia.
    $qsi = defaultBlock(PrintTemplateKind::QualifiedSimplifiedInvoice, 'title');
    foreach (['ja', 'en', 'vi'] as $locale) {
        expect($qsi['i18n'][$locale])->toBe('適格簡易請求書');
    }

    // Và cả hai kind chỉ được phát cho shop ở VN.
    expect(PrintTemplateKind::VatInvoice->countries())->toBe(['VN']);
    expect(PrintTemplateKind::RedInvoice->countries())->toBe(['VN']);
    expect(PrintTemplateKind::VatInvoice->availableIn('JP'))->toBeFalse();
    expect(PrintTemplateKind::VatInvoice->availableIn('VN'))->toBeTrue();
    // Không biết quốc gia thì KHÔNG ẩn — ẩn nhầm là chặn người ta xuất hoá đơn.
    expect(PrintTemplateKind::VatInvoice->availableIn(null))->toBeTrue();
    // Chứng từ vận hành thì mọi nước đều có.
    expect(PrintTemplateKind::Receipt->countries())->toBeNull();
});

/*
 * #2547 — câu miễn trừ ĐÃ GỠ khỏi `red_invoice` (quyết định sản phẩm).
 *
 * Rào đảo chiều chứ không xoá. `vat_disclaimer` từng là khối `locked` có tên
 * trong `required`, tức ba đường chặn của TemplateValidator đều đóng
 * (REQUIRED_BLOCK_MISSING · LOCKED_BLOCK_DISABLED · LOCKED_BLOCK_REORDERED).
 * Một khối như thế mọc lại thì mọc lại KÈM cả ba đường chặn, và nó sẽ in ra
 * giấy của quán mà không ai phải bấm gì — nên nó phải làm đỏ test, không phải
 * trôi qua trong im lặng.
 *
 * Hoá đơn GTGT (`vat_invoice`) GIỮ bản sao riêng của câu này trong emitter
 * `footer_text` của nó (DocsKindPlans::emitFooter) — chứng từ KHÁC, không đụng.
 */
it('#2547: red_invoice KHÔNG còn khối vat_disclaimer ở bất cứ tầng nào', function () {
    $catalog = app(BlockCatalog::class);

    expect(defaultBlockIds(PrintTemplateKind::RedInvoice))->not->toContain('vat_disclaimer');
    expect($catalog->requiredBlocks(PrintTemplateKind::RedInvoice))->not->toContain('vat_disclaimer');
    expect(defaultBlock(PrintTemplateKind::RedInvoice, 'vat_disclaimer'))->toBeNull();

    // Và khối biến mất khỏi CATALOG, không chỉ khỏi kind — còn trong catalog thì
    // một template publish vẫn khai được nó và validator vẫn cho qua.
    expect(array_keys(config('print_blocks.blocks')))->not->toContain('vat_disclaimer');
});

it('G5b: the debt slip heading is likewise unlocalised', function () {
    $title = defaultBlock(PrintTemplateKind::DebtSlip, 'title');

    foreach (['ja', 'en', 'vi'] as $locale) {
        expect($title['i18n'][$locale])->toBe('PHIEU GHI NO');
    }
});

it('G5c: `i18n_narrow` is an editable prop of `title`, so the shorthand survives a brand edit', function () {
    expect(app(BlockCatalog::class)->editableProps('title'))->toContain('i18n_narrow');
});

// ─── gap 6: the nine 精算 sections ─────────────────────────────────────────

it('G6: all nine settlement sections have block ids, in printing order', function () {
    $sections = [
        'sales_summary', 'tax_breakdown', 'tender_summary', 'non_cash_change',
        'discount_summary', 'service_charge', 'acct_correction', 'check_count',
        'cash_movement', 'void_summary',
    ];

    foreach ([PrintTemplateKind::ShiftReport, PrintTemplateKind::ChainReport] as $kind) {
        $ids = defaultBlockIds($kind);

        foreach ($sections as $section) {
            expect(in_array($section, $ids, true))
                ->toBeTrue("{$kind->value} is missing `{$section}`");
        }

        // Printing order, section by section.
        for ($i = 1; $i < count($sections); $i++) {
            expectOrder($kind, $sections[$i - 1], $sections[$i]);
        }

        // …then the reconciliation tail.
        expectOrder($kind, 'void_summary', 'variance');
        expectOrder($kind, 'variance', 'denomination_table');
    }
});

it('G6b: the seven new section ids are catalogued as toggleable, engine-owned blocks', function () {
    $catalog = app(BlockCatalog::class);

    foreach ([
        'sales_summary', 'non_cash_change', 'discount_summary',
        'acct_correction', 'check_count', 'cash_movement', 'void_summary',
    ] as $id) {
        expect($catalog->hasBlock($id))->toBeTrue("block `{$id}` is not in the catalog");
        // A settlement report is an internal operations document, so a brand
        // may hide a section it does not run — but the CONTENT is still the
        // engine's, so `enabled` is the only editable prop.
        expect($catalog->editableProps($id))->toBe(['enabled']);
    }
});

it('G6c: the chain report leads with its chain summary', function () {
    expectOrder('chain_report', 'chain_summary', 'sales_summary');
});

// ─── gap 7: shift_open note + device ──────────────────────────────────────

it('G7: the shift-open slip carries the cashier note and names the DEVICE', function () {
    $ids = defaultBlockIds(PrintTemplateKind::ShiftOpen);

    expect($ids)->toContain('order_note');
    // The denominations are counted BEFORE the float they add up to.
    expectOrder('shift_open', 'denomination_table', 'float_count');
    expectOrder('shift_open', 'float_count', 'order_note');

    $meta = defaultBlock(PrintTemplateKind::ShiftOpen, 'shift_meta');
    expect($meta['fields'])->toBe(['device_name', 'cashier_name', 'opened_at']);
});

it('G7b: `device_name` is an allow-listed param field', function () {
    expect(app(BlockCatalog::class)->paramFields())->toContain('device_name');
});

it('G7c: the VAT invoice may print the buyer tax code and address', function () {
    $fields = app(BlockCatalog::class)->paramFields();
    expect($fields)->toContain('customer_tax_code');
    expect($fields)->toContain('customer_address');

    $header = defaultBlock(PrintTemplateKind::VatInvoice, 'customer_header');
    expect($header['fields'])->toBe(['customer_name', 'customer_tax_code', 'customer_address']);
});

// ─── gap 8 (found while fixing): empty i18n must never encode as `[]` ─────

it('G8: an empty i18n map is OMITTED, never encoded as a JSON array', function () {
    /*
     * PHP has one array type, so `json_encode([])` yields `[]` — and Go
     * refuses an array where it expects `map[string]string`, failing the
     * WHOLE definition:
     *
     *   json: cannot unmarshal array into Go struct field .i18n
     *
     * The workstation would then fall back to its embedded default (TR-14),
     * so every brand and shop edit would have been silently discarded while
     * the registry looked healthy from Cloud's side. The kinds that carry a
     * disabled `footer_text` / `greeting` / `shift_signature` by default —
     * i.e. all thirteen — were all affected.
     */
    foreach (PrintTemplateKind::cases() as $kind) {
        $json = (string) json_encode(app(SystemTemplateDefaults::class)->forKind($kind));

        expect(str_contains($json, '"i18n":[]'))
            ->toBeFalse("{$kind->value} encodes an empty i18n as a JSON array");
        expect(str_contains($json, '"i18n_narrow":[]'))
            ->toBeFalse("{$kind->value} encodes an empty i18n_narrow as a JSON array");
    }
});

it('G8b: the normalizer drops empty maps and leaves everything else alone', function () {
    $in = [
        'schema' => 'tempo.print.v1',
        'blocks' => [
            ['id' => 'a', 'type' => 'text', 'i18n' => []],
            ['id' => 'b', 'type' => 'text', 'i18n' => ['ja' => 'あ'], 'i18n_narrow' => []],
            ['id' => 'c', 'type' => 'params', 'fields' => []],
            ['id' => 'd', 'type' => 'locked'],
        ],
    ];

    $out = DefinitionNormalizer::forTransport($in);

    expect($out['blocks'][0])->not->toHaveKey('i18n');
    expect($out['blocks'][1]['i18n'])->toBe(['ja' => 'あ']);
    expect($out['blocks'][1])->not->toHaveKey('i18n_narrow');
    // `fields` is a genuine LIST — `[]` is the correct encoding and must stay.
    expect($out['blocks'][2]['fields'])->toBe([]);
    expect($out['blocks'][3])->toBe(['id' => 'd', 'type' => 'locked']);
    expect($out['schema'])->toBe('tempo.print.v1');
});

it('G8c: a definition with no blocks passes through untouched', function () {
    expect(DefinitionNormalizer::forTransport(['schema' => 'x']))->toBe(['schema' => 'x']);
    expect(DefinitionNormalizer::forTransport(['blocks' => 'nonsense']))->toBe(['blocks' => 'nonsense']);
});

// ─── the baseline stays publishable ───────────────────────────────────────

it('P4: every corrected system default still declares only catalogued blocks', function () {
    $catalog = app(BlockCatalog::class);

    foreach (PrintTemplateKind::cases() as $kind) {
        foreach (defaultBlockIds($kind) as $id) {
            expect($catalog->hasBlock($id))->toBeTrue("`{$id}` on {$kind->value} is not in the catalog");
        }
    }
});

it('P5: every required block of a kind is actually present in its default', function () {
    // The catalog used to require `reprint_marker` on kinds whose default did
    // not have one, so the shipped baseline could not be published.
    $catalog = app(BlockCatalog::class);

    foreach (PrintTemplateKind::cases() as $kind) {
        $ids = defaultBlockIds($kind);

        foreach ($catalog->requiredBlocks($kind->value) as $required) {
            expect(in_array($required, $ids, true))
                ->toBeTrue("{$kind->value} requires `{$required}` but its default omits it");
        }
    }
});

it('G8d: a BRAND-published definition is normalised too, not just layer 0', function () {
    /*
     * The normalizer sits in ResolvedTemplate rather than in
     * SystemTemplateDefaults alone, because layer 0 is not the only definition
     * that reaches the wire. A brand that clears its footer copy stores
     * `i18n: {}`, PHP round-trips it to `[]`, and sync DOWN would ship a
     * definition the workstation refuses to parse — silently reverting that
     * brand to the embedded default while Cloud reported everything healthy.
     *
     * Asserted through the RESOLVER, which is the funnel every sync-DOWN
     * payload and every checksum passes through.
     */
    $orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $orgId,
        'console_organization_id' => $orgId,
    ]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
        'timezone' => 'Asia/Tokyo',
    ]);

    $definition = app(SystemTemplateDefaults::class)->forKind(PrintTemplateKind::Receipt);
    foreach ($definition['blocks'] as $i => $block) {
        if (($block['id'] ?? null) === 'footer_text') {
            // What a brand that cleared its footer actually stores.
            $definition['blocks'][$i]['i18n'] = [];
            $definition['blocks'][$i]['enabled'] = true;
        }
    }

    PrintTemplate::factory()->create([
        'brand_id' => $brand->id,
        'branch_id' => null,
        'kind' => 'receipt',
        'scope' => PrintTemplateScope::Brand->value,
        'status' => PrintTemplateStatus::Published->value,
        'version' => 1,
        'definition' => $definition,
        'shop_editable' => [],
        'effective_from' => null,
        'published_at' => now(),
    ]);

    $resolved = app(TemplateResolver::class)
        ->forBranch(PrintTemplateKind::Receipt, (string) $branch->id);

    $json = (string) json_encode($resolved->definition);

    expect(str_contains($json, '"i18n":[]'))
        ->toBeFalse('a resolved brand definition still encodes an empty i18n as a JSON array');

    // …and the block itself survives — normalising must DROP THE KEY, never
    // the block.
    expect(collect($resolved->definition['blocks'])->pluck('id'))->toContain('footer_text');
});
