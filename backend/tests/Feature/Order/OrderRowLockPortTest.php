<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Services\Order\Contracts\OrderRowLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * #1622 — cổng KHOÁ dòng đơn mà Ordering công bố.
 *
 * Khoá là thứ khó test nhất trong cả epic: nó **không có kết quả quan sát được**
 * ở một tiến trình đơn lẻ, và test chạy trên SQLite nơi `FOR UPDATE` gần như vô
 * nghĩa. Nên bài này ghim những thứ **thật sự kiểm được**, và nói rõ cái không:
 *
 *   1. adapter thật sự gọi `->lockForUpdate()` — quét NGUỒN, vì SQLite không
 *      sinh mệnh đề khoá nên SQL đã chạy không nói lên điều gì;
 *   2. giả định "SQLite không sinh `for update`" được ghim riêng, để nếu engine
 *      test đổi thì có người biết mà chuyển sang ghim bằng SQL thật;
 *   3. id ma không ném lỗi — giữ nguyên hành vi bản cũ (`->first()` trả null);
 *   4. câu truy vấn KHÔNG lọc `deleted_at` ⇒ đơn đã xoá mềm vẫn bị khoá. Đây là
 *      lý do adapter dùng `DB::table` chứ không `CustomerOrder::query()`, và là
 *      chỗ dễ "dọn code" nhất.
 *
 * **KHÔNG kiểm được ở đây**: hai request đồng thời có thật sự bị tuần tự hoá
 * không. Đó cần hai kết nối và một engine hỗ trợ khoá hàng (MySQL). Bài này
 * không giả vờ chứng minh điều đó.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);

    $this->lock = app(OrderRowLock::class);

    $this->makeOrder = function (): CustomerOrder {
        return CustomerOrder::create([
            'order_code' => 'L-'.Str::random(6),
            'order_type' => 'spot',
            'status' => 'open',
            'subtotal' => 0, 'discount_amount' => 0, 'service_charge' => 0,
            'tax_amount' => 0, 'total_amount' => 0, 'paid_amount' => 0, 'total_tip' => 0,
            'opened_at' => now(),
            'branch_id' => $this->branch->id,
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);
    };

    $this->captureQueries = function (callable $fn): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $fn();

            return DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
    };
});

it('có binding thật, không phải interface rỗng', function () {
    expect($this->lock)->toBeInstanceOf(OrderRowLock::class);
});

/**
 * Đây là assertion CHỊU LỰC của cả bài: bỏ `->lockForUpdate()` thì mọi thứ khác
 * vẫn xanh — truy vấn vẫn chạy, không lỗi, và **không khoá gì cả**.
 *
 * Kiểm bằng cách quét NGUỒN chứ không bằng SQL đã chạy, vì test chạy trên
 * SQLite mà `SQLiteGrammar::compileLock()` **trả chuỗi rỗng** (đã đọc
 * `vendor/.../Grammars/SQLiteGrammar.php:31`): câu SQL sinh ra ở đây KHÔNG bao
 * giờ chứa `for update`, dù code có gọi hay không. Một assertion trên SQL đã
 * chạy sẽ xanh/đỏ vì lý do không liên quan tới điều mình muốn ghim — đúng loại
 * test tệ hơn không có.
 */
it('adapter thật sự gọi lockForUpdate()', function () {
    $source = file_get_contents(app_path('Services/Order/Internal/EloquentOrderRowLock.php'));

    // `expect(...)->toContain($a, $b)` của Pest coi $b là MỘT GIÁ TRỊ NỮA phải
    // có mặt, không phải thông điệp — đã trả giá một lần ở epic này.
    expect(str_contains($source, '->lockForUpdate()'))->toBeTrue(
        'adapter không còn gọi `lockForUpdate()` — truy vấn vẫn chạy, không lỗi, và không khoá gì.',
    );
});

/**
 * Ghim luôn GIẢ ĐỊNH của bài trên: trên engine test (SQLite), cùng builder ấy
 * **không** sinh mệnh đề khoá — `SQLiteGrammar::compileLock()` trả chuỗi rỗng.
 * Nếu ngày nào đó engine test đổi và câu SQL bắt đầu có `for update`, bài này
 * đỏ và người sửa biết rằng có thể ghim bằng SQL thật thay vì quét nguồn.
 */
it('trên SQLite, builder KHÔNG sinh mệnh đề khoá — nên phải quét nguồn', function () {
    $sql = strtolower(DB::table('customer_orders')->where('id', 'x')->lockForUpdate()->toSql());

    expect(str_contains($sql, 'for update'))->toBeFalse(
        'engine test đã sinh `for update` — giả định của bài quét-nguồn không còn đúng, hãy ghim bằng SQL.',
    );
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'sqlite', 'chỉ có nghĩa trên SQLite');

it('id ma → không ném lỗi (giữ nguyên hành vi bản cũ)', function () {
    $this->lock->lockForUpdate((string) Str::uuid());
})->throwsNoExceptions();

/**
 * Adapter cố ý dùng `DB::table` chứ không `CustomerOrder::query()`: model
 * builder thêm `whereNull('deleted_at')`, nên một đơn đã xoá mềm sẽ **không bị
 * khoá** — đúng chỗ #821 A9 dựng lock để chặn hai lần phát hành đồng thời.
 */
it('vẫn khoá được dòng đã XOÁ MỀM — câu truy vấn KHÔNG lọc deleted_at', function () {
    $order = ($this->makeOrder)();
    $order->delete();

    $queries = ($this->captureQueries)(fn () => $this->lock->lockForUpdate((string) $order->id));

    $onOrders = array_values(array_filter(
        $queries,
        static fn (array $q): bool => str_contains(strtolower($q['query']), 'from "customer_orders"')
            || str_contains(strtolower($q['query']), 'from `customer_orders`'),
    ));

    expect($onOrders)->not->toBeEmpty('không thấy truy vấn nào chạm `customer_orders`');
    expect(str_contains(strtolower($onOrders[0]['query']), 'deleted_at'))->toBeFalse(
        'câu khoá lọc `deleted_at` ⇒ đơn đã xoá mềm KHÔNG bị khoá, đúng chỗ #821 A9 cần chặn.',
    );
    expect($onOrders[0]['bindings'])->toContain((string) $order->id);
});
