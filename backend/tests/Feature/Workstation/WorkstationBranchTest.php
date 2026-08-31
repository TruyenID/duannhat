<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\BrandOrderPolicy;
use App\Models\Device;
use App\Models\Organization;
use App\Models\ShopOrderSetting;
use App\Models\TaxType;
use App\Services\Shop\SellerRegistrationResolver;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();

    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
        'name' => 'Quán Phở Hàng Bún',
        'currency' => 'JPY',
        'timezone' => 'Asia/Tokyo',
        'cart_timeout_minutes' => 5,
    ]);

    $this->wsToken = Str::random(64);

    $this->wsDevice = Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
});

it('returns branch info for workstation device branch', function () {
    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/branch')
        ->assertOk()
        ->assertJsonStructure(['data' => ['id', 'name', 'currency', 'timezone', 'cart_timeout_minutes', 'settings'], 'generated_at'])
        ->assertJsonPath('data.id', $this->branch->id)
        ->assertJsonPath('data.name', 'Quán Phở Hàng Bún')
        ->assertJsonPath('data.currency', 'JPY');
});

it('includes shop_order_settings in payload when row exists for branch', function () {
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'service_charge_rate' => 5.00,
        'currency_code' => 'JPY',
        'prep_before_payment' => false,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/branch')
        ->assertOk()
        ->assertJsonPath('data.settings.service_charge_rate', '5.00')
        ->assertJsonPath('data.settings.currency_code', 'JPY')
        // issue #456 — workstation needs this to decide the auto-print timing.
        ->assertJsonPath('data.settings.prep_before_payment', false);
});

// plan-043 T3.2 — the workstation needs the consumption-tax config to run its
// local tax resolver + close-report tax breakdown offline. These 4 columns are
// additive; old workstations ignore the extra keys.
it('ships the plan-043 consumption-tax settings in the payload', function () {
    $taxType = TaxType::factory()->reduced()->asDefault()->create();

    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'default_tax_type_id' => $taxType->id,
        'prices_include_tax' => true,
        'service_charge_tax_rate' => 8.00,
        'close_report_tax_breakdown' => true,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/branch')
        ->assertOk()
        ->assertJsonPath('data.settings.default_tax_type_id', $taxType->id)
        ->assertJsonPath('data.settings.prices_include_tax', true)
        ->assertJsonPath('data.settings.service_charge_tax_rate', '8.00')
        ->assertJsonPath('data.settings.close_report_tax_breakdown', true);
});

it('ships the plan-045 tax-rounding rule so the workstation mirrors + applies it', function () {
    // Regression: the branch settings SELECT omitted these two columns, so the
    // workstation never received the rounding rule (PullBranch flattens
    // data.settings.* into shop_settings), and LAN orders silently priced with
    // the default rule instead of the shop's configured one.
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'tax_rounding_mode' => 'ceil',
        'tax_rounding_decimals' => 2,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/branch')
        ->assertOk()
        ->assertJsonPath('data.settings.tax_rounding_mode', 'ceil')
        ->assertJsonPath('data.settings.tax_rounding_decimals', 2);
});

it('ships print_table_paid so the shop can actually turn the table-paid slip off', function () {
    // #1306 — same class as the plan-045 regression above, one step worse: the
    // workstation had ALWAYS gated the slip on this key
    // (auto_print.go: shopSetting("print_table_paid", "true")) while Cloud had no
    // column, no literal, nowhere to send it from. The default "true" kept the slip
    // printing correctly, so nothing looked broken — but shop_settings on the
    // workstation has exactly one writer, the Cloud pull, so the OFF branch could
    // not be reached by any shop, ever.
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'print_table_paid' => false,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/branch')
        ->assertOk()
        ->assertJsonPath('data.settings.print_table_paid', false);
});

it('ships print_table_paid as true for a row that never set it', function () {
    // The column defaults true — the same fallback the workstation has always
    // used — so exposing the switch must not change what any existing branch
    // prints. (A branch with NO settings row ships no key at all; that stays
    // safe because auto_print.go defaults the key to "true" when absent.)
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/branch')
        ->assertOk()
        ->assertJsonPath('data.settings.print_table_paid', true);
});

it('still ships the effective table_status_after_payment when branch has no shop_order_setting row', function () {
    // #491 — even without a shop_order_setting row, the workstation must learn
    // the effective table-status-after-payment (shop ?? HQ brand ?? 'free') so
    // it can apply the policy locally on close. Absent any override it is 'free'.
    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/branch')
        ->assertOk()
        ->assertJsonPath('data.settings.table_status_after_payment', 'free');
});

/**
 * #1490 (tầng 1 của #1459) — quốc gia của shop phải xuống tới thiết bị.
 *
 * Không có trục này, workstation suy quốc gia ra từ ngôn ngữ giao diện của thu
 * ngân (`FormatVatInvoice` rẽ theo `normalizePrintLocale(info.Locale)`), nên một
 * quán Việt đặt locale ja in ra 適格簡易請求書 và một quán Nhật đặt locale vi in ra
 * hoá đơn GTGT. Bốn trục độc lập: compliance-country ≠ currency ≠ timezone ≠
 * print locale.
 *
 * Key nằm trong `settings` chứ không phải khối branch vì PullBranch flatten
 * `data.settings.*` generic vào shop_settings — mọi bản workstation cũ lưu được
 * ngay, không cần deploy client.
 */
it('#1490 ships the shop operating country for a VN organization', function () {
    Organization::where('console_organization_id', $this->orgId)
        ->update(['operating_country' => 'VN']);

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/branch')
        ->assertOk()
        ->assertJsonPath('data.settings.operating_country', 'VN');
});

it('#1490 ships JP for a Japanese organization', function () {
    Organization::where('console_organization_id', $this->orgId)
        ->update(['operating_country' => 'JP']);

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/branch')
        ->assertOk()
        ->assertJsonPath('data.settings.operating_country', 'JP');
});

/**
 * Org chưa bao giờ được đặt quốc gia → `'JP'`, và đường đi là **cột có default**
 * chứ không phải nhánh fail-safe của resolver: `organizations.operating_country`
 * là `string(2) NOT NULL default 'JP'` (schemas/Backend/Sso/Organization.yaml).
 * Ghi rõ ở đây vì `null` là trạng thái KHÔNG tồn tại trên cột này — ai đọc feed
 * mà chờ `null` để biết "chưa cấu hình" sẽ chờ mãi.
 */
it('#1490 ships JP for an org that never set a country (column default, not resolver fallback)', function () {
    expect(Organization::where('console_organization_id', $this->orgId)->value('operating_country'))
        ->toBe('JP');

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/branch')
        ->assertOk()
        ->assertJsonPath('data.settings.operating_country', 'JP');
});

/**
 * Nhánh fail-safe THẬT của `ComplianceProfileResolver` (#1153): **không có dòng
 * organization nào** khớp console id — branch mồ côi, hoặc org chưa kịp mirror
 * về từ Platform. Feed vẫn phải trả một giá trị dùng được thay vì rỗng, vì bên
 * kia là máy in đang chờ biết in chứng từ nước nào.
 *
 * Đây cũng là lý do tầng 3 (#1493) không được coi `'JP'` là "chắc chắn Nhật":
 * hai trạng thái rất khác nhau — "Nhật thật" và "chưa biết" — cùng gửi `'JP'`.
 */
it('#1490 still ships JP when no organization row exists at all', function () {
    $this->branch->forceFill(['console_organization_id' => (string) Str::uuid()])->save();

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/branch')
        ->assertOk()
        ->assertJsonPath('data.settings.operating_country', 'JP');
});

/**
 * Cái bẫy mà #1459 tồn tại để gỡ: chứng từ đi theo quốc gia, KHÔNG theo ngôn ngữ
 * in. Một quán VN mà thu ngân để giao diện tiếng Nhật vẫn phải là VN.
 */
it('#1490 keeps the country independent of print_label_locale', function () {
    Organization::where('console_organization_id', $this->orgId)
        ->update(['operating_country' => 'VN']);

    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'print_label_locale' => 'ja',
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/branch')
        ->assertOk()
        ->assertJsonPath('data.settings.print_label_locale', 'ja')
        ->assertJsonPath('data.settings.operating_country', 'VN');
});

/**
 * Feed này chạy cho branch KHÔNG có `console_brand_id` (factory để trống), nên
 * quốc gia phải lấy từ org của chính branch — không được đi vòng qua Brand như
 * PrintTemplateController (brand-scoped), nếu không một branch chưa gắn brand sẽ
 * im lặng mất quốc gia.
 */
it('#1490 resolves the country without needing a brand row', function () {
    Organization::where('console_organization_id', $this->orgId)
        ->update(['operating_country' => 'VN']);

    expect($this->branch->fresh()->console_brand_id)->toBeNull();

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/branch')
        ->assertOk()
        ->assertJsonPath('data.settings.operating_country', 'VN');
});

it('returns 401 without auth', function () {
    $this->getJson('/api/v1/workstation/branch')->assertUnauthorized();
});

it('returns 403 when tms device hits /workstation/branch', function () {
    $tmsToken = Str::random(64);
    Device::factory()->create([
        'type' => 'tms',
        'status' => 'active',
        'device_token' => $tmsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$tmsToken}"])
        ->getJson('/api/v1/workstation/branch')
        ->assertForbidden();
});

// Ngôn ngữ phiếu in — the shop ?? HQ resolve happens HERE in Cloud, so the
// workstation only ever sees one settled value in shop_settings and never has
// to know the brand layer exists. That is what keeps printing correct while the
// Cloud link is down: the resolved value already sits in local SQLite.
it('resolves print_label_locale from the HQ brand default when the shop leaves it null', function () {
    $brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $this->branch->update(['console_brand_id' => $brand->console_brand_id]);

    BrandOrderPolicy::factory()->create([
        'brand_id' => $brand->id,
        'organization_id' => $this->orgId,
        'default_print_label_locale' => 'ja',
    ]);
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'print_label_locale' => null, // "inherit HQ"
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/branch')
        ->assertOk()
        ->assertJsonPath('data.settings.print_label_locale', 'ja');
});

it('lets the shop override the HQ print_label_locale default', function () {
    $brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $this->branch->update(['console_brand_id' => $brand->console_brand_id]);

    BrandOrderPolicy::factory()->create([
        'brand_id' => $brand->id,
        'organization_id' => $this->orgId,
        'default_print_label_locale' => 'ja',
    ]);
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'print_label_locale' => 'vi',
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/branch')
        ->assertOk()
        ->assertJsonPath('data.settings.print_label_locale', 'vi');
});

// Neither layer configured → null travels down untouched so the workstation
// falls through to branches.locale → pos_print_locale → "ja" instead of being
// pinned to a language nobody chose.
it('sends a null print_label_locale when neither shop nor HQ configured one', function () {
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'print_label_locale' => null,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/branch')
        ->assertOk()
        ->assertJsonPath('data.settings.print_label_locale', null);
});

/**
 * #1301 — a 登録番号 set at SHOP level must reach the workstation.
 *
 * The field is the one 適格請求書 / 適格簡易請求書 requires on the receipt, and #1152
 * exists so it is never missing. It was: the branch feed selected a fixed
 * column list that omitted `invoice_registration_number`, so the resolver read
 * a partially-hydrated model, saw the attribute absent, read that as "no
 * override" and fell through to the brand. Stored correctly, served empty, no
 * exception anywhere.
 *
 * Brand-level numbers were unaffected — the resolver queries Brand separately —
 * which is why this could sit unnoticed.
 */
it('#1301 serves a shop-level 登録番号 to the workstation feed', function () {
    $this->branch->forceFill(['invoice_registration_number' => 'T1234567890125'])->save();

    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->branch->id],
        ['organization_id' => $this->orgId, 'show_seller_registration_on_receipt' => true],
    );

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/branch')
        ->assertOk()
        ->assertJsonPath('data.settings.seller_registration_number', 'T1234567890125');
});

it('#1301 still falls back to the brand number when the shop has none', function () {
    // The factory leaves the branch without a console_brand_id, so the fallback
    // needs both halves wired: a brand, and a branch that points at it.
    $brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'invoice_registration_number' => 'T9999999999999',
    ]);
    $this->branch->forceFill(['console_brand_id' => $brand->console_brand_id])->save();

    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->branch->id],
        ['organization_id' => $this->orgId, 'show_seller_registration_on_receipt' => true],
    );

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/branch')
        ->assertOk()
        ->assertJsonPath('data.settings.seller_registration_number', 'T9999999999999');
});

/**
 * #1301 — the resolver must not read "column not selected" as "no number".
 *
 * The select() fix above closes this instance. This closes the class: a
 * partially-hydrated Eloquent model answers a missing attribute with null
 * rather than raising, so any future caller that trims the column list would
 * silently strip a field 適格請求書 requires — the same way, with the data still
 * correct in the database.
 */
it('#1301 resolves the shop number even from a partially-hydrated branch', function () {
    $this->branch->forceFill(['invoice_registration_number' => 'T1234567890125'])->save();

    // Exactly the shape the branch feed used to build: no registration column.
    $partial = Branch::query()
        ->select(['id', 'console_branch_id', 'console_brand_id', 'name'])
        ->find($this->branch->id);

    expect(array_key_exists('invoice_registration_number', $partial->getAttributes()))->toBeFalse()
        ->and(app(SellerRegistrationResolver::class)->resolve($partial))
        ->toBe('T1234567890125');
});

it('#2000 bước 4 — feed mang 法人名, tra qua console_organization_id', function () {
    Organization::query()->whereKey($this->orgId)->update(['name' => '株式会社ファムジア']);

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/branch')
        ->assertOk()
        // Tên PHÁP NHÂN, khác brand (thương hiệu) và khác chi nhánh. 登録番号
        // T+13 thuộc về pháp nhân, nên hoá đơn phải nói đúng chủ thể của con số.
        ->assertJsonPath('data.organization_name', '株式会社ファムジア');
});

it('#2000 bước 4 — không có tổ chức khớp thì trả CHUỖI RỖNG, không phải null', function () {
    // `branches` mang khoá do Platform cấp; nếu bản ghi tổ chức chưa đồng bộ về
    // thì tra ra không có gì. Trả rỗng chứ không null: phía Go giải mã vào
    // `string`, và một `null` sẽ thành lỗi JSON chứ không thành "chưa biết".
    Organization::query()->whereKey($this->orgId)->update(['console_organization_id' => (string) Str::uuid()]);

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/branch')
        ->assertOk()
        ->assertJsonPath('data.organization_name', '');
});
