<?php

declare(strict_types=1);

/**
 * #962 — ba cạnh cuối mà `TillSessionService` (Payments) còn với thẳng sang
 * Ordering, giờ đi qua hợp đồng công bố.
 *
 * Ba cạnh KHÔNG cùng loại, nên bài này ghim ba kiểu bất biến khác nhau:
 *
 *  1. `BranchOrderReads::itemQuantityForOrders` — luật "bỏ dòng đã VOID" phải ở
 *     lại phía sở hữu bảng. Chuyển sai thì 点数 trên phiếu ca đếm cả món đã huỷ.
 *  2. `OrderTaxBreakdownReads::forOrders()['by_rate']` — HÌNH DẠNG `{rate, taxable, tax}`
 *     phải giữ nguyên: snapshot của Cloud ghi đè bản tạm của workstation qua
 *     response sync-UP (plan-046 R7), nên lệch một khoá là một mục biến mất khỏi
 *     phiếu chuỗi ca mà không ai thấy lúc build.
 *  3. `BranchOrderSettingsLock` — **cái khoá là nội dung, không phải chi tiết**.
 *     Đây là phần khó nhất của cả PR và là lý do bài này tồn tại: bỏ
 *     `->lockForUpdate()` khỏi adapter thì mọi test khác vẫn XANH — câu lệnh vẫn
 *     chạy, không lỗi, và không khoá gì cả — trong khi ca đua của AUDIT FIX 3.6
 *     (mở ca ĐUA với `PATCH /shops/{slug}/settings/order`, plan-031) mở lại.
 *
 * ## Cái bài này KHÔNG chứng minh, nói thẳng
 *
 * Không chứng minh được hai request đồng thời bị TUẦN TỰ HOÁ thật. Việc đó cần
 * hai kết nối và một engine có khoá hàng; suite chạy trên SQLite, nơi
 * `SQLiteGrammar::compileLock()` trả CHUỖI RỖNG (đã đọc nguồn) — câu SQL sinh ra
 * không bao giờ chứa `for update`, dù code có gọi hay không. Nên khoá được ghim
 * bằng hai thứ kiểm được, theo đúng tiền lệ `OrderRowLockPortTest`:
 *
 *   · adapter thật sự gọi `->lockForUpdate()` (quét NGUỒN);
 *   · lời gọi xảy ra TRONG một transaction đang mở — vì `SELECT … FOR UPDATE`
 *     ngoài transaction nhả khoá ngay khi câu lệnh kết thúc, tức đúng cái ảo
 *     giác an toàn mà `BranchOrderSettingsLock` được viết ra để chặn.
 *
 * Và giả định "SQLite không sinh `for update`" được ghim RIÊNG, để hôm nào engine
 * test đổi thì có người biết mà chuyển sang ghim bằng SQL thật.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Denomination;
use App\Models\Organization;
use App\Models\ShopOrderSetting;
use App\Omnify\Enums\OrderItemStatusEnum;
use App\Services\Order\Contracts\BranchOrderReads;
use App\Services\Order\Contracts\BranchOrderSettingsLock;
use App\Services\Order\Contracts\LockedBranchOrderSettings;
use App\Services\Order\Contracts\OrderTaxBreakdownReads;
use App\Services\Pos\TillSessionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);

    $this->reads = app(BranchOrderReads::class);
    $this->taxRates = app(OrderTaxBreakdownReads::class);
    $this->settingsLock = app(BranchOrderSettingsLock::class);

    // Ca két dưới đây mở bằng VND (giá trị đọc được dưới khoá), và
    // `assertDenominationCurrency()` chặn mệnh giá khác tiền tệ của ca — nên
    // mệnh giá phải là VND, không phải một tờ JPY.
    $this->vndNote = fn (): Denomination => Denomination::factory()->create([
        'currency_code' => 'VND',
        'value' => 1000,
        'kind' => 'note',
    ]);

    $this->makeOrder = fn (): CustomerOrder => CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'closed',
    ]);
});

// =============================================================================
//  1. 点数 — luật "bỏ dòng đã VOID" thuộc về Ordering
// =============================================================================

it('itemQuantityForOrders cộng số lượng và BỎ dòng đã void', function () {
    $order = ($this->makeOrder)();

    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'quantity' => 3,
        'status' => OrderItemStatusEnum::Served->value,
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'quantity' => 2,
        'status' => OrderItemStatusEnum::Pending->value,
    ]);
    // Dòng này KHÔNG được đếm — nếu đếm, 点数 trên phiếu ca gồm cả món đã huỷ.
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'quantity' => 99,
        'status' => OrderItemStatusEnum::Voided->value,
    ]);

    expect($this->reads->itemQuantityForOrders([(string) $order->id]))->toBe(5);
});

it('itemQuantityForOrders: tập rỗng trả 0 mà không truy vấn', function () {
    expect($this->reads->itemQuantityForOrders([]))->toBe(0);
});

it('itemQuantityForOrders chỉ đếm đơn được hỏi', function () {
    $mine = ($this->makeOrder)();
    $other = ($this->makeOrder)();

    CustomerOrderItem::factory()->create([
        'customer_order_id' => $mine->id, 'quantity' => 4, 'status' => OrderItemStatusEnum::Served->value,
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $other->id, 'quantity' => 7, 'status' => OrderItemStatusEnum::Served->value,
    ]);

    expect($this->reads->itemQuantityForOrders([(string) $mine->id]))->toBe(4);
});

// =============================================================================
//  2. 税率別 — hình dạng phải khớp workstation từng byte (plan-046 R7)
// =============================================================================

it('by_rate nhóm theo thuế suất, tăng dần, và bỏ dòng void', function () {
    $order = ($this->makeOrder)();

    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'quantity' => 1, 'subtotal' => 1000, 'tax_amount' => 100, 'tax_rate' => 10,
        'status' => OrderItemStatusEnum::Served->value,
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'quantity' => 1, 'subtotal' => 500, 'tax_amount' => 40, 'tax_rate' => 8,
        'status' => OrderItemStatusEnum::Served->value,
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'quantity' => 1, 'subtotal' => 9999, 'tax_amount' => 999, 'tax_rate' => 10,
        'status' => OrderItemStatusEnum::Voided->value,
    ]);

    $byRate = $this->taxRates->forOrders([(string) $order->id])['by_rate'];

    expect($byRate)->toBe([
        ['rate' => 8.0, 'taxable' => 500.0, 'tax' => 40.0],
        ['rate' => 10.0, 'taxable' => 1000.0, 'tax' => 100.0],
    ]);
});

it('by_rate: tập rỗng trả mảng rỗng', function () {
    expect($this->taxRates->forOrders([])['by_rate'])->toBe([]);
});

// =============================================================================
//  3. shop_order_settings — cổng KHOÁ-RỒI-ĐỌC
// =============================================================================

it('trả cả hai trường trong MỘT lần đọc', function () {
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'currency_code' => 'VND',
        'prices_include_tax' => true,
    ]);

    $settings = $this->settingsLock->lockAndReadForBranch((string) $this->branch->id);

    expect($settings)->toBeInstanceOf(LockedBranchOrderSettings::class)
        ->and($settings->currencyCode)->toBe('VND')
        ->and($settings->pricesIncludeTax)->toBeTrue();
});

it('chi nhánh chưa có dòng cấu hình → null, KHÔNG ném lỗi', function () {
    // Bản cũ dùng `->first()` và đi tiếp với `$shopSetting?->...`. Ném lỗi ở đây
    // sẽ chặn mở ca ở mọi chi nhánh có trước khi bảng ra đời.
    expect($this->settingsLock->lockAndReadForBranch((string) $this->branch->id))->toBeNull();
});

/**
 * GHI CHÚ TRUNG THỰC: nhánh `?? false` trong adapter KHÔNG được bài này phủ, và
 * không phủ được — `shop_order_settings.prices_include_tax` là NOT NULL ở schema
 * nên trạng thái NULL không dựng nổi bằng factory. Lá chắn giữ lại vì đây là PR
 * RANH GIỚI: chép nguyên biểu thức cũ, không nhân tiện "dọn" một thứ chưa chứng
 * minh được là thừa. Cái kiểm được là `false` đi qua cổng vẫn là `false` chứ
 * không thành `null` — tức cổng luôn trả `bool`, đúng như chữ ký khai.
 */
it('prices_include_tax false đi qua cổng vẫn là bool false', function () {
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'currency_code' => 'JPY',
        'prices_include_tax' => false,
    ]);

    expect($this->settingsLock->lockAndReadForBranch((string) $this->branch->id)->pricesIncludeTax)
        ->toBeFalse();
});

/**
 * Assertion CHỊU LỰC #1. Quét NGUỒN chứ không quét SQL đã chạy: trên SQLite câu
 * SQL không bao giờ chứa `for update`, nên một bài assert trên SQL sẽ xanh/đỏ vì
 * lý do không liên quan tới điều muốn ghim.
 */
it('adapter thật sự gọi lockForUpdate()', function () {
    $source = file_get_contents(app_path('Services/Order/Internal/EloquentBranchOrderSettingsLock.php'));

    expect(str_contains($source, '->lockForUpdate()'))->toBeTrue(
        'adapter không còn gọi `lockForUpdate()` — truy vấn vẫn chạy, không lỗi, và KHÔNG khoá gì. '
        .'Ca đua AUDIT FIX 3.6 (mở ca × PATCH settings currency_code) mở lại.',
    );
});

/**
 * Ghim GIẢ ĐỊNH của bài trên. Đỏ ở đây = engine test đã đổi, và người sửa biết
 * rằng giờ có thể ghim bằng SQL thật thay vì quét nguồn.
 */
it('trên SQLite builder KHÔNG sinh mệnh đề khoá — nên phải quét nguồn', function () {
    $sql = strtolower(DB::table('shop_order_settings')->where('branch_id', 'x')->lockForUpdate()->toSql());

    expect(str_contains($sql, 'for update'))->toBeFalse(
        'engine test đã sinh `for update` — giả định của bài quét-nguồn không còn đúng.',
    );
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'sqlite', 'chỉ có nghĩa trên SQLite');

/**
 * Assertion CHỊU LỰC #2 — CA ĐUA.
 *
 * `SELECT … FOR UPDATE` chạy NGOÀI transaction nhả khoá ngay khi câu lệnh xong:
 * lệnh chạy, không lỗi, và không tuần tự hoá gì cả. Nên điều phải ghim không chỉ
 * là "có gọi khoá" mà là "gọi khoá khi transaction ĐANG MỞ".
 *
 * Bài này thay cổng bằng một spy ghi lại `DB::transactionLevel()` tại đúng thời
 * điểm được gọi, rồi chạy `open()` thật. Ai đó kéo lời gọi ra ngoài
 * `DB::transaction()` — hoặc bọc adapter trong một transaction RIÊNG — sẽ đỏ ở
 * đây, không phải sáu tháng sau trên máy khách hàng.
 */
it('open() gọi cổng khoá khi transaction ĐANG MỞ', function () {
    $seen = new stdClass;
    $seen->levels = [];
    $seen->branchIds = [];

    app()->bind(BranchOrderSettingsLock::class, fn () => new class($seen) implements BranchOrderSettingsLock
    {
        public function __construct(private readonly stdClass $seen) {}

        public function lockAndReadForBranch(string $branchId): ?LockedBranchOrderSettings
        {
            $this->seen->levels[] = DB::transactionLevel();
            $this->seen->branchIds[] = $branchId;

            return new LockedBranchOrderSettings('VND', true);
        }
    });

    // Mệnh giá KHÔNG gắn currency_code: nó nhận tiền tệ của ca. Một tờ JPY thật
    // sẽ bị `assertDenominationCurrency()` chặn trong ca VND — đúng như thiết kế.
    $note = ($this->vndNote)();

    $session = app(TillSessionService::class)->open([
        'branch_id' => (string) $this->branch->id,
        'organization_id' => $this->orgId,
        'brand_id' => (string) $this->brand->id,
        'opening_counts' => [
            ['denomination_id' => (string) $note->id, 'quantity' => 2],
        ],
        'opened_by_id' => null,
        'opener_name' => 'ports-test',
    ]);

    expect($seen->levels)->not->toBeEmpty('open() không còn đi qua cổng khoá cấu hình chi nhánh');
    expect($seen->levels[0])->toBeGreaterThan(
        0,
        'cổng khoá được gọi NGOÀI transaction ⇒ `SELECT … FOR UPDATE` nhả khoá ngay, không khoá gì cả.',
    );
    expect($seen->branchIds[0])->toBe((string) $this->branch->id);

    // Và giá trị đọc dưới khoá thật sự được chụp lên ca — nếu không, cái khoá
    // đang bảo vệ một giá trị bị vứt đi.
    expect($session->default_currency_code)->toBe('VND')
        ->and((bool) $session->prices_include_tax_at_open)->toBeTrue();
});

it('open() chụp cấu hình THẬT của chi nhánh, không phải mặc định của két', function () {
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'currency_code' => 'VND',
        'prices_include_tax' => true,
    ]);

    $note = ($this->vndNote)();

    $session = app(TillSessionService::class)->open([
        'branch_id' => (string) $this->branch->id,
        'organization_id' => $this->orgId,
        'brand_id' => (string) $this->brand->id,
        'opening_counts' => [
            ['denomination_id' => (string) $note->id, 'quantity' => 1],
        ],
        'opened_by_id' => null,
        'opener_name' => 'ports-test',
    ]);

    expect($session->default_currency_code)->toBe('VND')
        ->and((bool) $session->prices_include_tax_at_open)->toBeTrue();
});
