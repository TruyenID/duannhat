<?php

declare(strict_types=1);

/**
 * #962 — bốn cổng công bố vừa gỡ năm cạnh module→module của Inventory/Catalog.
 *
 * Deptrac MỘT MÌNH không ghim được việc này. Baseline liệt kê từng vi phạm đã
 * tha, và các mục đó **vẫn nằm nguyên trong `deptrac-baseline.yaml`** sau khi nợ
 * được trả — đúng theo quy ước "file chỉ co lại, và co bằng một PR nói rõ vì
 * sao". Hệ quả: ai đó thêm lại `use App\Models\CustomerOrder;` vào
 * `RecallService` thì baseline **khớp lại** và deptrac im lặng cho qua. Cái
 * ratchet ở đây, không ở đó.
 *
 * Ba nhóm bài, và nhóm giữa mới là nhóm đắt:
 *
 *   1. cổng resolve được thật (bẫy #1544: interface không ai bind)
 *   2. HÀNH VI không đổi khi truy vấn dời sang chủ sở hữu dữ liệu — đây là chỗ
 *      một PR ranh giới làm hỏng tiền/kho mà suite vẫn xanh
 *   3. lớp tiêu thụ không còn nêu tên model của module khác
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\NotificationRule;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ShopOrderSetting;
use App\Modules\Notifications\Contracts\NotificationDispatcher;
use App\Omnify\Enums\StockDeductionTimingEnum;
use App\Services\Inventory\StockDeductionService;
use App\Services\Order\Contracts\BranchStockDeductionTiming;
use App\Services\Order\Contracts\OrderCustomerContacts;
use App\Services\Product\Contracts\SkuDirectory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// =============================================================================
//  1. Cổng phải resolve được — không chấp nhận đường ống rỗng (#1544)
// =============================================================================

it('P1: hai cổng Ordering mới có binding thật', function (string $port) {
    expect(app()->make($port))->toBeInstanceOf($port);
})->with([
    OrderCustomerContacts::class,
    BranchStockDeductionTiming::class,
]);

// =============================================================================
//  2. OrderCustomerContacts — hai câu hỏi của thu hồi
// =============================================================================

it('customerIdsByOrderId phân biệt "khách vãng lai" với "đơn không tồn tại"', function () {
    $customer = Customer::factory()->create();
    $registered = CustomerOrder::factory()->create(['customer_id' => $customer->id]);
    $guest = CustomerOrder::factory()->create(['customer_id' => null]);
    $ghost = (string) Str::uuid();

    $map = app(OrderCustomerContacts::class)->customerIdsByOrderId([
        (string) $registered->id,
        (string) $guest->id,
        $ghost,
    ]);

    /*
     * `RecallService` DỰA vào đúng khác biệt này: đơn khách vãng lai được đánh
     * dấu đã xử lý với `notification_id = null` (có hàng, giá trị null), còn id
     * không tra được thì không có khoá. Gộp hai ca thành cùng một `null` sẽ làm
     * một đơn đã bị xoá trông như một khách vãng lai đã được xử lý.
     */
    expect($map)->toHaveKey((string) $registered->id)
        ->and($map[(string) $registered->id])->toBe((string) $customer->id)
        ->and($map)->toHaveKey((string) $guest->id)
        ->and($map[(string) $guest->id])->toBeNull()
        ->and($map)->not->toHaveKey($ghost);
});

it('orderIdsWithReachableContact tính CẢ hai kênh, và không rò đơn ngoài phạm vi', function () {
    $customer = Customer::factory()->create();
    $byCustomer = CustomerOrder::factory()->create([
        'customer_id' => $customer->id,
        'customer_takeaway_phone' => null,
    ]);
    $byPhone = CustomerOrder::factory()->create([
        'customer_id' => null,
        'customer_takeaway_phone' => '090-0000-0000',
    ]);
    $unreachable = CustomerOrder::factory()->create([
        'customer_id' => null,
        'customer_takeaway_phone' => null,
    ]);
    /*
     * Cái bẫy THẬT của truy vấn này: `whereIn(...)->where(A)->orWhere(B)` viết
     * phẳng sẽ để `OR` cuốn luôn `whereIn`, và đơn dưới đây — có điện thoại
     * nhưng KHÔNG nằm trong danh sách hỏi — sẽ bị đếm vào. Diễn tập thu hồi khi
     * đó báo tỉ lệ liên lạc được cao hơn sự thật, tức báo một con số an toàn
     * giả. Cặp ngoặc là thứ chặn nó, nên nó phải có một bài test.
     */
    CustomerOrder::factory()->create([
        'customer_id' => null,
        'customer_takeaway_phone' => '090-1111-1111',
    ]);

    $asked = [(string) $byCustomer->id, (string) $byPhone->id, (string) $unreachable->id];
    $hit = app(OrderCustomerContacts::class)->orderIdsWithReachableContact($asked);

    expect($hit)->toHaveCount(2)
        ->and($hit)->toContain((string) $byCustomer->id)
        ->and($hit)->toContain((string) $byPhone->id)
        ->and($hit)->not->toContain((string) $unreachable->id);
});

it('danh sách rỗng không chạm DB và trả rỗng', function () {
    $port = app(OrderCustomerContacts::class);

    expect($port->customerIdsByOrderId([]))->toBe([])
        ->and($port->orderIdsWithReachableContact([]))->toBe([]);
});

// =============================================================================
//  3. BranchStockDeductionTiming — mặc định ở lại Inventory
// =============================================================================

it('cổng trả CHUỖI THÔ, không trả enum và không tự áp mặc định', function () {
    $branch = Branch::factory()->create();
    ShopOrderSetting::factory()->create([
        'branch_id' => $branch->id,
        'stock_deduction_timing' => 'on_add',
    ]);

    $raw = app(BranchStockDeductionTiming::class)->rawTimingFor((string) $branch->id);

    // Cột có cast enum ở model; cổng hứa `?string` nên phải ép về `->value`.
    expect($raw)->toBeString()->toBe('on_add');
});

it('chi nhánh chưa cấu hình ⇒ cổng null, và Inventory mới là bên hạ về on_close', function () {
    $branch = Branch::factory()->create();

    expect(app(BranchStockDeductionTiming::class)->rawTimingFor((string) $branch->id))->toBeNull()
        ->and(app(StockDeductionService::class)->timingForBranch((string) $branch->id))
        ->toBe(StockDeductionTimingEnum::OnClose);
});

it('thiếu hàng cấu hình và chi nhánh không tồn tại đều về on_close', function () {
    /*
     * Bất biến NGHIỆP VỤ, không phải chi tiết kỹ thuật: `on_close` là thời điểm
     * trừ kho MUỘN NHẤT. Hạ về nó khi không đọc được cấu hình là chọn phương án
     * không bao giờ trừ sớm hàng chưa bán. Một cổng "tiện tay" trả thẳng
     * `on_add` khi thiếu cấu hình sẽ trừ kho ngay lúc thêm món và không ai thấy
     * cho tới lúc kiểm kê.
     */
    $service = app(StockDeductionService::class);

    expect($service->timingForBranch((string) Branch::factory()->create()->id))
        ->toBe(StockDeductionTimingEnum::OnClose)
        ->and($service->timingForBranch((string) Str::uuid()))
        ->toBe(StockDeductionTimingEnum::OnClose);
});

it('cột chứa giá trị lạ vẫn NỔ như trước khi có cổng, không lặng lẽ về on_close', function () {
    /*
     * Đo được khi viết bài này: nhánh `tryFrom(...) ?? OnClose` trong
     * `timingForBranch` là **mã chết** và đã chết từ trước — cast enum của model
     * `from()` ngay lúc đọc, nên một giá trị lạ ném `ValueError` trước khi
     * `tryFrom` kịp chạy. Bản cũ (`->first([...])?->stock_deduction_timing`) và
     * bản mới (`->value(...)`, cũng đi qua cast) hành xử y hệt.
     *
     * Ghim nó ở đây thay vì "sửa" cho nó về `on_close`: nổ to là đúng cho một
     * cột cấu hình đã hỏng — im lặng chọn hộ một thời điểm trừ kho là đúng thứ
     * làm lệch tồn kho mà không ai biết. Đây là hành vi CŨ, không phải hành vi
     * PR này tạo ra.
     */
    $branch = Branch::factory()->create();
    ShopOrderSetting::factory()->create([
        'branch_id' => $branch->id,
        'stock_deduction_timing' => 'on_close',
    ]);
    // Ghi thô: cast enum chặn cả ĐƯỜNG GHI, nên một cột hỏng chỉ đến được từ
    // ngoài Eloquent (migration tay, sửa DB trực tiếp) — đúng ca đang ghim.
    DB::table('shop_order_settings')
        ->where('branch_id', $branch->id)
        ->update(['stock_deduction_timing' => 'on_full_moon']);

    expect(fn () => app(BranchStockDeductionTiming::class)->rawTimingFor((string) $branch->id))
        ->toThrow(ValueError::class)
        ->and(fn () => app(StockDeductionService::class)->timingForBranch((string) $branch->id))
        ->toThrow(ValueError::class);
});

// =============================================================================
//  4. SkuDirectory::getWithRecipeForOrganization — cái ném về với chủ model
// =============================================================================

it('getWithRecipeForOrganization ném ModelNotFoundException, giữ nguyên 404 cũ', function () {
    expect(fn () => app(SkuDirectory::class)->getWithRecipeForOrganization(
        (string) Str::uuid(),
        (string) Str::uuid(),
    ))->toThrow(ModelNotFoundException::class);
});

it('phạm vi tổ chức vẫn là RÀO, không phải bộ lọc trang trí', function () {
    /*
     * plan-040 C1 (TH.1): một công thức ĐƯỢC PHÉP tham chiếu SKU của tenant
     * khác. Nếu bản `get…` quên `whereHas('product', …)` thì nó vẫn trả snapshot
     * — và màn hình kế hoạch sản xuất in tên/tồn kho của tenant khác.
     */
    $otherOrg = Organization::factory()->create();
    $brand = Brand::factory()->create(['console_organization_id' => $otherOrg->id]);
    $product = Product::factory()->create([
        'organization_id' => $otherOrg->id,
        'brand_id' => $brand->id,
    ]);
    $sku = ProductSku::factory()->create(['product_id' => $product->id]);

    $directory = app(SkuDirectory::class);
    $mine = '00000000-0000-0000-0000-000000000001';

    expect($directory->getWithRecipeForOrganization((string) $sku->id, (string) $otherOrg->id)->id)
        ->toBe((string) $sku->id)
        ->and(fn () => $directory->getWithRecipeForOrganization((string) $sku->id, $mine))
        ->toThrow(ModelNotFoundException::class);
});

// =============================================================================
//  5. NotificationDispatcher::coversEmitter — cùng định nghĩa "che phủ"
// =============================================================================

it('coversEmitter khớp từng ca với NotificationRule::hasActiveCoverage', function () {
    $org = Organization::factory()->create();
    $dispatcher = app(NotificationDispatcher::class);

    expect($dispatcher->coversEmitter('Recipe', 'model.updated', (string) $org->id))->toBeFalse();

    // Luật BÓNG của M7: chỉ có `audience_name`, không có `audience_rule`.
    // KHÔNG tính là che phủ — nếu tính, bật NOTIFICATION_USE_RULES sẽ tắt lặng
    // thông báo duyệt công thức mà chưa có gì thay thế.
    $shadow = NotificationRule::factory()->create([
        'organization_id' => $org->id,
        'trigger_model_type' => 'Recipe',
        'trigger_event' => 'model.updated',
        'is_active' => true,
        'action' => ['audience_name' => 'recipe-owners'],
    ]);

    expect($dispatcher->coversEmitter('Recipe', 'model.updated', (string) $org->id))->toBeFalse()
        ->and(NotificationRule::hasActiveCoverage('Recipe', 'model.updated', (string) $org->id))->toBeFalse();

    $shadow->update(['action' => ['audience_rule' => ['role' => 'shop-manager']]]);

    expect($dispatcher->coversEmitter('Recipe', 'model.updated', (string) $org->id))->toBeTrue()
        ->and(NotificationRule::hasActiveCoverage('Recipe', 'model.updated', (string) $org->id))->toBeTrue();

    // Luật tắt ⇒ hết che phủ, kể cả khi audience_rule đầy đủ.
    $shadow->update(['is_active' => false]);

    expect($dispatcher->coversEmitter('Recipe', 'model.updated', (string) $org->id))->toBeFalse();
});

it('coversEmitter bị giới hạn theo tổ chức', function () {
    $mine = Organization::factory()->create();
    $theirs = Organization::factory()->create();
    NotificationRule::factory()->create([
        'organization_id' => $theirs->id,
        'trigger_model_type' => 'Recipe',
        'trigger_event' => 'model.updated',
        'is_active' => true,
        'action' => ['audience_rule' => ['role' => 'shop-manager']],
    ]);

    expect(app(NotificationDispatcher::class)->coversEmitter('Recipe', 'model.updated', (string) $mine->id))
        ->toBeFalse();
});

// =============================================================================
//  6. Ratchet — năm class tiêu thụ không được nêu lại tên model của module khác
// =============================================================================

it('P6: năm cạnh đã gỡ không được quay lại bằng một dòng `use`', function (array $case) {
    /*
     * Vì sao đọc mã nguồn chứ không hỏi deptrac: baseline vẫn còn giữ đúng năm
     * mục này (nó chỉ được phép co lại trong một PR nói rõ lý do), nên thêm lại
     * import sẽ KHỚP baseline và đi qua im lặng. Bài test này là cái ratchet
     * duy nhất giữa hai thời điểm đó.
     */
    [$file, $forbidden] = $case;
    $source = file_get_contents(base_path($file));

    /*
     * #1605 — bài này TỪNG viết `expect($source)->not->toContain($needle, $msg)`
     * và KHÔNG BAO GIỜ nổ: `toContain` là biến thiên (`...$needles`), nên chuỗi
     * giải thích bị đọc là needle thứ hai và `not` thoả mãn ngay vì đường dẫn
     * tuyệt đối không nằm trong mã nguồn. Năm ca dưới đây vì thế xanh kể cả khi
     * import đã quay lại — đo được bằng cách thêm lại `use` rồi chạy. Khẳng định
     * trên boolean là chỗ duy nhất message thật sự là message.
     */
    expect(str_contains($source, "use {$forbidden};"))
        ->toBeFalse($file." nhập lại {$forbidden} — cạnh #962 đã gỡ đang quay lại");
})->with([
    [['app/Services/Inventory/RecallService.php', CustomerOrder::class]],
    [['app/Services/Inventory/RecallDrillService.php', CustomerOrder::class]],
    [['app/Services/Inventory/StockDeductionService.php', ShopOrderSetting::class]],
    [['app/Services/Inventory/ProductionCalculatorService.php', ProductSku::class]],
    [['app/Services/Product/RecipeService.php', NotificationRule::class]],
]);
