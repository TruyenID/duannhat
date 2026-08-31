<?php

use App\Console\Commands\DeptracConfigCommand;
use Illuminate\Support\Facades\Artisan;

/**
 * #1597 — ratchet cho lớp nợ mà deptrac KHÔNG NHÌN THẤY.
 *
 * `DB::table('menus')` trong Ordering không import class nào ⇒ không phải một
 * cạnh ⇒ **không nằm trong con số nào của #962**. Nó vẫn là một module đọc
 * thẳng bảng của module khác. Bài test này biến lỗ hổng đó thành một con số có
 * hàng rào, thay vì một thứ chỉ tìm thấy khi tình cờ đi ngang.
 *
 * Ngưỡng CHỈ ĐƯỢC GIẢM — cùng nghi thức với `SCC_VIOLATING_EDGE_BUDGET`.
 */
/**
 * ⚠️ Ngưỡng này ĐI LÊN đúng MỘT lần: 6 → 12 ở #1622, và **không phải vì có nợ
 * mới**. Bộ quét bản đầu (#1621) đòi dấu nháy đóng ngay sau tên bảng, nên mọi
 * truy vấn đặt bí danh — `DB::table('customer_order_items as coi')` — biến mất
 * khỏi phép đo. Có **40 chỗ** như vậy trong `app/`, nhiều hơn cả con số 21 mà
 * bản đầu báo cáo; sáu trong số đó là nợ xuyên module.
 *
 * Ngoài ca "phép đo nhìn thấy thêm", ngưỡng CHỈ ĐƯỢC GIẢM.
 */
/**
 * ✅ **VỀ 0 ở #1622** — trục nợ này đã đóng.
 *
 * Từ đây ngưỡng thôi làm bánh cóc và trở thành một luật tuyệt đối: **bất kỳ**
 * `DB::table('<bảng của module khác>')` mới nào cũng làm R2 đỏ ngay, không có
 * "trong ngân sách" để nấp. Đó là điểm khác biệt duy nhất giữa 0 và 1, và nó
 * đáng giữ — chỗ cuối cùng (`FloatingSectionPriceResolver`) tồn tại được lâu
 * đúng vì nó nằm dưới một ngưỡng dương.
 *
 * Nâng lại số này = mở lại cửa. Nếu một chỗ đọc thô mới thật sự chính đáng thì
 * nó thuộc về module SỞ HỮU bảng (adapter của chính module đó), không phải một
 * dòng ngân sách.
 */
const RAW_CROSS_MODULE_READ_BUDGET = 0;

/**
 * Bộ quét hỏng thì `cross_module_count` về 0, và một ngưỡng "≤ 21" sẽ **xanh**
 * — đọc y hệt "đã sửa xong hết". Đây là hàng rào chống đúng ca đó: số lần đọc
 * thô TRONG module (hợp lệ, không phải nợ) phải còn khác 0.
 *
 * #2374 bỏ comment/docblock làm phép đo thật 76 → 70. Sàn 30 vẫn thấp hơn xa
 * nhưng còn đủ bắt regex mất một lớp lớn, nên không hạ sàn chỉ để bản sửa xanh.
 */
const RAW_SAME_MODULE_FLOOR = 30;

function rawTableReadReport(): array
{
    Artisan::call('architecture:raw-table-reads', ['--json' => true]);

    return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
}

it('bỏ comment và docblock nhưng vẫn giữ lời gọi đọc bảng thật', function () {
    // #2786 — không ghi vào app/: PhantomColumnsCommand quét app/ song song.
    $root = sys_get_temp_dir().'/raw-table-reads-'.getmypid();
    $dir = $root.'/Services/Order/Internal';
    mkdir($dir, 0777, true);
    $fixture = $dir.'/RawTableReadsCommentFixture.php';
    file_put_contents($fixture, <<<'PHP'
<?php
namespace App\Services\Order\Internal;

/** Trước đây từng gọi DB::table('product_category') ở đây. */
final class RawTableReadsCommentFixture
{
    public function read(): void
    {
        DB::table('product_category')->first();
    }
}
PHP);
    try {
        Artisan::call('architecture:raw-table-reads', ['--json' => true, '--path' => $root]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $rows = array_values(array_filter(
            $report['cross_module'],
            static fn (array $row): bool => basename($row['file']) === basename($fixture),
        ));
        expect($rows)->toHaveCount(1, 'docblock phải bị bỏ, còn lời gọi thật vẫn phải được đếm')
            ->and($rows[0]['table'])->toBe('product_category');
    } finally {
        @unlink($fixture);
        @rmdir($dir);
        @rmdir($root.'/Services/Order');
        @rmdir($root.'/Services');
        @rmdir($root);
    }
});

it('R1: bộ quét còn CHẠY — đọc thô trong module vẫn khác 0', function () {
    $report = rawTableReadReport();

    expect($report['same_module_count'])->toBeGreaterThan(RAW_SAME_MODULE_FLOOR, sprintf(
        'Chỉ còn %d lần đọc thô trong module. Hoặc repo vừa đổi rất lớn, hoặc REGEX HỎNG — '.
        'và regex hỏng làm con số xuyên module về 0, đọc giống hệt "đã sửa xong hết".',
        $report['same_module_count'],
    ));
});

it('R2: đọc thô xuyên module chỉ được CO LẠI', function () {
    $report = rawTableReadReport();
    $count = $report['cross_module_count'];

    $detail = implode("\n", array_map(
        static fn (array $r): string => sprintf('  %s → %s  %s  (%s)', $r['reader'], $r['owner'], $r['table'], $r['file']),
        array_slice($report['cross_module'], 0, 25),
    ));

    expect($count)->toBeLessThanOrEqual(RAW_CROSS_MODULE_READ_BUDGET, sprintf(
        "Có %d chỗ đọc thô xuyên module, ngân sách %d. Một `DB::table('<bảng module khác>')` mới ".
        "KHÔNG bị deptrac bắt — nó chỉ hiện ra ở đây.\n%s",
        $count,
        RAW_CROSS_MODULE_READ_BUDGET,
        $detail,
    ));

    expect($count)->toBeGreaterThanOrEqual(RAW_CROSS_MODULE_READ_BUDGET, sprintf(
        'TIN TỐT — còn %d chỗ đọc thô xuyên module, ngân sách vẫn ghi %d. Hạ xuống.',
        $count,
        RAW_CROSS_MODULE_READ_BUDGET,
    ));
});

/**
 * Con số trên là **cận dưới**, và bài test ghim luôn điều đó: bảng không có
 * model (pivot thuần) không tra được chủ sở hữu. Nếu ai đó thêm model cho
 * `product_category`, mục này rỗng đi và con số xuyên module có thể TĂNG — đó
 * là phép đo tốt lên, không phải nợ mới, và người sửa cần đọc được điều đó ở
 * đây thay vì đoán.
 */
/**
 * #1622 — bản đầu của bộ quét (#1621) **báo thừa 5 chỗ**: nó tính cả đọc bảng
 * TenancyKernel (`organizations`/`brands`/`branches`) là nợ, trong khi mọi
 * module được phép phụ thuộc kernel. Một phép đo CHẶT HƠN đồ thị tầng cũng sai
 * như một phép đo lỏng hơn — nó đòi trả khoản nợ không tồn tại.
 *
 * Bài này ghim hai chiều: kernel vẫn được nhận diện (đếm > 0), và danh sách
 * kernel của bộ quét **không lệch** khỏi cái generator dùng.
 */
it('R4: bảng TenancyKernel không bị tính là nợ, và danh sách không lệch', function () {
    $report = rawTableReadReport();

    expect($report['tenancy_kernel_count'])->toBeGreaterThan(0,
        'Không nhận ra chỗ nào đọc bảng TenancyKernel. Hoặc repo hết thật, hoặc bộ quét '.
        'thôi tra danh sách kernel — và khi đó 5 chỗ hợp lệ sẽ quay lại thành "nợ".',
    );

    $kernel = (new ReflectionClass(DeptracConfigCommand::class))->getConstant('TENANCY_KERNEL');
    expect($kernel)->toBeArray()->not->toBeEmpty(
        'Bộ quét đọc danh sách kernel bằng reflection từ hằng số này. Đổi tên hằng số '.
        'mà không sửa bộ quét ⇒ nó lặng lẽ coi mọi bảng kernel là nợ trở lại.',
    );

    foreach ($report['cross_module'] as $row) {
        expect(in_array($row['table'], ['organizations', 'brands', 'branches', 'branch_translations', 'users'], true))
            ->toBeFalse(sprintf('bảng kernel `%s` bị tính là nợ (%s → %s)', $row['table'], $row['reader'], $row['owner']));
    }
});

/**
 * #1622 — hàng rào cho điểm mù đã trả giá: bí danh bảng.
 *
 * Không có bài này, ai đó "dọn" regex về dạng đơn giản sẽ làm ~40 truy vấn biến
 * mất khỏi phép đo, con số xuyên module tụt xuống, và **mọi ngưỡng vẫn xanh** —
 * đọc y hệt một lần trả nợ.
 *
 * Bản ĐẦU của bài này ghim một bảng cụ thể (`customer_order_items`), và nó **đỏ
 * ngay trong PR trả nợ kế tiếp** vì chính khoản nợ đó vừa được trả. Bài học:
 * một hàng rào cho **cơ chế đo** không được neo vào một **khoản nợ**, vì nợ thì
 * biến mất còn cơ chế thì phải sống. Neo lại vào tổng số truy vấn đặt bí danh —
 * chỉ về 0 khi regex hỏng hoặc repo hết sạch bí danh.
 * #2374 đo lại sau khi bỏ comment: 46 → 44, vẫn qua sàn 20 mà không nới rào.
 */
it('R5: truy vấn đặt BÍ DANH vẫn được đếm', function () {
    $report = rawTableReadReport();

    expect($report['aliased_read_count'])->toBeGreaterThan(20, sprintf(
        'Chỉ đếm được %d truy vấn thô đặt bí danh. Regex đã thôi nhận `\'x as y\'` ⇒ '.
        'hàng chục truy vấn biến mất khỏi phép đo mà không có gì đỏ lên.',
        $report['aliased_read_count'],
    ));
});

/**
 * ⚠️ Bản đầu neo vào một BẢNG CỤ THỂ: `product_category` phải nằm trong
 * `unowned_tables`, vì pivot đó không model nào nhận nên không tra được chủ.
 *
 * Nó đỏ ở #2371 — và đỏ ĐÚNG. Omnify 5.9.21 (upstream omnify-go#158) sửa
 * `$table` của `CategoryProductBaseModel` từ `category_products` (một bảng
 * KHÔNG TỒN TẠI) về `product_category`, nên bảng có chủ và rơi khỏi danh sách.
 * Đúng kịch bản mà chính thông điệp của bản đầu đã dự đoán.
 *
 * Bài học thì y hệt R5 ngay trên: **hàng rào cho CƠ CHẾ đo không được neo vào
 * một KHOẢN NỢ**, vì nợ thì biến mất còn cơ chế phải sống. Neo lại vào bất biến
 * thật sự cần: *mọi bảng bộ quét đọc tới đều tra được chủ*.
 *
 * Vì sao bất biến đó bảo vệ R2: nếu bản đồ chủ sở hữu hỏng, mọi bảng thành vô
 * chủ, `cross_module_count` tụt về 0 và **R2 xanh** — đọc y hệt "sạch nợ". Giữ
 * `unowned_tables` ở 0 làm ca đó đỏ ở đây trước.
 */
it('R3: báo rõ phần KHÔNG đo được, không nuốt im lặng', function () {
    $report = rawTableReadReport();

    expect($report)->toHaveKey('unowned_tables')
        ->and($report)->toHaveKey('unparseable_raw_sql');

    expect($report['unowned_tables'])->toBeEmpty(sprintf(
        "Có %d bảng không tra được chủ: %s\n".
        'Hoặc một bảng mới chưa có model nào nhận, hoặc bản đồ chủ sở hữu vừa hỏng. '.
        'Ca thứ hai nguy hiểm hơn: nó kéo `cross_module_count` về 0 và làm R2 xanh oan.',
        count($report['unowned_tables']),
        implode(', ', array_slice((array) $report['unowned_tables'], 0, 10)),
    ));
});
