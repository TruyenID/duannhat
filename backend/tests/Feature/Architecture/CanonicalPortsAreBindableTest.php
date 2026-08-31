<?php

declare(strict_types=1);

/**
 * #1544 — a boundary port must RESOLVE, not merely exist.
 *
 * `DomainMutationContractsTest` guards the canonical facade/persistence/query
 * triplet, but it reflects over method signatures and never asks the container
 * for an instance. So `OrderQueryPort` lived for months as an empty pipe: no
 * implementation, no binding, `app()->make()` throwing — while the triplet
 * looked complete and the suite stayed green.
 *
 * The cost was not theoretical. Every module that needed to READ an order
 * imported `App\Models\CustomerOrder` instead, because the correct route did
 * not work. Ten files in Payments alone (#1544), and the boundary debt was
 * blamed on those callers rather than on the missing implementation.
 *
 * An interface nobody can resolve is not a boundary. It is decoration.
 *
 * This test lives in Feature, not Unit, on purpose: Unit tests here do not boot
 * the framework, so a container assertion there passes or fails for reasons that
 * have nothing to do with the binding.
 */

use App\Services\Customer\Contracts\CustomerMutationFacade;
use App\Services\Menu\Contracts\MenuMutationFacade;
use App\Services\Menu\Contracts\MenuQueryPort;
use App\Services\Order\Contracts\OrderMutationFacade;
use App\Services\Order\Contracts\OrderQueryPort;
use App\Services\Payment\Orchestration\Contracts\PaymentQueryPort;
use App\Services\Product\Contracts\ProductMutationFacade;

it('P1: mọi mutation facade resolve được từ container', function (string $port) {
    expect(app()->make($port))->toBeInstanceOf($port);
})->with([
    OrderMutationFacade::class,
    ProductMutationFacade::class,
    // #1550 — hai facade này vừa rời `UNIMPLEMENTED_BY_DESIGN`. Chính test P3
    // dặn: "xoá nó khỏi UNIMPLEMENTED_BY_DESIGN và thêm vào P1".
    MenuMutationFacade::class,
    CustomerMutationFacade::class,
]);

/**
 * Ranh giới ĐÃ VẼ MÀ CHƯA XÂY (#1546).
 *
 * Hai facade dưới đây không phải "quên bind" — chúng chưa có gì để bind. Đo
 * được:
 *
 *   MenuMutationFacade      ĐÃ XÂY ở #1550 — không còn trong danh sách.
 *                           Mô tả cũ: 54 method, 0 class implement,
 *                           `app/Services/Menu/` chỉ có Commands/Contracts/
 *                           Enums/ValueObjects. Nay có `MenuMutationService`
 *                           (mỏng) + `EloquentMenuPersistence`, và persistence
 *                           UỶ QUYỀN cho sáu service menu đang chạy production
 *                           thay vì viết lại đường ghi — cùng khuôn
 *                           `ProductService` + `EloquentProductPersistence`.
 *
 *   CustomerMutationFacade  15 method, 0 class implement. Các Command trong
 *                           `app/Services/Customer/Commands/` chỉ được tham
 *                           chiếu bởi chính các Contract cùng thư mục — một
 *                           vòng khép kín interface trỏ vào nhau, không có
 *                           code sản xuất. `CustomerAuthService` và
 *                           `CustomerService` dùng 0 Command.
 *
 * Xây Customer = chuyển hai service sang hình dạng command/facade, và nó còn
 * chặn bởi ba câu hỏi miền nghiệp vụ chưa ai trả lời (`merge` gộp khách theo
 * luật nào; `linkScope`/`unlinkScope` là gì) — `merge` thiếu cả schema.
 * Giả vờ implement để cổng xanh thì tệ hơn hẳn việc ghi ra sự thật.
 *
 * @var list<class-string>
 */
// Xây thật hai ranh giới này: #1550 (đo được 69 method facade + 70 method
// persistence port, hành vi trải trên 6 class Menu và 2 class Customer, và 4
// method Customer chưa từng tồn tại ở đâu — riêng `merge` còn thiếu cả schema).
/*
 * #1550 — danh sách nay RỖNG. Cả hai facade đã xây:
 *
 *   MenuMutationFacade      54 method → MenuMutationService + EloquentMenuPersistence
 *   CustomerMutationFacade  15 method → CustomerMutationService + bốn cổng
 *
 * Giữ lại hằng số (rỗng) thay vì xoá: bánh cóc ngược dưới đây là thứ bắt cả hai
 * lần tôi phải sửa dòng này — nó đỏ ngay khi một facade bind được. Xoá hằng số
 * là xoá luôn cái cơ chế ấy, và lần "chưa xây" sau sẽ lại vô hình.
 */
const UNIMPLEMENTED_BY_DESIGN = [];

it('P3: danh sách "chưa xây" chỉ được CO LẠI', function () {
    /*
     * Bánh cóc ngược: ĐỎ khi ai đó implement facade mà quên xoá tên khỏi danh
     * sách. Nếu chỉ liệt kê để bỏ qua, danh sách sẽ sống mãi và không ai biết
     * lúc nào nợ đã trả xong.
     *
     * #1550 — lặp trong THÂN chứ không qua `->with()`: dataset rỗng làm Pest
     * báo *"Empty data set provided by data provider"* và test ĐỎ vì lý do
     * không liên quan gì tới nợ. Danh sách rỗng phải là trạng thái hợp lệ —
     * đó chính là đích của bánh cóc.
     */
    foreach (UNIMPLEMENTED_BY_DESIGN as $port) {
        expect(interface_exists($port))->toBeTrue("{$port} không còn tồn tại — xoá khỏi UNIMPLEMENTED_BY_DESIGN");

        $bound = false;
        try {
            app()->make($port);
            $bound = true;
        } catch (Throwable) {
            // vẫn chưa xây — đúng như khai báo
        }

        expect($bound)->toBeFalse(
            "{$port} GIỜ ĐÃ bind được — xoá nó khỏi UNIMPLEMENTED_BY_DESIGN và thêm vào P1 (#1546)."
        );
    }

    expect(UNIMPLEMENTED_BY_DESIGN)->toBe([], 'danh sách chỉ được co lại — thêm tên vào đây là ghi nhận một ranh giới VẼ MÀ CHƯA XÂY');
});

it('P2: mọi query port resolve được — không chấp nhận đường ống rỗng', function (string $port) {
    /*
     * Đây là assertion mà cổng cũ thiếu. `OrderQueryPort` từng qua được mọi
     * kiểm tra trong khi không ai implement nó.
     */
    expect(app()->make($port))->toBeInstanceOf($port);
})->with([
    OrderQueryPort::class,
    PaymentQueryPort::class,
    // #1550 — `MenuQueryPort` vào danh sách khi nó có hiện thực. Trước đó nó là
    // đúng cái "đường ống rỗng" mà chính test này sinh ra để bắt, nhưng danh
    // sách lại không có tên nó — nên cổng không nhìn thấy chính thứ nó canh.
    MenuQueryPort::class,
]);
