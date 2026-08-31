<?php

declare(strict_types=1);

/**
 * #1653 — rào cho họ lỗi "cột ma": code nêu tên một cột không tồn tại.
 *
 * Bốn lỗi cùng khuôn trong một phiên — #1614, #1645 (Z-report khai doanh thu
 * chịu thuế là 非課税), #1651, và plan-043 T1.13 (mọi hoá đơn lưu tax_amount = 0).
 * Gốc chung: Eloquent trả `null` cho thuộc tính không tồn tại thay vì báo lỗi.
 *
 * Gate nằm ở TEST chứ không ở lệnh vì lệnh cần một DB SỐNG để đọc danh sách cột.
 * Chạy tay thì phụ thuộc `.env` từng máy — đã thử, `.env.example` trỏ vào một
 * MySQL không có thật và lệnh chết ngay. Ở đây Pest đã dựng sẵn sqlite in-memory
 * ĐÃ MIGRATE, tức đúng cái schema mà code phải khớp.
 */

use App\Console\Commands\PhantomColumnsCommand;
use Illuminate\Support\Facades\Artisan;

/**
 * Ngân sách CHỈ ĐƯỢC GIẢM, và **nay đã về 0** — mọi chỗ từng đếm đều đã hết:
 *
 * | chỗ | cột | đi đâu |
 * |---|---|---|
 * | `ShopTillTrackingService:1105` | `shop_order_settings.tax_rate` (đã DROP ở plan-043 T6.2) | sửa ở #1646 |
 * | `AutoCreateRecipesFromComponents:46` | `materials.components` (không migration nào tạo) | chết cùng lệnh `plan-022:auto-create-recipes` xoá ở #2507 |
 * | `EloquentDeviceDirectory:14` | `->pluck('device')` — **dương tính giả**, nằm trong DOCBLOCK | #2511 dạy bộ quét bóc comment |
 *
 * Từ đây 0 là mức sàn: thêm một chỗ nêu cột không tồn tại là đỏ ngay. Đừng nâng
 * ngân sách để làm xanh — cái đỏ đó chính là thứ #1653 dựng rào để bắt.
 */
const PHANTOM_COLUMN_BUDGET = 0;

function phantomColumnReport(): array
{
    Artisan::call('architecture:phantom-columns', ['--json' => true]);

    return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
}

it('không có tên cột nào không tồn tại trên chuỗi truy vấn của một model', function () {
    $report = phantomColumnReport();

    $detail = implode("\n", array_map(
        static fn (array $f): string => sprintf('  %s:%d  %s::…->%s(\'%s\')',
            $f['file'], $f['line'], $f['model'], $f['method'], $f['column']),
        array_slice($report['phantom'], 0, 25),
    ));

    expect($report['phantom_count'])->toBeLessThanOrEqual(PHANTOM_COLUMN_BUDGET, sprintf(
        "Có %d chỗ nêu một cột KHÔNG TỒN TẠI trên chuỗi truy vấn của model.\n".
        "Cột chuỗi trong query builder đi thẳng vào SQL — accessor/relation KHÔNG cứu được.\n\n%s",
        $report['phantom_count'], $detail,
    ));
});

it('bộ quét CÒN CHẠY — có phân giải được model, và có bỏ qua chuỗi mơ hồ', function () {
    // Rào chống ca "regex hỏng ⇒ 0 phát hiện ⇒ xanh, đọc y hệt đã sạch".
    $report = phantomColumnReport();

    expect($report)->toHaveKeys(['phantom_count', 'phantom', 'skipped_ambiguous', 'unresolved_models']);

    // Repo này CHẮC CHẮN có chuỗi mơ hồ (closure trong whereHas, join, pluck
    // trên collection). `skipped_ambiguous == 0` nghĩa là bộ dò đã hỏng.
    expect($report['skipped_ambiguous'])->toBeGreaterThan(20,
        'Không bỏ qua chuỗi mơ hồ nào — bộ dò hỏng, và con số 0 ở bài trên vô nghĩa.');
});

it('bắt được đúng khuôn #1645, và TỪ CHỐI đúng năm lớp nhiễu đã đo', function () {
    $command = new ReflectionClass(PhantomColumnsCommand::class);
    $mentions = $command->getMethod('columnMentions');
    $mentions->setAccessible(true);
    $instance = $command->newInstance();

    $scan = function (string $php) use ($mentions, $instance): array {
        $skipped = 0;
        $found = $mentions->invokeArgs($instance, [$php, &$skipped]);

        return array_map(fn ($m) => $m[0].'.'.$m[2], $found);
    };

    // ── BẮT: khuôn của #1645. `$session->branch_id` là ĐỌC THUỘC TÍNH, không
    //    phải lời gọi method, nên nó KHÔNG được kích hoạt dấu hiệu mơ hồ —
    //    chính chỗ này quyết định bộ quét còn giữ được ca thật hay không.
    expect($scan(<<<'PHP'
        $rate = ShopOrderSetting::query()
            ->where('branch_id', $session->branch_id)
            ->value('tax_rate');
        PHP))->toContain('ShopOrderSetting.tax_rate');

    // ── TỪ CHỐI 1: closure — `branch_id` thuộc bảng của QUAN HỆ.
    expect($scan(<<<'PHP'
        $x = MenuProductSku::query()
            ->whereHas('menuProduct.menu', fn ($q) => $q->where('branch_id', $id))
            ->first();
        PHP))->toBe([]);

    // ── TỪ CHỐI 2: chuỗi model LỒNG NHAU — `branch_id` thuộc Menu, không phải
    //    MenuProduct (model đứng gần nhất phía trước).
    expect($scan(<<<'PHP'
        $b = Menu::query()
            ->whereIn('id', MenuProduct::query()->whereKey($id)->select('menu_id'))
            ->value('branch_id');
        PHP))->toBe([]);

    // ── TỪ CHỐI 3: `pluck` trên COLLECTION nằm lọt trong span của chuỗi model.
    expect($scan(<<<'PHP'
        $m = CustomerOrder::query()
            ->whereIn('id', $affectedOrders->pluck('customer_order_id')->all())
            ->pluck('customer_id', 'id');
        PHP))->toBe([]);

    // ── TỪ CHỐI 4: join / alias — cột của bảng khác, hoặc `as cnt`.
    expect($scan(<<<'PHP'
        $r = PaymentRefund::query()
            ->selectRaw('count(*) as cnt')
            ->pluck('cnt');
        PHP))->toBe([]);

    // ── TỪ CHỐI 5 (#2511): COMMENT. Mô tả một lỗi đã sửa không phải là lỗi —
    //    đúng khuôn #1921 gọi tên ở `businessTimeCodeOnly()`. Cả docblock lẫn
    //    comment dòng, vì cả hai đều từng được đọc như mã.
    expect($scan(<<<'PHP'
        /** Chỗ gọi cũ làm `Device::query()->pluck('device')`, #1666 đã thay. */
        // và cả kiểu này: Device::query()->where('device', $x)
        $d = 1;
        PHP))->toBe([]);

    // ── …nhưng comment KHÔNG được che mã đứng ngay cạnh. Chuỗi dưới đây phải
    //    ra ĐÚNG HAI lượt nhắc — `branch_id` và `tax_rate` của phần MÃ. Lượt
    //    thứ ba (`->value('tax_rate')` trong comment) là thứ bản vá gỡ đi, nên
    //    `toBe` chặt hơn `toContain` ở đúng chỗ cần chặt.
    //    (`branch_id` là cột CÓ THẬT; `handle()` mới là chỗ đối chiếu với
    //    schema và loại nó — `columnMentions()` chỉ liệt kê.)
    expect($scan(<<<'PHP'
        /** Ghi chú vô hại có nhắc ->value('tax_rate') cho vui. */
        $rate = ShopOrderSetting::query()
            ->where('branch_id', $session->branch_id)
            ->value('tax_rate');
        PHP))->toBe(['ShopOrderSetting.branch_id', 'ShopOrderSetting.tax_rate']);
});

it('bóc comment mà KHÔNG dịch số dòng — báo cáo trỏ đúng chỗ', function () {
    $command = new ReflectionClass(PhantomColumnsCommand::class);
    $mentions = $command->getMethod('columnMentions');
    $mentions->setAccessible(true);
    $skipped = 0;

    // Chuỗi thật nằm ở dòng 5. Nếu `codeOnly()` cắt comment thay vì thay bằng
    // khoảng trắng, số dòng báo về sẽ nhỏ hơn — và một báo cáo trỏ nhầm dòng
    // còn tệ hơn không báo, vì người đọc mở đúng file và không thấy gì.
    $php = <<<'PHP'
        <?php
        /**
         * Docblock nhiều dòng để số dòng lệch thấy rõ nếu cắt bỏ.
         */
        $rate = ShopOrderSetting::query()->value('tax_rate');
        PHP;

    $found = $mentions->invokeArgs($command->newInstance(), [$php, &$skipped]);

    expect($found)->toHaveCount(1)
        ->and($found[0][3])->toBe(5, 'Số dòng lệch ⇒ comment đã bị CẮT chứ không bị thay bằng khoảng trắng.');
});
