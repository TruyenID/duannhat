<?php

declare(strict_types=1);

/**
 * plan-053 (#1171) — TESTS.md §2 (V1–V10, Q1–Q2): the publish gate.
 *
 * Every rule here fires at PUBLISH and only at publish (TR-14). The mirror
 * property — that a broken definition never blocks a sale at print time — is
 * the workstation's to prove (§4 W5); what this file guarantees is that a
 * broken definition never gets that far.
 */

use App\Exceptions\Print\TemplateValidationException;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Print\Enums\PrintTemplateKind;
use App\Services\Print\Enums\PrintTemplateScope;
use App\Services\Print\SystemTemplateDefaults;
use App\Services\Print\TemplateValidator;
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

    $this->validator = app(TemplateValidator::class);
    $this->base = fn (PrintTemplateKind $kind = PrintTemplateKind::Receipt) => app(SystemTemplateDefaults::class)->forKind($kind);
});

/** Mutate one block of a definition and return the whole definition. */
function withBlock(array $definition, string $id, array $props): array
{
    foreach ($definition['blocks'] as $i => $block) {
        if (($block['id'] ?? null) === $id) {
            $definition['blocks'][$i] = array_replace($block, $props);
        }
    }

    return $definition;
}

function removeBlock(array $definition, string $id): array
{
    $definition['blocks'] = array_values(array_filter(
        $definition['blocks'],
        fn (array $block): bool => ($block['id'] ?? null) !== $id,
    ));

    return $definition;
}

/** @return list<string> the violation codes of a rejected publish */
function publishCodes(callable $publish): array
{
    try {
        $publish();
    } catch (TemplateValidationException $e) {
        return $e->codes();
    }

    throw new RuntimeException('Expected the definition to be rejected, but it validated.');
}

it('accepts the untouched system default of every kind — the baseline must be publishable', function () {
    foreach (PrintTemplateKind::cases() as $kind) {
        $definition = app(SystemTemplateDefaults::class)->forKind($kind);

        $result = $this->validator->validateForPublish(
            $definition,
            $kind,
            PrintTemplateScope::Brand,
            $this->brand,
            null,
        );

        expect($result['blocks'])->not->toBeEmpty("kind {$kind->value} failed to validate");
    }
});

// ─── V1 (TR-15) ──────────────────────────────────────────────────────────
it('V1: rejects arithmetic / placeholders in a definition (TR-15)', function () {
    $definition = withBlock(($this->base)(), 'footer_text', [
        'enabled' => true,
        'fallback' => true,
        'i18n' => ['ja' => 'Total: {{ subtotal * 0.1 }}'],
    ]);

    $codes = publishCodes(fn () => $this->validator->validateForPublish(
        $definition, PrintTemplateKind::Receipt, PrintTemplateScope::Brand, $this->brand, null,
    ));

    expect($codes)->toContain('EXPRESSION_NOT_ALLOWED');
});

it('V1b: rejects shell-style interpolation too — the rule has no "safe subset"', function () {
    $definition = withBlock(($this->base)(), 'footer_text', [
        'enabled' => true,
        'fallback' => true,
        'i18n' => ['ja' => 'Thanks ${customer_name}'],
    ]);

    expect(publishCodes(fn () => $this->validator->validateForPublish(
        $definition, PrintTemplateKind::Receipt, PrintTemplateScope::Brand, $this->brand, null,
    )))->toContain('EXPRESSION_NOT_ALLOWED');
});

// ─── #1949 — block bật mà không ai vẽ ────────────────────────────────────
it('#1949: từ chối bật một block MỚI mà không renderer nào vẽ', function () {
    // Giả lập một block vừa được thêm vào kind nhưng chưa ai viết emitter — tức
    // KHÔNG nằm trong `renderable_debt`. Đây là ca mà rào sinh ra để bắt: trước
    // #1949 nó đi qua CẢ BẢY kiểm (kể cả lượt render thử, vốn chạy thành công và
    // chỉ không vẽ gì) rồi tờ giấy ra thiếu một khối, không lỗi, không log.
    config(['print_blocks.renderable_debt.receipt' => []]);

    $definition = withBlock(($this->base)(), 'logo', ['enabled' => true]);

    expect(publishCodes(fn () => $this->validator->validateForPublish(
        $definition, PrintTemplateKind::Receipt, PrintTemplateScope::Brand, $this->brand, null,
    )))->toContain('BLOCK_NOT_RENDERABLE');
});

it('#1949: nợ ĐÃ KHAI trong config thì KHÔNG chặn publish', function () {
    // Ranh giới cố ý, và nó được rút ra bằng cách thử luật rộng hơn rồi thấy nó
    // hỏng: bản đầu chặn mọi block bật mà thiếu emitter, và chặn luôn bản mặc
    // định hệ thống — `discounts`/`invoice_number` là block `locked`, không khai
    // `enabled` nên mặc định BẬT, và chưa từng có emitter ở đâu. Không brand nào
    // publish được gì.
    //
    // Đó đúng bẫy \"định nghĩa trung thực không publish nổi\" của lỗ #4 (#1181),
    // và một luật chặn tất cả thì bị tắt chứ không được sửa.
    $definition = ($this->base)();

    expect(fn () => $this->validator->validateForPublish(
        $definition, PrintTemplateKind::Receipt, PrintTemplateScope::Brand, $this->brand, null,
    ))->not->toThrow(TemplateValidationException::class);
});

// ─── V2 (TR-16) ──────────────────────────────────────────────────────────
it('V2: rejects editing the content of a locked block (TR-16)', function () {
    $definition = withBlock(($this->base)(), 'tax_breakdown', [
        'i18n' => ['ja' => 'my own tax wording'],
    ]);

    expect(publishCodes(fn () => $this->validator->validateForPublish(
        $definition, PrintTemplateKind::Receipt, PrintTemplateScope::Brand, $this->brand, null,
    )))->toContain('LOCKED_BLOCK_MODIFIED');
});

it('V2b: rejects REORDERING the locked blocks (TR-16)', function () {
    $definition = ($this->base)();

    /*
     * Move grand_total ABOVE subtotal — a slip that states its total before
     * the figures it is the sum of.
     *
     * #1181 note: this test used to move grand_total above `tax_breakdown`
     * and justify it as "a slip that totals before it itemises tax is not an
     * 適格請求書". That reasoning was backwards, and it stopped detecting
     * anything the moment the catalog was corrected: the real receipt prints
     * the per-rate 内税 split BELOW the grand total (#1042), because 内税 is
     * already inside the total and reads as a breakdown of it. Once the
     * default order was fixed, "remove grand_total, re-insert it at
     * tax_breakdown's index" reproduced the original order exactly and the
     * assertion passed on a definition that had not been reordered at all.
     *
     * `subtotal` is the honest anchor: it is above grand_total in the real
     * slip and in every locked ordering, so moving past it is unambiguously
     * a reorder.
     */
    $blocks = collect($definition['blocks']);
    $total = $blocks->firstWhere('id', 'grand_total');
    $definition['blocks'] = $blocks
        ->reject(fn (array $b): bool => $b['id'] === 'grand_total')
        ->values()
        ->all();

    $subtotalIndex = collect($definition['blocks'])->search(fn (array $b): bool => $b['id'] === 'subtotal');
    array_splice($definition['blocks'], (int) $subtotalIndex, 0, [$total]);

    expect(publishCodes(fn () => $this->validator->validateForPublish(
        $definition, PrintTemplateKind::Receipt, PrintTemplateScope::Brand, $this->brand, null,
    )))->toContain('LOCKED_BLOCK_REORDERED');
});

// ─── V3 (TR-17, #1152) ───────────────────────────────────────────────────
it('V3: rejects disabling registration_number when the brand HAS a number (TR-17)', function () {
    $this->brand->update(['invoice_registration_number' => 'T1234567890123']);

    $definition = withBlock(($this->base)(), 'registration_number', ['enabled' => false]);

    expect(publishCodes(fn () => $this->validator->validateForPublish(
        $definition, PrintTemplateKind::Receipt, PrintTemplateScope::Brand, $this->brand->fresh(), null,
    )))->toContain('REQUIRED_BLOCK_DISABLED');
});

it('V3b: ALLOWS disabling registration_number when there is no number — 免税事業者 is legal (#1152 ruling)', function () {
    $definition = withBlock(($this->base)(), 'registration_number', ['enabled' => false]);

    $result = $this->validator->validateForPublish(
        $definition, PrintTemplateKind::Receipt, PrintTemplateScope::Brand, $this->brand, null,
    );

    expect($result)->toBeArray();
});

it('V3c: a branch registration override also makes the block mandatory (#1152 resolution order)', function () {
    $this->branch->update(['invoice_registration_number' => 'T9999999999999']);

    $definition = withBlock(($this->base)(), 'registration_number', ['enabled' => false]);

    expect(publishCodes(fn () => $this->validator->validateForPublish(
        $definition,
        PrintTemplateKind::Receipt,
        PrintTemplateScope::Brand,
        $this->brand,
        $this->branch->fresh(),
    )))->toContain('REQUIRED_BLOCK_DISABLED');
});

// ─── V4 (TR-18) ──────────────────────────────────────────────────────────
it('V4: rejects disabling red_invoice_marker — 赤伝 must say it is 赤伝 (TR-18)', function () {
    $definition = withBlock(
        ($this->base)(PrintTemplateKind::RedInvoice),
        'red_invoice_marker',
        ['enabled' => false],
    );

    expect(publishCodes(fn () => $this->validator->validateForPublish(
        $definition, PrintTemplateKind::RedInvoice, PrintTemplateScope::Brand, $this->brand, null,
    )))->toContain('LOCKED_BLOCK_DISABLED');
});

it('V4b: rejects removing a required compliance block outright', function () {
    $definition = removeBlock(($this->base)(), 'tax_breakdown');

    expect(publishCodes(fn () => $this->validator->validateForPublish(
        $definition, PrintTemplateKind::Receipt, PrintTemplateScope::Brand, $this->brand, null,
    )))->toContain('REQUIRED_BLOCK_MISSING');
});

it('V4c: rejects disabling the reprint marker — 再発行 must be visible', function () {
    $definition = withBlock(($this->base)(), 'reprint_marker', ['enabled' => false]);

    expect(publishCodes(fn () => $this->validator->validateForPublish(
        $definition, PrintTemplateKind::Receipt, PrintTemplateScope::Brand, $this->brand, null,
    )))->toContain('LOCKED_BLOCK_DISABLED');
});

// ─── V5 (TR-03) ──────────────────────────────────────────────────────────
it('V5: rejects a shop editing a field outside shop_editable (TR-03)', function () {
    $overlay = [
        'blocks' => [
            ['id' => 'footer_text', 'type' => 'text', 'enabled' => true, 'fallback' => true, 'i18n' => ['ja' => 'ok']],
            ['id' => 'header_text', 'type' => 'text', 'enabled' => true, 'fallback' => true, 'i18n' => ['ja' => 'NOT ALLOWED']],
        ],
    ];

    $codes = publishCodes(fn () => $this->validator->validateForPublish(
        $overlay,
        PrintTemplateKind::Receipt,
        PrintTemplateScope::Shop,
        $this->brand,
        $this->branch,
        ['footer_text'],
    ));

    expect($codes)->toContain('SHOP_FIELD_NOT_EDITABLE');
});

it('V5b: accepts a shop override entirely inside the allow-list', function () {
    $overlay = [
        'blocks' => [
            ['id' => 'footer_text', 'type' => 'text', 'enabled' => true, 'fallback' => true, 'i18n' => ['ja' => 'shop footer']],
        ],
    ];

    $result = $this->validator->validateForPublish(
        $overlay,
        PrintTemplateKind::Receipt,
        PrintTemplateScope::Shop,
        $this->brand,
        $this->branch,
        ['footer_text'],
    );

    expect($result['blocks'][0]['id'])->toBe('footer_text');
});

it('V5c: a brand may not delegate a locked block to a shop', function () {
    expect(publishCodes(fn () => $this->validator->validateShopEditable(
        ['tax_breakdown'],
        PrintTemplateKind::Receipt,
    )))->toContain('SHOP_EDITABLE_LOCKED_BLOCK');
});

it('V5d: a brand may not delegate a block that is not part of the kind', function () {
    expect(publishCodes(fn () => $this->validator->validateShopEditable(
        ['chain_summary'],
        PrintTemplateKind::Receipt,
    )))->toContain('SHOP_EDITABLE_UNKNOWN_BLOCK');
});

// ─── V6 (TR-06) ──────────────────────────────────────────────────────────
it('V6: rejects an unknown block id (TR-06)', function () {
    $definition = ($this->base)();
    $definition['blocks'][] = ['id' => 'totally_made_up', 'type' => 'text', 'i18n' => ['ja' => 'x']];

    expect(publishCodes(fn () => $this->validator->validateForPublish(
        $definition, PrintTemplateKind::Receipt, PrintTemplateScope::Brand, $this->brand, null,
    )))->toContain('BLOCK_UNKNOWN');
});

it('V6b: rejects a real block that does not belong to this kind', function () {
    $definition = ($this->base)();
    $definition['blocks'][] = ['id' => 'chain_summary', 'type' => 'locked'];

    expect(publishCodes(fn () => $this->validator->validateForPublish(
        $definition, PrintTemplateKind::Receipt, PrintTemplateScope::Brand, $this->brand, null,
    )))->toContain('BLOCK_NOT_IN_KIND');
});

it('V6c: rejects a wrong schema envelope', function () {
    $definition = ($this->base)();
    $definition['schema'] = 'tempo.print.v99';

    expect(publishCodes(fn () => $this->validator->validateForPublish(
        $definition, PrintTemplateKind::Receipt, PrintTemplateScope::Brand, $this->brand, null,
    )))->toContain('SCHEMA_MISMATCH');
});

it('V6d: an unknown kind is a 422 on the HTTP surface, not a 500 (TR-06)', function () {
    $user = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($user, $this->orgId);

    $this->actingAs($user)
        ->getJson("/api/v1/hq/{$this->brand->slug}/print-templates/not_a_kind")
        ->assertStatus(422)
        ->assertJsonPath('code', 'PRINT_TEMPLATE_KIND_UNKNOWN');
});

// ─── V7 (TR-21) ──────────────────────────────────────────────────────────
it('V7: rejects a `source` outside the allow-list — no arbitrary URLs at a printer (TR-21)', function () {
    $definition = withBlock(($this->base)(), 'logo', [
        'enabled' => true,
        'source' => 'http://evil.example/x.png',
    ]);

    expect(publishCodes(fn () => $this->validator->validateForPublish(
        $definition, PrintTemplateKind::Receipt, PrintTemplateScope::Brand, $this->brand, null,
    )))->toContain('SOURCE_NOT_ALLOWED');
});

it('V7b: rejects an unknown params field binding', function () {
    $definition = withBlock(($this->base)(), 'store_info', [
        'fields' => ['store_name', 'secret_api_key'],
    ]);

    expect(publishCodes(fn () => $this->validator->validateForPublish(
        $definition, PrintTemplateKind::Receipt, PrintTemplateScope::Brand, $this->brand, null,
    )))->toContain('PARAM_FIELD_NOT_ALLOWED');
});

it('V7c: rejects an unknown line-item column', function () {
    $definition = withBlock(($this->base)(), 'items', [
        'columns' => ['name', 'cost_price'],
    ]);

    expect(publishCodes(fn () => $this->validator->validateForPublish(
        $definition, PrintTemplateKind::Receipt, PrintTemplateScope::Brand, $this->brand, null,
    )))->toContain('PROP_VALUE_NOT_ALLOWED');
});

// ─── V8 (TR-22) ──────────────────────────────────────────────────────────
it('V8: CLAMPS an oversize logo to the printable width instead of rejecting it (TR-22)', function () {
    $definition = withBlock(($this->base)(), 'logo', [
        'enabled' => true,
        'source' => 'brand_logo',
        'max_width_dots' => 4000,
    ]);

    $result = $this->validator->validateForPublish(
        $definition, PrintTemplateKind::Receipt, PrintTemplateScope::Brand, $this->brand, null,
    );

    $logo = collect($result['blocks'])->firstWhere('id', 'logo');

    expect($logo['max_width_dots'])->toBe(config('print_blocks.image.printable_dots_80mm'));
});

it('V8b: a non-numeric image width is a hard reject — nothing sane to clamp to', function () {
    $definition = withBlock(($this->base)(), 'logo', [
        'enabled' => true,
        'source' => 'brand_logo',
        'max_width_dots' => 'huge',
    ]);

    expect(publishCodes(fn () => $this->validator->validateForPublish(
        $definition, PrintTemplateKind::Receipt, PrintTemplateScope::Brand, $this->brand, null,
    )))->toContain('IMAGE_WIDTH_INVALID');
});

// ─── V9 (TR-19) ──────────────────────────────────────────────────────────
it('V9: rejects a missing locale unless the block declares fallback (TR-19)', function () {
    $definition = withBlock(($this->base)(), 'footer_text', [
        'enabled' => true,
        'fallback' => false,
        'i18n' => ['ja' => 'ありがとう', 'en' => 'Thank you'],
    ]);

    $codes = publishCodes(fn () => $this->validator->validateForPublish(
        $definition, PrintTemplateKind::Receipt, PrintTemplateScope::Brand, $this->brand, null,
    ));

    expect($codes)->toContain('I18N_INCOMPLETE');
});

it('V9b: publishes when the block declares fallback: true', function () {
    $definition = withBlock(($this->base)(), 'footer_text', [
        'enabled' => true,
        'fallback' => true,
        'i18n' => ['ja' => 'ありがとう', 'en' => 'Thank you'],
    ]);

    expect($this->validator->validateForPublish(
        $definition, PrintTemplateKind::Receipt, PrintTemplateScope::Brand, $this->brand, null,
    ))->toBeArray();
});

it('V9c: the fallback chain is declared so the renderer cannot invent its own', function () {
    expect(config('print_templates.locale_fallback'))->toBe(['ja', 'en']);
});

// ─── V10 (DESIGN §4.6) ───────────────────────────────────────────────────
it('V10: rejects a publish whose render trial fails, naming the paper width (§4.6)', function () {
    // 40 unbreakable columns: fits 80mm (48 cols), impossible on 58mm (32).
    $definition = withBlock(($this->base)(), 'footer_text', [
        'enabled' => true,
        'fallback' => true,
        'i18n' => ['ja' => str_repeat('A', 40)],
    ]);

    try {
        $this->validator->validateForPublish(
            $definition, PrintTemplateKind::Receipt, PrintTemplateScope::Brand, $this->brand, null,
        );
        $this->fail('Expected the render trial to reject this definition.');
    } catch (TemplateValidationException $e) {
        expect($e->codes())->toContain('RENDER_TRIAL_FAILED');

        $message = collect($e->violations)->firstWhere('code', 'RENDER_TRIAL_FAILED')['message'];
        expect($message)->toContain('58mm')
            ->and($message)->not->toContain('80mm paper');
    }
});

it('V10b: measures DISPLAY width — 20 fullwidth chars are 40 columns, not 20', function () {
    $definition = withBlock(($this->base)(), 'footer_text', [
        'enabled' => true,
        'fallback' => true,
        'i18n' => ['ja' => str_repeat('あ', 20)],
    ]);

    expect(publishCodes(fn () => $this->validator->validateForPublish(
        $definition, PrintTemplateKind::Receipt, PrintTemplateScope::Brand, $this->brand, null,
    )))->toContain('RENDER_TRIAL_FAILED');
});

it('V10c: long but WRAPPABLE text passes — the renderer wraps (TR-20)', function () {
    $definition = withBlock(($this->base)(), 'footer_text', [
        'enabled' => true,
        'fallback' => true,
        'i18n' => ['ja' => trim(str_repeat('word ', 40))],
    ]);

    expect($this->validator->validateForPublish(
        $definition, PrintTemplateKind::Receipt, PrintTemplateScope::Brand, $this->brand, null,
    ))->toBeArray();
});

// ─── Q1 / Q2 (TR-37) ─────────────────────────────────────────────────────
it('Q1: a cashier cannot reach the HQ publish surface at all (TR-37)', function () {
    $cashier = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantRole($cashier, $this->orgId, 'shop-staff');

    $this->actingAs($cashier)
        ->postJson("/api/v1/hq/{$this->brand->slug}/print-templates/receipt/publish", [])
        ->assertForbidden();

    // Not even the list — TR-37 "Cashier không thấy menu".
    $this->actingAs($cashier)
        ->getJson("/api/v1/hq/{$this->brand->slug}/print-templates")
        ->assertForbidden();
});

it('Q1b: a cashier cannot reach the SHOP override surface either (TR-37)', function () {
    $cashier = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantRole($cashier, $this->orgId, 'shop-staff');

    $this->actingAs($cashier)
        ->getJson("/api/v1/shops/{$this->branch->slug}/print-templates")
        ->assertForbidden();
});

it('Q2: a shop-manager is 403 on brand-level publish but may publish a shop override (TR-37)', function () {
    $manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantRole($manager, $this->orgId, 'shop-manager');

    $this->actingAs($manager)
        ->postJson("/api/v1/hq/{$this->brand->slug}/print-templates/receipt/publish", [])
        ->assertForbidden();

    // The shop surface IS theirs.
    $this->actingAs($manager)
        ->getJson("/api/v1/shops/{$this->branch->slug}/print-templates")
        ->assertOk();

    $this->actingAs($manager)
        ->postJson("/api/v1/shops/{$this->branch->slug}/print-templates/receipt/draft", [
            'definition' => ['blocks' => [
                ['id' => 'footer_text', 'type' => 'text', 'enabled' => true, 'fallback' => true, 'i18n' => ['ja' => 'shop']],
            ]],
        ])
        ->assertOk();
});

it('Q2b: an org-admin may publish at brand level', function () {
    $admin = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($admin, $this->orgId);

    $this->actingAs($admin)
        ->postJson("/api/v1/hq/{$this->brand->slug}/print-templates/receipt/draft", [
            'definition' => app(SystemTemplateDefaults::class)->forKind(PrintTemplateKind::Receipt),
        ])
        ->assertOk();

    $this->actingAs($admin)
        ->postJson("/api/v1/hq/{$this->brand->slug}/print-templates/receipt/publish", [])
        ->assertCreated()
        ->assertJsonPath('data.status', 'published');
});

/** Assign a named system role, seeding the IAM matrix on first use. */
function grantRole(User $user, string $organizationId, string $slug): void
{
    if (! Permission::query()->exists()) {
        (new IamSeeder)->run();
    }

    $user->assignRole(Role::query()->where('slug', $slug)->firstOrFail(), $organizationId);
}
