<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Module ownership manifest (#962 Phase 0)
|--------------------------------------------------------------------------
|
| Đây là SỔ SỞ HỮU của modular monolith: mỗi class trong app/ thuộc về đúng
| một module. Manifest này CHƯA di chuyển file nào — nó chỉ khai ai sở hữu
| cái gì. Deptrac được SINH RA từ file này (`php artisan architecture:deptrac-config`)
| nên nó đo được coupling thật giữa các bounded context mà không ai phải đụng
| vào code — và `App\Modules\Kernel\ModuleRegistry` đọc CÙNG file này để boot
| module, nên ranh giới đo được và ranh giới lúc chạy không thể lệch nhau.
|
| Vì sao khai bằng tên model chứ không bằng namespace: 176 model đều nằm
| phẳng trong `App\Models`, nên namespace không mang thông tin sở hữu. Danh
| sách dưới đây LÀ quyết định phân chia — sửa nó là sửa ranh giới.
|
| Class không khớp luật nào rơi vào `Unassigned` và bị đếm; ngân sách
| Unassigned nằm trong baseline test, không cho phép phình ra.
|
*/

return [

    /*
    | Delivery surface = adapter, KHÔNG phải module. Controller/Request/
    | Resource dưới các namespace này được gán `surface:<tên>` để đo fan-out
    | (một service bị bao nhiêu surface gọi thẳng), chứ không tính là vi phạm
    | ranh giới module↔module.
    */
    'surfaces' => [
        'App\Http\Controllers\Api\V1\Customer' => 'customer',
        'App\Http\Controllers\Api\V1\HQ' => 'hq',
        'App\Http\Controllers\Api\V1\Shop' => 'shop',
        'App\Http\Controllers\Api\V1\Pos' => 'pos',
        'App\Http\Controllers\Api\V1\Kiosk' => 'kiosk',
        'App\Http\Controllers\Api\V1\Kds' => 'kds',
        'App\Http\Controllers\Api\V1\Handy' => 'handy',
        'App\Http\Controllers\Api\V1\Workstation' => 'workstation',
        'App\Http\Controllers\Api\V1\Tms' => 'tms',
        'App\Http\Controllers\Api\V1\Inventory' => 'inventory-ui',
        'App\Http\Controllers\Api\V1\Device' => 'device',
        'App\Http\Controllers\Api\Dev' => 'dev',
        'App\Mcp' => 'mcp',
        'App\Console\Commands' => 'console',
    ],

    /*
    | Hạ tầng dùng chung — không thuộc bounded context nào và được phép bị mọi
    | module import. Giữ danh sách này NGẮN: mỗi mục thêm vào đây là một chỗ
    | ranh giới không còn được kiểm.
    */
    /*
     * Class LẺ thuộc SharedKernel dù namespace của chúng thuộc module khác (#1553).
     *
     * Tiêu chí ở đây KHÁC tiêu chí kernel tenancy của #1537. Ở đó câu hỏi là
     * "có phải mỏ neo định danh không" và đo bằng số file ngoài import. Ở đây
     * câu hỏi là "có phải HẠ TẦNG CẮT NGANG không", và một thứ thuộc về nhóm
     * này khi CẢ HAI đúng:
     *
     *   1. nó không có hành vi miền riêng mà module nào đó có thể sở hữu, và
     *   2. mọi module cần nó vì BẢN CHẤT là một module, không vì một quan hệ
     *      tính năng cụ thể.
     *
     * AuditLog            42 lần / 22 class trải mọi module. Ghi vết là việc
     *                     mọi module làm khi mutate — `App\Traits\AuditsActivity`
     *                     vốn đã nằm trong `shared` và dùng chính nó.
     * UserWorkspaceAccess 42 lần / 42 class, mỗi class đúng một lần, phần lớn
     *                     là Policy. Đây là nguyên thuỷ phân quyền: mọi policy
     *                     đều hỏi "user này có quyền ở workspace này không".
     *
     * Device (27 file) CỐ Ý không có ở đây: nó có vòng đời riêng — pair, cấp
     * token, thu hồi — nên là nợ thật, không phải hạ tầng. Giữ nó ngoài để
     * danh sách này không trở thành chỗ đổ mọi thứ bất tiện.
     */
    /*
    |--------------------------------------------------------------------------
    | Công tắc rollback của pilot (#1360 — #962 Phase 2)
    |--------------------------------------------------------------------------
    |
    | Pilot chạy trên một endpoint ĐANG SỐNG, nên nó phải tắt được mà không cần
    | deploy code mới và không đụng vào dữ liệu. Tắt = URL cũ trỏ lại controller
    | cũ, cùng payload, cùng auth (xem routes/api.php).
    |
    | KHÔNG phải "không cần lệnh nào": production chạy `route:cache` + `config:cache`
    | (deploy-xserver.yml), nên lựa chọn bị đóng băng vào cache. Đổi env xong phải
    | `config:clear && config:cache` rồi `route:clear && route:cache`. Nói gọn thành
    | "chỉ cần bật/tắt" là kiểu tuyên bố bị phát hiện sai đúng lúc cần dùng nhất.
    |
    | Mặc định BẬT là cố ý: pilot không có giá trị nếu không có ai đi qua nó.
    | Đây không phải cờ bypass/debug — tắt nó chỉ đưa hệ thống về đường cũ vốn
    | vẫn đang được test, nên mặc định bật không mở thêm quyền gì.
    */
    'shared_classes' => [
        /*
         * #962 — HẠ TẦNG CSV. Ba class này không có một dòng nghiệp vụ nào:
         * `CsvExporter` chỉ biết `Builder` + `StreamedResponse` và tự stream
         * theo chunk; `CsvImporter` chỉ biết `UploadedFile` + transaction;
         * `ImportResult` là một DTO đếm dòng. Kiểm bằng grep chứ không đoán:
         * `grep '^use App' → rỗng` ở cả ba.
         *
         * Chúng nằm trong `App\Services\{Import,Export}` (⇒ Catalog) là DI SẢN
         * của thời mọi file CSV đều là danh mục. #962 đưa `MaterialExporter` /
         * `MaterialImporter` về Inventory — chúng là CSV của NGUYÊN VẬT LIỆU —
         * và lúc đó lớp cha thành thứ hai module cùng kế thừa. Để nó ở Catalog
         * thì Inventory "vi phạm" khi extend, và con số đó không nói lên gì thật
         * (đúng lý do `App\Modules\Kernel` đã là `shared` từ #1359).
         */
        'App\Services\Export\CsvExporter',
        'App\Services\Import\CsvImporter',
        'App\Services\Import\ImportResult',
        // #1360 — PHẢI ở đây chứ không phải trong 'shared'. Mục trong 'shared' được
        // hiểu là TIỀN TỐ NAMESPACE, nên `App\Http\Controllers\Controller` viết ở
        // đó sinh ra `#^App\Http\Controllers\Controller\\#` và không bao giờ khớp
        // chính cái class ấy — ý định "lớp cha dùng chung" đã được khai từ trước mà
        // KHÔNG có hiệu lực nào, im lặng, cho tới khi module đầu tiên extend nó.
        'App\Http\Controllers\Controller',
    ],

    /*
     * #1574 — class LẺ thuộc TENANCY KERNEL, không phải SharedKernel.
     *
     * `SharedKernel: ~` khai rằng hạ tầng dùng chung không phụ thuộc vào gì. Hai
     * class dưới đây vi phạm lời khai đó **theo bản chất, không theo cẩu thả**:
     *
     *  - `AuditLog` là MODEL, và quan hệ `user()` của nó (plan-036, để trang chi
     *    tiết ca két render "{action} by {actor.name}") bắt buộc nhắc tới `User`.
     *  - `UserWorkspaceAccess` phân giải quyền của một user trên org/brand/branch.
     *    Toàn bộ đầu vào và đầu ra của nó là bốn neo tenancy — nó KHÔNG phải hạ
     *    tầng chung tình cờ chạm tenancy, nó CHÍNH LÀ việc phân giải tenancy.
     *
     * Vì sao không nới `SharedKernel` cho phụ thuộc TenancyKernel: làm thế là hợp
     * thức hoá chu trình ở ĐÁY đồ thị (`TenancyKernel → SharedKernel` đã có sẵn),
     * tức bài C3 trong `LayerCyclesTest` sẽ xanh vì luật đổi chứ không vì nợ trả.
     *
     * Vì sao không kéo cả `App\Services\Iam` vào: #1537 đã ghi lý do và nó vẫn
     * đứng — *"pulling the whole cluster in would turn the kernel into a bin where
     * anything inconvenient gets dropped"*. Đây là danh sách class LẺ, và mỗi mục
     * phải tự biện minh bằng "mọi thứ nó chạm đều là neo tenancy".
     */
    /*
     * #1596 — class LẺ thuộc COMPOSITION.
     *
     * `CustomerOrderHistoryService` chỉ làm một việc: dựng truy vấn lịch sử đơn
     * cho màn hình khách. Đo theo đúng hai tiêu chí của #1591:
     *
     *   · 0 cạnh VÀO từ module — consumer duy nhất là `CustomerOrderHistoryController`
     *   · 0 lời gọi GHI
     *
     * Khai cả namespace `App\Services\Customer` là SAI — phần còn lại của nó
     * (`CustomerService`, `CustomerAuthService`…) ghi dữ liệu và thuộc
     * CustomerEngagement thật. Nên đây là danh sách class lẻ, không phải namespace.
     */
    'composition_classes' => [
        'App\Services\Customer\CustomerOrderHistoryService',
    ],

    'tenancy_kernel_classes' => [
        'App\Models\AuditLog',
        // Trait ghi vết — nó TỒN TẠI để tạo `AuditLog`, nên buộc phải nhắc tới
        // model đó. Đi cùng `AuditLog` là bắt buộc: để lại SharedKernel thì nó
        // thành cạnh SharedKernel → TenancyKernel duy nhất còn lại.
        'App\Traits\AuditsActivity',
        'App\Services\Iam\UserWorkspaceAccess',
    ],

    'shared' => [
        // Kernel của module (#1359) — mọi module kế thừa nó, nên nó phải là hạ tầng
        // dùng chung; nếu để nó thuộc một module thì tám module còn lại lập tức
        // "vi phạm" khi extend, và con số đó không nói lên điều gì thật.
        'App\Modules\Kernel',
        'App\Support',
        'App\Casts',
        'App\Concerns',
        'App\Traits',
        'App\Rules',
        'App\Providers',
        'App\Exceptions',
        'App\Architecture',
        'App\OpenApi',
        'App\Omnify\Enums',
        'App\Services\Omnify',
        'App\Services\DomainMutation',
        'App\Http\Middleware',
        'App\Models\Concerns',
    ],

    /*
     * #1596 — HỢP ĐỒNG CÔNG BỐ.
     *
     * Ruleset mặc định coi MỌI cạnh module→module là vi phạm, kể cả cạnh trỏ
     * vào một cổng vừa được dựng đúng cách. Đo trên `dev`: 19/597 vi phạm còn
     * lại trỏ vào một class `\Contracts\`. Nghĩa là epic này đang tự cộng vào
     * con số nó đang kéo xuống, và DoD "allowlist = 0" không thể đạt chừng nào
     * các module còn cộng tác với nhau — trừ khi có chỗ hợp lệ để cộng tác.
     *
     * Thực nghiệm rẻ nhất đã chạy trước khi làm cái này: cho `ProductSkuService`
     * (Catalog) đọc `OrderStatusVocabulary::OPEN` thay vì
     * `CustomerOrder::OPEN_STATUSES` → tổng đứng nguyên **597**. Đi qua cổng chỉ
     * đổi HÌNH DẠNG cạnh, không đổi con số.
     *
     * Hai luật giữ cho cái cửa này không thành lỗ hổng:
     *
     *  1. Danh sách TƯỜNG MINH, không quét theo thư mục. "Để vào folder
     *     Contracts là hết bị soi" biến rào thành thủ tục.
     *  2. `PublishedContracts` chỉ được phụ thuộc hai kernel — KHÔNG module nào,
     *     KHÔNG hợp đồng công bố nào khác. Nên một contract mang model Eloquent
     *     của chủ sở hữu trong chữ ký sẽ ĐỎ ngay tại đây. Đó là bản cưỡng chế
     *     máy móc của câu "cổng không được rò model".
     *
     * Vì luật 2, danh sách này KHÔNG gồm `CouponPricing` (nhận `Coupon`,
     * `App\Models\Coupon` thuộc Pricing) hay `OrderMutationFacade` (nhận
     * command mang model). Chúng chưa đủ điều kiện — và biết được điều đó là
     * một kết quả, không phải một thiếu sót.
     */
    'published_contracts' => [
        // #1597 — KIỂU KẾT QUẢ của Pricing. Ba value object thuần, tổng 70 dòng,
        // **không import gì cả** (kiểm: `grep '^use ' → rỗng`).
        //
        // Ordering nhận 30 cạnh vào ba class này. Đó KHÔNG phải nợ theo hướng:
        // ADR 0001 và CLAUDE.md đều nói Pricing sở hữu tính toán còn Ordering
        // tiêu thụ, và #1581 cố ý TẠO RA chiều này. Nhưng trước khi công bố,
        // "tiêu thụ đúng cách" vẫn bị đếm y như "chạm bừa".
        //
        // Đây đúng là thứ `PublishedContracts` sinh ra để đựng: kiểu kết quả mà
        // module tính toán trao cho module tiêu thụ. Không phải engine —
        // `TaxResolver` (222 dòng, import 5 model) KHÔNG vào, và luật "không phụ
        // thuộc module nào" tự loại nó.
        'App\Services\Customer\PricingResult',
        // #2063 — Ordering hỏi Payments "đơn này còn nợ ghi sổ chưa tất toán
        // chưa?" trước khi cho in biên lai / hoá đơn đỏ. Câu trả lời nằm ở
        // `order_payments` + `payment_methods` do Payments sở hữu, nên câu hỏi
        // vượt ranh giới module và phải đi qua một cổng CÔNG BỐ, chỉ đọc — thay
        // vì một lượt đọc thô bảng của module khác.
        //
        // Cùng khuôn với cổng `KitchenTicketLookup` từng dựng cho #2522: một
        // interface hẹp, trả sự kiện đã diễn giải, không phơi hàng thô.
        'App\Services\Payment\Contracts\OpenAccountDebtReads',
        // Kiểu KẾT QUẢ của cổng trên. VO thuần: 56 dòng, **0 import**
        // (`grep '^use ' → rỗng`) — đúng tiêu chí đã dùng cho `PricingResult`.
        // Công bố cổng mà không công bố kiểu nó trả về thì cổng vẫn không
        // dùng được: deptrac chặn ở chính hợp đồng.
        'App\Services\Payment\Contracts\OpenAccountDebt',
        'App\Services\Customer\TaxGroup',
        'App\Services\Customer\TaxResolution',
        'App\Modules\Notifications\Contracts\NotificationDispatcher',
        'App\Modules\Notifications\Contracts\NotificationRequest',
        // #1567 — cổng đọc CÔNG THỨC của Catalog. `RecipeSnapshot` chỉ import
        // `App\Omnify\Enums\ApprovalStatusEnum` (đã là `shared`), nên nó thoả
        // luật "không phụ thuộc module nào".
        //
        // Khai từng class chứ không khai cả `App\Services\Product\Contracts`:
        // trong đó còn `ProductMutationFacade`/`ProductPersistencePort` nhận
        // command của Catalog, và công bố chúng là công bố cả một API chưa ai
        // đo — đúng thứ danh sách tường minh tồn tại để chặn.
        'App\Services\Product\Contracts\RecipeDirectory',
        'App\Services\Product\Contracts\RecipeSnapshot',
        'App\Services\Product\Contracts\SkuDirectory',
        'App\Services\Product\Contracts\SkuSnapshot',
        'App\Services\Product\Contracts\BranchSkuPricing',
        // #1622 — "floating section nào đang phát sóng". Catalog công bố vị ngữ
        // cửa sổ hiệu lực để Pricing và CustomerEngagement thôi mỗi bên giữ một
        // bản sao của cùng một luật.
        'App\Services\Product\Contracts\FloatingSectionAvailability',
        'App\Services\Product\Contracts\FloatingSectionSkuPrice',
        'App\Services\Product\Contracts\SellableSkuPrice',
        'App\Services\Iam\Contracts\RoleAssignmentDirectory',
        // #1666 — cổng DANH BẠ THIẾT BỊ. PlatformIntegration công bố đúng hai thứ
        // Payments đo được là có đọc: tên hiển thị của thiết bị, và vị ngữ "còn
        // hoạt động trong org/branch này". Vòng đời thiết bị (pair / cấp token /
        // thu hồi) KHÔNG nằm ở đây — `Device` cố ý không phải hạ tầng dùng chung,
        // xem ghi chú ở `shared_classes` bên trên.
        'App\Services\Device\Contracts\DeviceDirectory',
        'App\Services\Order\Contracts\OpenOrderSkuUsage',
        'App\Services\Menu\Contracts\ShopMenuSections',
        'App\Services\Payment\Contracts\BranchPaymentTotals',
        'App\Services\Order\Contracts\OrderRowLock',
        'App\Services\Order\Contracts\CustomerOrderPresence',
        'App\Services\Promotion\Contracts\MenuPromotionResolver',
        'App\Services\Promotion\Contracts\ActiveMenuPromotion',
        'App\Services\Promotion\Contracts\FloatingSectionPricing',
        'App\Services\Topping\Contracts\ToppingLinePricing',
        'App\Services\Inventory\Contracts\OrderLineStockDeduction',
        // #962 — bốn cổng Inventory KHAI nhưng module khác HIỆN THỰC. Cùng
        // chiều khai báo đảo với `OpenTillSessionLookup` (#1662): người TIÊU THỤ
        // khai hợp đồng, người SỞ HỮU BẢNG hiện thực nó trong `Internal/`.
        //
        // Cái đầu do Ordering hiện thực (`void_reasons`), cái cuối do
        // CustomerEngagement (`customers`). Không cái nào import `App\Models` —
        // `VoidReasonStockEffect` là value object thuần, còn
        // `CustomerNotifiableDirectory` cố ý trả `Illuminate\Support\Collection`
        // các đối tượng MỜ vì `NotificationDispatcher` vốn đã nhận notifiable ẩn danh.
        'App\Services\Inventory\Contracts\VoidReasonStockEffect',
        'App\Services\Inventory\Contracts\VoidReasonStockEffects',
        'App\Services\Inventory\Contracts\CustomerNotifiableDirectory',
        'App\Services\Shop\Contracts\TableOccupancy',
        'App\Services\Shop\Contracts\TableOccupancySnapshot',
        // #962 — cổng TRA LOẠI THUẾ mà Pricing công bố cho Catalog. Xem
        // `App\Services\Tax\Contracts\TaxTypeDirectory` để biết vì sao
        // `TaxTypeIdentity` cố ý KHÔNG mang `rate`.
        'App\Services\Tax\Contracts\TaxTypeDirectory',
        'App\Services\Tax\Contracts\TaxTypeIdentity',
        // #962 — chiều NGƯỢC lại của hai cái trên: Pricing KHAI, Catalog HIỆN THỰC.
        // `TaxResolver` cần tầng 2 (pivot `menu_menu_sections`) và tầng 3 (`menus`)
        // của chuỗi #1218 mà không cầm model Catalog. Không import gì (kiểm:
        // `grep '^use ' → rỗng`), chỉ nhận/trả `string` — nên nó thoả luật
        // "PublishedContracts không phụ thuộc module nào".
        'App\Services\Tax\Contracts\MenuTaxTypeAnchors',
        // #1596 — cổng TỈ LỆ THUẾ HIỂN THỊ mà Pricing công bố cho Catalog, khi
        // `CustomerMenuService` về đúng module của nó và thôi `new TaxResolver`.
        //
        // Cổng RIÊNG chứ không dùng lại `App\Services\Order\Contracts\OrderLineTaxPricing`:
        // cổng kia cố ý phát cảnh báo "không tầng nào giải ra tỉ lệ" — nghĩa là
        // "sắp thu thiếu thuế" — còn endpoint thực đơn chạy ở MỌI lượt xem trang
        // của khách, nên dùng chung sẽ chôn những lần xảy ra thật dưới lưu lượng
        // hiển thị. Thứ tự tầng thì KHÔNG nhân đôi: cả hai chuyển tiếp về đúng
        // `TaxResolver::walk()`.
        //
        // Chữ ký toàn `?string`/`?float` nên thoả luật "hợp đồng công bố không
        // phụ thuộc module nào" — deptrac cưỡng chế ngay tại rào.
        'App\Services\Tax\Contracts\MenuDisplayTaxRates',
        'App\Services\Tax\Contracts\MenuDisplayTaxRateBatch',
        // #962 — cổng DANH MỤC NGUYÊN VẬT LIỆU mà Inventory công bố cho Catalog.
        'App\Services\Inventory\Contracts\MaterialDirectory',
        'App\Services\Inventory\Contracts\MaterialSnapshot',
        // #962 — cổng ĐỒ THỊ CÔNG THỨC mà Catalog công bố cho Inventory, để
        // `MaterialService` về được với aggregate `Material` mà nó quản.
        'App\Services\Product\Contracts\RecipeGraph',
        'App\Services\Product\Contracts\MaterialAllergenPropagation',

        // #962 — ảnh chụp bàn cho luồng QR của khách. Tách khỏi
        // `TableOccupancySnapshot` có chủ ý: cái kia cố tình chỉ mang
        // `current_order_id` + `status`, còn luồng QR còn cần org/branch để mở
        // `TableSession` và `qr_token`/tên bàn để dựng payload trả về. Nhét
        // thêm cột vào snapshot cũ là phá đúng cái ranh giới nó vẽ ra.
        'App\Services\Shop\Contracts\TableQrSnapshot',
        // #962 — HAI class DỊCH VỤ được công bố NGUYÊN VẸN, không bọc
        // interface. Cả hai chỉ phụ thuộc `Branch`/`Brand`/`Organization`, tức
        // TOÀN BỘ phụ thuộc của chúng đã là TenancyKernel, nên chúng thoả sẵn
        // luật "hợp đồng công bố chỉ được phụ thuộc hai kernel" — deptrac cưỡng
        // chế điều đó ngay tại đây, không cần ai hứa.
        //
        // Dựng thêm một interface cho chúng sẽ là tầng gián tiếp thuần nghi
        // thức: một hiện thực, một method, chữ ký đã toàn primitive/kernel.
        // Cái làm cạnh biến mất là CÔNG BỐ, không phải cái `interface`.
        //
        //  · `PublicBranchScopeResolver` — slug công khai → (branch, brand, org).
        //    Chính docblock của nó nói nó tồn tại để module khác thôi tự đi ba
        //    model của Organization; công bố là bước còn thiếu của lời khai đó.
        //    Gỡ: CustomerEngagement → Organization (CustomerAuthService).
        //  · `SellerRegistrationResolver` — 登録番号 T+13 hiệu lực của chi nhánh.
        //    Gỡ: PlatformIntegration → Payments (Print\TemplateValidator).
        'App\Services\Shop\PublicBranchScopeResolver',
        'App\Services\Shop\SellerRegistrationResolver',
        // #1696 — CÙNG KHUÔN với hai cái trên, và cùng chủ đề với
        // `SellerRegistrationResolver` ngay trên nó: hồ sơ tuân thủ theo quốc
        // gia (#1153) quyết định ĐỊNH DẠNG số đăng ký thuế — JP インボイス T+13
        // vs VN mã số thuế. Công bố nguyên vẹn, không bọc interface, vì toàn bộ
        // phụ thuộc của cụm này đã thoả luật sẵn:
        //   · `ComplianceProfile` + hai hiện thực JP/VN — KHÔNG import gì
        //     (`grep '^use ' → rỗng`), chỉ nhận/trả string;
        //   · `ComplianceProfileResolver` — import DUY NHẤT `App\Models\
        //     Organization`, vốn là TenancyKernel.
        // Chúng được công bố THÀNH CỤM vì resolver tự dựng hai profile con;
        // công bố mỗi resolver sẽ để lại đúng cạnh mình vừa đi gỡ.
        //
        // Gỡ: Organization → Compliance. Cạnh đó xuất hiện khi #1696 đưa khối
        // validate `invoice_registration_number` từ `ShopBranchSettingsController`
        // (tầng Composition, được phép với tới mọi module) xuống
        // `ShopBranchSettingsService` (Organization). Không công bố thì SCC lớn
        // nhất phình 9 → 10 node và cạnh vi phạm 12 → 16 — `LayerCyclesTest` đỏ.
        'App\Services\Compliance\ComplianceProfileResolver',
        'App\Services\Compliance\ComplianceProfile',
        'App\Services\Compliance\JpComplianceProfile',
        'App\Services\Compliance\VnComplianceProfile',
        // #962 — cổng HẸP do module CUNG CẤP công bố.
        'App\Services\Device\Contracts\NotifiableDeviceDirectory',
        'App\Services\Inventory\Contracts\ActiveMaterialDirectory',
        'App\Services\Inventory\Contracts\WarehouseMemberDirectory',
        // #1550 — cổng CHUYỂN CHỦ coupon khi gộp khách. Cùng khuôn
        // `CustomerOrderReassignment` của Ordering: CustomerEngagement ra lệnh
        // qua một cổng hẹp, Pricing tự ghi bảng của mình.
        //
        // Thiếu dòng này thì `deptrac` đỏ (`EloquentCustomerPersistence must not
        // depend on Promotion\Contracts\CustomerCouponReassignment`) — và #1550
        // đã lọt vì tôi chỉ chạy `architecture:domain-writers` + `RawTableReadsTest`,
        // hai rào KHÁC, rồi coi như đã kiểm hết ranh giới. Deptrac chạy ở job CI
        // riêng, không nằm trong bộ pest.
        // #1772 — Loyalty công bố ảnh nền thẻ theo hạng cho Shop.
        'App\Services\Loyalty\Contracts\MembershipTierBackgrounds',
        'App\Services\Promotion\Contracts\CustomerCouponReassignment',
        'App\Services\Promotion\Contracts\PersonalCouponMinting',
        'App\Services\Promotion\Contracts\PersonalCouponSpec',
        'App\Services\Promotion\Contracts\MintedCoupon',
        'App\Services\Till\Contracts\OrgTenderVocabulary',

        /*
         * #2878 (T1 của #2876) — hai cổng cho sổ lượt thu tiền 釣銭機.
         *
         * `CashDeviceTransactionIntake` (Payments) phải kiểm phạm vi hai thứ do
         * THIẾT BỊ gửi lên: một `customer_order_id` (Ordering sở hữu) và một
         * `peripheral_device_id` (PlatformIntegration sở hữu). Trước bản vá nó
         * đọc thẳng hai model đó và deptrac bắt đúng.
         *
         * Cả hai thoả điều kiện của tầng này: chữ ký chỉ mang string/bool, không
         * cái nào rò model của chủ sở hữu ra ngoài.
         */
        'App\Services\Order\Contracts\BranchOrderMembership',
        'App\Services\PeripheralDevice\Contracts\BranchCashDevices',
        // #2879 — ngưỡng lệch tiền mặt theo brand. Cổng trả CÂU TRẢ LỜI CUỐI
        // CÙNG (một int), không trả model, nên nó thoả điều kiện của tầng này.
        'App\Services\Shop\Contracts\BranchCashVarianceTolerance',

        /*
         * #962 (cụm CustomerEngagement) — sáu hợp đồng cho ba đường mà module
         * này đang tự đọc bảng của module khác. Chúng vào đây được vì đều thoả
         * luật "chỉ phụ thuộc hai kernel": không class nào import `App\Models`,
         * và thứ nặng nhất chúng mang là `App\Omnify\Enums\*` (đã `shared`).
         *
         * `App\Services\Order\Contracts\ReviewableOrderLine(s)` KHÔNG có ở đây —
         * `App\Services\Order\Contracts` vốn đã là namespace công bố (#1583).
         */
        'App\Services\Promotion\Contracts\MenuDisplayPromotions',
        'App\Services\Promotion\Contracts\MenuDisplayPromotion',
        'App\Services\Promotion\Contracts\CustomerOwnedCoupons',
        'App\Services\Product\Contracts\ReviewedSkuDirectory',
        'App\Services\Product\Contracts\ReviewedSku',
        'App\Services\Product\Contracts\ReviewedProduct',
        'App\Services\Product\Contracts\ProductReviewAggregates',
        'App\Services\Topping\Contracts\ToppingGroupItemIntegrity',
    ],

    /*
     * #1583 — API LỆNH của Ordering, công bố theo namespace.
     *
     * #1583 hỏi: "dựng `MutationContext` có tính là đi qua API công bố không,
     * khi `OrderMutationContextFactory` nằm trong `Internal`?" — và đề ra hai
     * hướng, cái rẻ đổi tên chỗ đau, cái đắt sửa 20+ command.
     *
     * Đo lại thì CÂU HỎI ĐÃ HẾT HIỆU LỰC, vì hai sự thật:
     *
     *  1. `MutationContext` nằm ở `App\Services\DomainMutation`, tức đã là
     *     `shared` từ trước. Tiền đề "một khái niệm nội bộ của Ordering" sai.
     *  2. Không còn cạnh nào trỏ vào `OrderMutationContextFactory`. Năm cạnh
     *     `CouponService → factory` tan khi #1581 dời nửa đơn hàng sang
     *     `OrderCouponService` (Ordering). Mọi caller còn lại là controller,
     *     tức Composition — được phép phụ thuộc mọi module.
     *
     * Cái CÒN LẠI là 9 cạnh Payments → API lệnh, và chúng làm ĐÚNG: đi qua
     * `OrderMutationFacade` với command. Chúng bị tính là nợ chỉ vì API lệnh
     * chưa được khai là công bố.
     *
     * Đã kiểm toàn bộ 77 file trong năm namespace dưới: KHÔNG file nào import
     * `App\Models\`. Phụ thuộc ngoài của chúng chỉ là `App\Omnify\Enums` và
     * `App\Services\DomainMutation` — cả hai đều `shared`. API lệnh vốn đã
     * sạch model; nó chỉ chưa được khai.
     *
     * Vì sao khai theo namespace mà không phá luật "tường minh": liệt kê 77
     * FQCN thì danh sách thành thứ không ai đọc, và một command mới sẽ lặng lẽ
     * nằm ngoài. Cái chặn lạm dụng không phải độ dài danh sách mà là luật
     * `PublishedContracts` chỉ được phụ thuộc hai kernel — thả một class chạm
     * model vào đây là deptrac ĐỎ ngay, không cần ai nhớ.
     */
    'published_contract_namespaces' => [
        // #1661 — cổng đánh dấu bản catalog của Catalog. Ordering (tầng 5) và
        // Pricing (tầng 6 + thuế suất) phải báo được cho Catalog khi cấu hình
        // thuế của CHÍNH chúng đổi, nếu không bản catalog — vốn là số phiên bản
        // của feed menu — không tiến và workstation nhận 304 rồi in theo thuế
        // suất cũ. Chiều ngược lại (Catalog tự đọc hai bảng đó) là cạnh deptrac
        // chặn, đúng.
        //
        // Namespace này chứa ĐÚNG interface đó và **không import gì cả**, nên nó
        // thoả luật `PublishedContracts` chỉ được phụ thuộc hai kernel — thả một
        // class chạm model vào đây là deptrac đỏ ngay.
        'App\Services\Catalog\Contracts',
        'App\Services\Order\Contracts',
        'App\Services\Order\Commands',
        'App\Services\Order\Results',
        'App\Services\Order\ValueObjects',
        'App\Services\Order\Enums',
    ],

    'modules' => [

        'Organization' => [
            'namespaces' => [
                'App\Services\Shop',
            ],
            'models' => [
                'Organization', 'Brand', 'BrandOrderPolicy', 'Branch', 'BranchTranslation',
                'Zone', 'ZoneTemplate', 'Table', 'TableTemplate',
            ],
        ],

        'Catalog' => [
            'classes' => [
                // #1574 — trait duyệt sản phẩm/công thức. `App\Concerns` bị khai là
                // `shared`, nên nó phát 2 cạnh SharedKernel → TenancyKernel (`User`).
                // Chỉ `Product` và `Recipe` dùng nó, cả hai đều Catalog; Catalog được
                // phép phụ thuộc TenancyKernel nên khai lại là cạnh biến mất hẳn.
                'App\Concerns\HasApprovalWorkflow',
                // #962 — ĐẶT NHẦM MODULE, không phải nợ ranh giới.
                // #1561 dời 13 wrapper `App\Services\Omnify\*` từ SharedKernel
                // xuống module phục vụ chúng, và cái này rơi vào `Ordering` —
                // trong khi `Allergen` là model của Catalog và class chỉ chạm
                // đúng `App\Models\Allergen` + `AllergenServiceBase` cùng tên.
                // Hai cạnh "Ordering → Catalog" trong baseline vì thế đo một
                // ranh giới không tồn tại. Consumer duy nhất là
                // `HQ\AllergenController` (Composition) nên đổi khai báo không
                // sinh cạnh mới nào — kiểm bằng grep, không đoán.
                'App\Services\Omnify\AllergenService',
                /*
                 * #1596 — HAI class DỰNG THỰC ĐƠN, đặt nhầm module.
                 *
                 * Chúng nằm trong `App\Services\Customer` (⇒ CustomerEngagement)
                 * nhưng từ vựng của chúng là Catalog từ đầu tới cuối:
                 * `Menu → MenuMenuSection → MenuProduct → MenuProductSku →
                 * Product → ProductOption → ProductSku → ToppingGroup →
                 * ToppingGroupItem → FloatingSection`. Không một dòng nào chạm
                 * `Customer`, điểm tích luỹ, ví coupon hay đánh giá —
                 * `App\Services\Customer` ở đây là tên BỀ MẶT API (customer-web),
                 * không phải tên module, đúng như bốn class đã khai sang Pricing
                 * và bảy class đã khai sang Ordering bên dưới.
                 *
                 * Bằng chứng mạnh hơn việc đọc từ vựng: `App\Services\Product\
                 * MenuService` (Catalog) mang năm comment tự khai *"Mirrors
                 * CustomerMenuService … keep in sync"*, và
                 * `EloquentToppingGroupItemIntegrity` (Catalog) khai truy vấn của
                 * nó là *"chép NGUYÊN từ MenuLocalizationIntegrityReporter"*. Hai
                 * bản sao của một luật nằm ở hai module là dấu hiệu ranh giới vẽ
                 * sai chỗ, không phải nợ ranh giới.
                 *
                 * ĐÃ ĐO trước khi khai (bằng deptrac, không bằng grep): 16 vi
                 * phạm cấp class trên 5 cặp baseline; khai lại thì 14/16 và 4/5
                 * biến mất. Hai cạnh còn lại KHÔNG được để lăn vào baseline dưới
                 * tên mới — cả hai đã TRẢ trong cùng PR, vì để lại thì
                 * `LayerCyclesTest` C2b nhảy 12 → 16 (đo thật):
                 *
                 *   · Catalog → Pricing — `new TaxResolver` ⇒ cổng công bố
                 *     `App\Services\Tax\Contracts\MenuDisplayTaxRates`.
                 *   · Catalog → CustomerEngagement — `ProductReviewService::
                 *     recommendPercent()` ⇒ về `Product::recommendPercent()`,
                 *     nơi hai cột `review_*` vốn đã nằm.
                 *
                 * Consumer đều là Composition/surface (`Customer\*Controller`,
                 * `Workstation\MenuController`), nên khai lại không sinh cạnh VÀO
                 * nào.
                 */
                'App\Services\Customer\CustomerMenuService',
                'App\Services\Customer\MenuLocalizationIntegrityReporter',
            ],
            'namespaces' => [
                'App\Services\Product',
                'App\Services\Menu',
                'App\Services\Topping',
                'App\Services\Import',
                'App\Services\Export',
            ],
            'models' => [
                'Allergen', 'AllergenMaterial', 'AllergenTranslation',
                'Category', 'CategoryProduct', 'CategoryTranslation',
                'FloatingSection', 'FloatingSectionProduct', 'FloatingSectionProductSku',
                'FloatingSectionProductToppingItemOverride', 'FloatingSectionSchedule',
                'FloatingSectionTranslation', 'BranchFloatingSectionOverride',
                'Menu', 'MenuItem', 'BranchScheduleOverride', 'MenuMenuSection', 'MenuProduct', 'MenuProductSku',
                'MenuProductToppingItemOverride', 'MenuSchedule', 'MenuSection',
                'MenuSectionTranslation', 'MenuTranslation',
                'Product', 'ProductOption', 'ProductOptionTranslation', 'ProductOptionValue',
                'ProductOptionValueTranslation', 'ProductSku', 'ProductSkuTranslation',
                'ProductToppingGroup', 'ProductToppingGroupItemOverride', 'ProductTranslation',
                'ProductType', 'ProductTypeTranslation',
                'Recipe', 'RecipeTranslation',
                'ToppingGroup', 'ToppingGroupItem', 'ToppingGroupItemSku', 'ToppingGroupTranslation',
                'VariantUnit', 'CatalogRevision',
            ],
        ],

        'Pricing' => [
            'classes' => [
                // #1564 — ĐỘNG CƠ THUẾ, không phải việc của CustomerEngagement.
                // Chúng nằm trong `App\Services\Customer` nên bị khai theo namespace đó,
                // trong khi CLAUDE.md nói thẳng: Pricing sở hữu Money/tax/discount/
                // coupon/promotion calculation. Hệ quả đo được: TaxResolver đọc TaxType
                // 14 lần — một cạnh CustomerEngagement → Pricing thuần do khai nhầm.
                'App\Services\Customer\TaxResolver',
                'App\Services\Customer\TaxGroup',
                'App\Services\Customer\PricingResult',
                'App\Services\Customer\TaxResolution',
            ],
            'namespaces' => [
                'App\Services\Promotion',
                // #962 — cổng TRA LOẠI THUẾ. Khai HAI namespace con chứ không
                // khai cả `App\Services\Tax`: `TaxTypeService` (CRUD loại thuế)
                // nằm ở gốc namespace đó và đọc `App\Models\Product` một lần
                // (phân giải org của brand) — khai cả cây là tự tạo một vi phạm
                // Pricing → Catalog mới, tức trả nợ chỗ này bằng nợ chỗ khác.
                // Nó ở lại Unassigned như trước, không xấu đi.
                'App\Services\Tax\Contracts',
                'App\Services\Tax\Internal',
            ],
            'models' => [
                'TaxType', 'TaxTypeTranslation', 'TaxTypeRate',
                'Coupon', 'CouponBranch', 'CouponRedemption', 'CouponTranslation',
                'MenuPromotion', 'MenuPromotionCategory', 'MenuPromotionProduct',
                'MenuPromotionTranslation',
            ],
        ],

        'Ordering' => [
            // #1541 — bảy class dưới đây nằm trong `App\Services\Customer` nhưng
            // làm việc của ĐƠN HÀNG: tạo/đóng/void đơn, khoá bàn qua QR, tính giá
            // đơn. Phân loại bằng method thật sự có trong file, không theo tên
            // class hay theo thư mục. Chúng chiếm 107/185 lần "vi phạm"
            // CustomerEngagement → Ordering — tức phần lớn khoản nợ đó KHÔNG phải
            // vượt ranh giới, mà là ranh giới vẽ sai chỗ. Dựng contract cho chúng
            // sẽ thêm một tầng gián tiếp vô nghĩa và khoá cứng cái sai.
            //
            // Namespace giữ nguyên: đổi tên namespace là refactor rủi ro và không
            // cần cho việc này — chủ sở hữu là một sự thật khai báo được.
            'classes' => [
                // #1568 — CHỨNG TỪ ĐƠN HÀNG, không phải thông báo.
                // `App\Mail` bị khai trọn cho Notifications, nên hai mailable này
                // kéo theo 7 cạnh Notifications↔Ordering. Đọc code: chúng chỉ phụ
                // thuộc CustomerOrder / OrderPricingCalculator / OrderQrService /
                // ShopOrderSetting — KHÔNG chạm một class Notification nào, và
                // không class Notification nào dựng chúng. `NotificationMail` và
                // `NotificationDigestMail` ở lại Notifications.
                'App\Mail\OrderPaidInvoiceMail',
                'App\Mail\OrderPlacedMail',
                // #1589 — CÙNG LOẠI với `ShopTillTrackingService` ở #1588: nằm
                // trong `App\Services\Shop` (⇒ Organization) nhưng làm chính sách
                // ĐƠN HÀNG. `EffectiveOrderPolicyService` là plan-035 (prep trước
                // thanh toán, phút chuẩn bị, hợp nhất brand↔branch);
                // `VoidableStatusResolver` là plan-051 — "trạng thái nào được VOID
                // một dòng món". Cả hai đọc `ShopOrderSetting`, và khi model đó về
                // Ordering thì để hai class này ở Organization là tạo ra một
                // 2-chu-trình Organization↔Ordering không có thật.
                'App\Services\Shop\EffectiveOrderPolicyService',
                'App\Services\Shop\VoidableStatusResolver',
                // #1687 — same shape again, one issue later: the guard+write
                // transaction of `PATCH /shops/{slug}/settings/order`, lifted
                // out of the controller. It reads, locks and WRITES the
                // `shop_order_settings` row, so Ordering owns it; leaving it at
                // the `App\Services\Shop` default (Organization) would invent an
                // Organization↔Ordering 2-cycle that does not exist, exactly as
                // #1589 argued for the two classes above.
                'App\Services\Shop\ShopOrderSettingsService',
                'App\Services\Shop\ShopOrderSettingsUpdateResult',
                // #1666 — lần thứ tư cùng một hình dạng: `VoidReasonService`
                // (tách khỏi `HQ\VoidReasonController::store`) tạo `VoidReason`,
                // một aggregate của Ordering, nhưng nằm ở `App\Services\Shop`
                // nên mặc định rơi vào Organization ⇒ deptrac báo
                // "Organization on Ordering" và CHẶN mọi PR nhắm vào dev. Khai
                // đúng chủ sở hữu, y như ba mục ngay trên.
                'App\Services\Shop\VoidReasonService',
                // #1561 — CRUD wrapper mỏng quanh `*ServiceBase` sinh tự động.
                // `App\Services\Omnify` bị khai là `shared`, nên 13 file này phát
                // 47/73 cạnh NGƯỢC từ SharedKernel xuống module, trong khi ruleset
                // khai `SharedKernel: ~` (hạ tầng dùng chung không phụ thuộc vào gì).
                // Chúng không hề dùng chung: mỗi cái phục vụ đúng một module.
                // Đổi khai báo là đủ — mọi consumer đều là controller/provider
                // (layer Composition) hoặc seeder (ngoài `paths: ./app`), nên
                // KHÔNG sinh cạnh mới nào. Kiểm bằng grep trước khi sửa, không đoán.
                'App\Services\Customer\CustomerOrderService',
                'App\Services\Customer\OrderClosingService',
                'App\Services\Customer\CustomerQrOrderService',
                'App\Services\Customer\CustomerTakeawayOrderService',
                'App\Services\Customer\CustomerTableSessionService',
                'App\Services\Customer\SplitByItemsCalculator',
                'App\Services\Customer\OrderPricingCalculator',
                'App\Services\Customer\OrderTaxBreakdownAggregator',
                // #2677 — phân bổ thuế của ĐƠN cho tập phiếu chia. Nó đọc ảnh chụp
                // per-rate của đơn và gọi bộ làm tròn nhóm-rate; cùng chủ sở hữu với
                // hai lớp ngay trên, chỉ tình cờ chung namespace `Customer`.
                'App\Services\Customer\SplitBillTaxAllocator',
            ],
            'namespaces' => [
                'App\Services\Order',
                'App\Services\Kds',
                'App\Services\Pos',
            ],
            'models' => [
                'CustomerOrder', 'CustomerOrderItem', 'OrderItemTopping', 'OrderCondition', 'ShopOrderSetting',
                'OrderCodeCounter', 'TableSession', 'TableStatusChange',
                'VoidReason', 'VoidReasonTranslation',
                // #2885 — bằng chứng lệch tiền giữa máy trạm và Cloud. Model
                // phải có chủ module: `OrderMoneyEvidenceRecorder` (người ghi
                // duy nhất) sống ở `App\Services\Order\Internal`, và một model
                // không thuộc layer nào sẽ khiến phụ thuộc của nó bị Deptrac
                // đếm là "uncovered" thay vì đếm là nợ.
                'OrderMoneyOverwrite',
            ],
        ],

        'Payments' => [
            // #1541 — ba cổng thanh toán đặt nhầm trong `App\Services\Customer`.
            // `OrderPaymentService` là vòng đời tiền của một OrderPayment
            // (create/confirm/refund/webhook); Stripe và PayPay là adapter cổng.
            // 64/185 lần "vi phạm" đến từ đây.
            'classes' => [
                // #1561 — CRUD wrapper mỏng quanh `*ServiceBase` sinh tự động.
                // `App\Services\Omnify` bị khai là `shared`, nên 13 file này phát
                // 47/73 cạnh NGƯỢC từ SharedKernel xuống module, trong khi ruleset
                // khai `SharedKernel: ~` (hạ tầng dùng chung không phụ thuộc vào gì).
                // Chúng không hề dùng chung: mỗi cái phục vụ đúng một module.
                // Đổi khai báo là đủ — mọi consumer đều là controller/provider
                // (layer Composition) hoặc seeder (ngoài `paths: ./app`), nên
                // KHÔNG sinh cạnh mới nào. Kiểm bằng grep trước khi sửa, không đoán.
                'App\Services\Omnify\PaymentMethodService',
                'App\Services\Omnify\DenominationService',
                'App\Services\Omnify\TillTenderTypeService',
                // #1543 — cụm KÉT TIỀN nằm trong `App\Services\Pos` (gán cho
                // Ordering vì POS là màn bán hàng) nhưng làm việc của TIỀN:
                // mở/đóng/bàn giao ca thu ngân, ghi biến động két, đối soát 過不足
                // theo `OrderPayment`, phát hành hoá đơn gắn với khoản đã thu.
                // Không method nào chạm vòng đời đơn.
                //
                // 137/141 lần "vi phạm" Ordering → Payments đến từ ba class này.
                // `PosRevenueService` KHÔNG chuyển: 3/4 method của nó truy vấn
                // customer_orders/items để phân tích huỷ đơn và cơ cấu món —
                // đúng miền Ordering.
                'App\Services\Shop\SellerRegistrationResolver',
                'App\Services\Pos\TillSessionService',
                'App\Services\Pos\PosInvoiceService',
                'App\Services\Pos\PosReturnInvoiceService',
                // #1562 — cùng kiểu đặt nhầm như ba class ngay trên, một namespace
                // khác: `App\Services\Shop` bị khai cho Organization, nên plan-036
                // "Manager Till Tracking read service" bị tính là Organization dù
                // toàn bộ việc của nó là KÉT TIỀN — dashboard KPI két, lịch sử ca,
                // đối soát 過不足, và render Z-report.
                //
                // 40/59 cạnh RA của Organization đến từ đúng một class này, và 35
                // trong số đó trỏ sang Payments (`TillSession` 25 · `OrderPayment` 5
                // · `Till` 2 · `TillCashEvent` 2 · `TillSessionService` 1).
                //
                // Không sinh cạnh mới: consumer DUY NHẤT ngoài chính nó là
                // `Api\V1\Shop\ShopTillTrackingController` (Composition, được phép
                // phụ thuộc mọi module). Kiểm bằng grep trước khi sửa, không đoán.
                'App\Services\Shop\ShopTillTrackingService',
                'App\Services\Customer\OrderPaymentService',
                'App\Services\Customer\StripePaymentService',
                'App\Services\Customer\PayPayPaymentService',
                'App\Services\Customer\PayPayAvailabilityService',
                'App\Services\Customer\PayPayUnavailable',
            ],
            'namespaces' => [
                'App\Services\Payment',
                'App\Services\Till',
                'App\Services\Compliance',
            ],
            'models' => [
                // OrderPaymentIntent (#1637) — the current gateway-intent pointer
                // for an order, moved off `customer_orders` so Payments owns it.
                'OrderPayment', 'OrderPaymentIntent', 'PaymentAttempt', 'PaymentGatewayConnection',
                'PaymentGatewayConnectionOption', 'PaymentGatewayOption',
                'PaymentGatewayOptionTranslation', 'PaymentGatewayProvider',
                'PaymentGatewayProviderTranslation', 'PaymentGatewaySecretAudit',
                'PaymentGatewaySecretVersion', 'PaymentMethod', 'PaymentMethodTranslation',
                'PaymentPolicyRevision', 'PaymentProviderEvent', 'PaymentRefund',
                'PaymentSettlement', 'GatewayPayout', 'SettlementReportBatch',
                'MoneyReconciliationTask',
                'Denomination', 'Till', 'TillCashDenominationCount', 'TillCashEvent',
                'TillSession', 'TillSettlementTenderDetail', 'TillTenderCategory',
                'TillTenderType', 'TillTenderTypeTranslation',
                'InvoiceCounter',
                'ShopPaymentOption', 'DevicePaymentOption',
            ],
        ],

        'Inventory' => [
            'classes' => [
                // #1561 — CRUD wrapper mỏng quanh `*ServiceBase` sinh tự động.
                // `App\Services\Omnify` bị khai là `shared`, nên 13 file này phát
                // 47/73 cạnh NGƯỢC từ SharedKernel xuống module, trong khi ruleset
                // khai `SharedKernel: ~` (hạ tầng dùng chung không phụ thuộc vào gì).
                // Chúng không hề dùng chung: mỗi cái phục vụ đúng một module.
                // Đổi khai báo là đủ — mọi consumer đều là controller/provider
                // (layer Composition) hoặc seeder (ngoài `paths: ./app`), nên
                // KHÔNG sinh cạnh mới nào. Kiểm bằng grep trước khi sửa, không đoán.
                'App\Services\Omnify\MaterialUnitService',
                'App\Services\Omnify\MaterialSubstitutionRuleService',
                'App\Services\Omnify\MaterialBatchService',
                'App\Services\Omnify\ProductionOrderService',
                'App\Services\Omnify\StockAlertService',
                'App\Services\Omnify\StockCountService',
                'App\Services\Omnify\StockTransactionService',
                'App\Services\Omnify\StockTransferService',
                'App\Services\Omnify\WarehouseService',
                /*
                 * #962 — cụm NGUYÊN VẬT LIỆU nằm trong `App\Services\Product`
                 * (⇒ Catalog) và `App\Services\{Import,Export}` (⇒ Catalog)
                 * nhưng quản đúng aggregate `Material`/`MaterialUnit` của
                 * Inventory. Cùng chẩn đoán như #1541 / #1543 / #1562: ranh
                 * giới vẽ sai chỗ, không phải nợ vượt biên.
                 *
                 * Đo được: `MaterialService` một mình phát 23/36 cạnh
                 * Catalog → Inventory, và KHÔNG cạnh nào là "nhận model từ
                 * người gọi" — tất cả là nó tự truy vấn chính aggregate nó quản.
                 * Bọc `Material` sau một interface để hợp thức hoá sẽ khoá cứng
                 * cái sai; khai lại quyền sở hữu thì không.
                 *
                 * `MaterialExporter` / `MaterialImporter` đi cùng: chúng là CSV
                 * của nguyên vật liệu, và `MaterialImporter` ghi qua
                 * `MaterialService` — để chúng ở Catalog là tạo ra một
                 * 2-chu-trình Catalog↔Inventory không có thật.
                 *
                 * Giá phải trả, nói thẳng: ba class này vẫn cần Catalog cho ĐỒ
                 * THỊ CÔNG THỨC (nguyên liệu nào ăn nguyên liệu nào) và cho hệ
                 * quả duyệt lại khi dị nguyên đổi. Hai thứ đó giờ đi qua
                 * `RecipeGraph` + `MaterialAllergenPropagation` — hai hợp đồng
                 * Catalog công bố, chữ ký toàn primitive.
                 *
                 * `AllergenRollupService` KHÔNG chuyển: nó tính
                 * `Recipe.allergen_rollup`, tức ghi vào aggregate của Catalog.
                 * Nó chỉ được thu hẹp chữ ký (nhận id thay vì `Material`).
                 */
                'App\Services\Product\MaterialService',
                'App\Services\Import\MaterialImporter',
                'App\Services\Export\MaterialExporter',
            ],
            'namespaces' => [
                'App\Services\Inventory',
            ],
            'models' => [
                'Material', 'MaterialBatch', 'MaterialBatchItem', 'MaterialLot',
                'MaterialLotReservation', 'MaterialSubstitutionRule', 'MaterialTranslation',
                'MaterialUnit',
                'StockAlert', 'StockCount', 'StockCountItem', 'StockLevel', 'StockMovement',
                'StockTransaction', 'StockTransactionItem', 'StockTransfer', 'StockTransferItem',
                'Warehouse', 'WarehouseMember',
                'DisposalRecord', 'ExpiryAlert',
                'ProductionOrder', 'ProductionOrderItem',
                'Recall', 'RecallAffectedOrder', 'RecallDrill', 'GenealogyLink',
            ],
        ],

        'CustomerEngagement' => [
            'namespaces' => [
                'App\Services\Customer',
                // #1441 — điểm tích luỹ + hạng thành viên. Ở đây chứ không
                // phải Pricing: điểm là quan hệ với KHÁCH (tích khi họ mua,
                // xét hạng theo mức gắn bó). Nó có với sang Pricing để mint
                // coupon lúc đổi điểm, và cạnh đó được khai trong baseline.
                'App\Services\Loyalty',
                'App\Listeners\Loyalty',
            ],
            'models' => [
                'Customer', 'BranchReview', 'ProductReview',
                'Post', 'PostCategory', 'PostCategoryTranslation', 'PostPostTag', 'PostTag',
                'PostTagTranslation', 'PostTranslation',
                'CustomerPointEntry', 'PointReward', 'PointRewardTranslation',
                // #1514 — pivot bật/tắt phần thưởng theo chi nhánh. Thuộc
                // module này chứ không phải Organization (nơi sở hữu Branch):
                // nó mô tả một quyết định về CATALOG ĐỔI ĐIỂM, chi nhánh chỉ
                // là chiều lọc.
                'PointRewardBranch',
            ],
        ],

        'Notifications' => [
            'namespaces' => [
                'App\Services\Notification',
                'App\Mail',
            ],
            'models' => [
                'Notification', 'NotificationAudience', 'NotificationChannelRoute',
                'NotificationDelivery', 'NotificationDigestPreference',
                'NotificationEmailSuppression', 'NotificationPreference',
                'NotificationRecipient', 'NotificationRule', 'NotificationRuleFiring',
                'NotificationSchedule', 'NotificationTemplate',
            ],
        ],

        'PlatformIntegration' => [
            'namespaces' => [
                'App\Sso',
                'App\Services\Iam',
                'App\Services\Device',
                'App\Services\PeripheralDevice',
                'App\Services\Print',
                'App\Services\Printing',
            ],
            'models' => [
                'User', 'Role', 'RolePermission', 'RoleUserPivot', 'Permission',
                'PersonalAccessToken',
                'Device', 'DeviceSigningKey', 'PeripheralDevice',
                'Printer', 'PrintJob', 'PrintJobResolution', 'PrintTemplate',
                'File', 'AuditLog',
            ],
        ],
    ],
];
