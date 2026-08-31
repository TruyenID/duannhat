<?php

namespace App\Providers;

use App\Models\BrandOrderPolicy;
use App\Modules\Pricing\Infrastructure\TaxResolverLineTaxPricing;
use App\Modules\Pricing\Infrastructure\TaxResolverMenuDisplayRates;
use App\Services\Device\EloquentOfflineSigningIdentity;
use App\Services\Inventory\Contracts\OrderLineStockDeduction;
use App\Services\Inventory\EloquentOrderLineStockDeduction;
use App\Services\Menu\Internal\EloquentMenuTaxTypeAnchors;
use App\Services\Menu\Internal\EloquentOrderLineCatalogAnchors;
use App\Services\Menu\Internal\EloquentOrderMenuLineDirectory;
use App\Services\Order\Contracts\BranchCurrency;
use App\Services\Order\Contracts\BranchDebtOrderAnchors;
use App\Services\Order\Contracts\BranchDefaultTaxType;
use App\Services\Order\Contracts\BranchOpeningWindow;
use App\Services\Order\Contracts\BranchOpenShiftStatus;
use App\Services\Order\Contracts\BranchOrderSettingsLock;
use App\Services\Order\Contracts\BranchSplitBillPolicy;
use App\Services\Order\Contracts\BranchStockDeductionTiming;
use App\Services\Order\Contracts\BrandOrderPolicyDefaults;
use App\Services\Order\Contracts\OfflineSigningIdentity;
use App\Services\Order\Contracts\OpenOrderTableUsage;
use App\Services\Order\Contracts\OpenTillSessionLookup;
use App\Services\Order\Contracts\OrderCouponLedger;
use App\Services\Order\Contracts\OrderCustomerContacts;
use App\Services\Order\Contracts\OrderEvidenceVerificationPort;
use App\Services\Order\Contracts\OrderLineCatalogAnchors;
use App\Services\Order\Contracts\OrderLineTaxPricing;
use App\Services\Order\Contracts\OrderMenuLineDirectory;
use App\Services\Order\Contracts\OrderMutationFacade;
use App\Services\Order\Contracts\OrderPaymentLedgerReads;
use App\Services\Order\Contracts\OrderPricingResolutionPort;
use App\Services\Order\Contracts\OrderQueryPort;
use App\Services\Order\Contracts\OrderSplitBillTotals;
use App\Services\Order\Contracts\OrderStockContextReads;
use App\Services\Order\Contracts\OrderStockLineReads;
use App\Services\Order\Contracts\OrderStockMarker;
use App\Services\Order\Contracts\OrderTaxBreakdownReads;
use App\Services\Order\Contracts\OrderToppingSelectionPricing;
use App\Services\Order\Contracts\PartPaidOrderReads;
use App\Services\Order\Contracts\PromotionRedemptionReads;
use App\Services\Order\Contracts\TableSessionCloser;
use App\Services\Order\Contracts\TableStatusJournal;
use App\Services\Order\Contracts\ToppingSelectionExistence;
use App\Services\Order\Internal\CustomerOrderPricingResolution;
use App\Services\Order\Internal\EloquentBranchCurrency;
use App\Services\Order\Internal\EloquentBranchDebtOrderAnchors;
use App\Services\Order\Internal\EloquentBranchDefaultTaxType;
use App\Services\Order\Internal\EloquentBranchOrderSettingsLock;
use App\Services\Order\Internal\EloquentBranchSplitBillPolicy;
use App\Services\Order\Internal\EloquentBranchStockDeductionTiming;
use App\Services\Order\Internal\EloquentOpenOrderTableUsage;
use App\Services\Order\Internal\EloquentOrderCustomerContacts;
use App\Services\Order\Internal\EloquentOrderPersistence;
use App\Services\Order\Internal\EloquentOrderQuery;
use App\Services\Order\Internal\EloquentOrderStockContextReads;
use App\Services\Order\Internal\EloquentOrderStockLineReads;
use App\Services\Order\Internal\EloquentOrderStockMarker;
use App\Services\Order\Internal\EloquentOrderTaxBreakdownReads;
use App\Services\Order\Internal\EloquentPartPaidOrderReads;
use App\Services\Order\Internal\EloquentPromotionRedemptionReads;
use App\Services\Order\Internal\EloquentSplitBillTotals;
use App\Services\Order\Internal\EloquentTableSessionCloser;
use App\Services\Order\Internal\EloquentTableStatusJournal;
use App\Services\Order\Internal\OfflineOrderEvidenceVerifier;
use App\Services\Order\OrderService;
use App\Services\Payment\Internal\EloquentBranchOpenShiftStatus;
use App\Services\Payment\Internal\EloquentOrderPaymentLedgerReads;
use App\Services\Payment\Internal\TillSessionOpenLookup;
use App\Services\Promotion\Contracts\CouponPricing;
use App\Services\Promotion\CouponService;
use App\Services\Promotion\EloquentOrderCouponLedger;
use App\Services\Shop\Contracts\TableOccupancy;
use App\Services\Shop\EffectiveOrderPolicyService;
use App\Services\Shop\EloquentBranchOpeningWindow;
use App\Services\Shop\EloquentBrandOrderPolicyDefaults;
use App\Services\Shop\EloquentTableOccupancy;
use App\Services\Tax\Contracts\MenuDisplayTaxRates;
use App\Services\Tax\Contracts\MenuTaxTypeAnchors;
use App\Services\Topping\Internal\EloquentToppingSelectionExistence;
use App\Services\Topping\Internal\ToppingSelectionPricer;
use Illuminate\Support\ServiceProvider;

final class OrderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // #1581 — `CouponService` (Pricing) hiện thực mặt tiếp giáp mà
        // `OrderCouponService` (Ordering) hỏi ngược lại. Bind ở đây, không ở
        // Pricing, vì Ordering mới là bên TIÊU THỤ cổng này.
        $this->app->bind(
            CouponPricing::class,
            CouponService::class,
        );

        // #962 — SỔ COUPON. `CouponPricing` chỉ trả lời câu hỏi về GIÁ; phần
        // GHI (khoá coupon, tăng/giảm `times_used`, dòng `CouponRedemption`)
        // vẫn do nửa đơn hàng tự làm trên bảng của Pricing. Cổng này là phần
        // còn lại, và nó CÔNG BỐ được vì chữ ký không mang model nào.
        $this->app->bind(OrderCouponLedger::class, EloquentOrderCouponLedger::class);

        // #962 — DANH TÍNH ký offline. Verifier từng tự `find()` thẳng
        // `DeviceSigningKey` + `Device` và tự gọi `DeviceSigningKeyService`;
        // giờ nó chỉ hỏi cổng. Không đụng gì tới bề mặt ký: byte đem ký vẫn do
        // `OfflineOrderSigningMessage` dựng, vẫn chốt bằng golden fixture
        // chung với workstation.
        $this->app->bind(OfflineSigningIdentity::class, EloquentOfflineSigningIdentity::class);

        // #1590 — cổng CHIẾM DỤNG BÀN. Bind ở đây (bên tiêu thụ) theo đúng
        // tiền lệ `CouponPricing` ngay trên: `tables` do Organization sở hữu,
        // Ordering chỉ lái hai cột `current_order_id` + `status`.
        $this->app->bind(TableOccupancy::class, EloquentTableOccupancy::class);

        // #1589 — mã tiền tệ của chi nhánh. Sáu chỗ ở BỐN module đọc đúng một
        // cột `shop_order_settings.currency_code`; cổng hẹp đúng bằng câu hỏi đó.
        $this->app->bind(BranchCurrency::class, EloquentBranchCurrency::class);

        // #1595 — hai cổng khép lại chu trình Ordering↔Inventory. Bind ở đây
        // để cả hai chiều đọc được trong một chỗ: Ordering hỏi Inventory trừ
        // kho, Inventory ghi dấu ngược lên đơn — trước đây bằng cách với tay
        // vào `Internal`, giờ qua hợp đồng công bố.
        $this->app->bind(OrderLineStockDeduction::class, EloquentOrderLineStockDeduction::class);
        $this->app->bind(OrderStockMarker::class, EloquentOrderStockMarker::class);

        // #1605 — cổng thứ BA của cùng chu trình, chiều "Inventory hỏi Ordering":
        // sáu trường vô hướng của đơn mà động cơ trừ kho đọc. Trước đây nó
        // `CustomerOrder::find()` ở năm chỗ.
        $this->app->bind(OrderStockContextReads::class, EloquentOrderStockContextReads::class);

        // #1731 — cổng thứ TƯ của cùng chu trình, và là cái gỡ nốt cạnh cuối:
        // dòng đơn KÈM QUYỀN KHOÁ. Ba cổng trên chỉ đọc giá trị vô hướng nên
        // một VO là đủ; cái này còn phải mang theo `lockForUpdate()` vào trong
        // transaction ghi kho của Inventory — xem docblock của interface.
        $this->app->bind(OrderStockLineReads::class, EloquentOrderStockLineReads::class);

        // #962 — hai cổng ĐỌC nữa theo cùng chiều "Inventory hỏi Ordering", nên
        // bind cạnh hai cái trên chứ không rải sang provider khác:
        //   · `OrderCustomerContacts`      — thu hồi/diễn tập hỏi ai liên lạc được
        //   · `BranchStockDeductionTiming` — trừ kho hỏi cấu hình của chi nhánh
        // Khác `OrderLineStockDeduction` ở chỗ hiện thực nằm bên Ordering: cả hai
        // chỉ đọc bảng của Ordering, nên chủ sở hữu dữ liệu cũng là chủ truy vấn.
        $this->app->bind(OrderCustomerContacts::class, EloquentOrderCustomerContacts::class);
        $this->app->bind(BranchStockDeductionTiming::class, EloquentBranchStockDeductionTiming::class);

        // #1992 — đơn khách TRẢ CHƯA ĐỦ. Vị ngữ là scope trên model
        // (`CustomerOrder::partPaid()`), dùng chung với
        // `CustomerOutstandingOrderService`; cổng này là cách Composition với
        // tới nó mà không chạm model của module khác.
        $this->app->bind(PartPaidOrderReads::class, EloquentPartPaidOrderReads::class);

        // #1993 — cùng chiều, lần này là "sổ nợ hỏi Ordering": một khoản nợ nằm
        // trên `order_payments` nhưng ai nợ và đơn nào thì nằm trên
        // `customer_orders`. Bind cạnh `OrderCustomerContacts` vì hai cổng cắt
        // theo cùng một nguyên tắc — chủ sở hữu dữ liệu là chủ truy vấn — và cả
        // hai đều hiện thực bên Ordering.
        $this->app->bind(BranchDebtOrderAnchors::class, EloquentBranchDebtOrderAnchors::class);

        $this->app->singleton(EloquentOrderPersistence::class);
        // #1131 — the OrderPersistencePort binding was a DECOY seam:
        // OrderService injects the CONCRETE EloquentOrderPersistence, no class
        // resolves the port, so a decorator bound here silently did nothing.
        // Deleted so nobody trusts it. Restore ONLY together with the door
        // migration that makes the facade actually depend on the port (the
        // interface stays as that migration's target contract).
        $this->app->bind(OrderPricingResolutionPort::class, CustomerOrderPricingResolution::class);
        // #1096/#1097 — the offline evidence verifier. Bound to the port so
        // OrderService::replayOffline cannot be constructed without a verifier
        // (there is no unverified code path to fall back to).
        $this->app->bind(OrderEvidenceVerificationPort::class, OfflineOrderEvidenceVerifier::class);
        $this->app->bind(OrderMutationFacade::class, OrderService::class);

        // #1544 — the READ side of the boundary. `OrderQueryPort` shipped as an
        // interface with no implementation and no binding, so any caller that
        // trusted it got a container exception; every module needing to read an
        // order imported the model instead. Binding it is what makes the port a
        // real option rather than a decorative one.
        $this->app->bind(OrderQueryPort::class, EloquentOrderQuery::class);

        // #1662 — hai cổng Ordering KHAI nhưng module khác HIỆN THỰC. Chiều khai
        // báo bị đảo có chủ ý: Ordering publish theo namespace nên cổng nằm ở đây
        // là dùng được ngay, còn để Pos/Payments khai thì phải publish class-by-class
        // trong `config/modules.php`.
        $this->app->bind(OpenTillSessionLookup::class, TillSessionOpenLookup::class);
        $this->app->bind(OrderPaymentLedgerReads::class, EloquentOrderPaymentLedgerReads::class);

        // #1687 — cùng chiều đảo đó, cho bốn guard giữa-ca của
        // `PATCH /shops/{slug}/settings/order`: `ShopOrderSettingsService` GHI
        // `shop_order_settings` nên nó là Ordering, mà hai vị từ nó hỏi
        // ("quầy nào đang mở ca / chuỗi ca") là của Payments.
        $this->app->bind(BranchOpenShiftStatus::class, EloquentBranchOpenShiftStatus::class);

        // #962 — hai cổng Ordering KHAI và HIỆN THỰC, cho hai module khác đọc
        // `shop_order_settings` mà không cầm model của Ordering:
        //   · Pricing (`TaxResolver`)          → `default_tax_type_id`
        //   · Payments (`OrderPaymentService`) → ba cột cấu hình chia hoá đơn
        $this->app->bind(BranchDefaultTaxType::class, EloquentBranchDefaultTaxType::class);
        $this->app->bind(BranchSplitBillPolicy::class, EloquentBranchSplitBillPolicy::class);

        // #1594 — cùng chuỗi đó, mắt cuối: `BranchSplitBillPolicy` đã đưa BA CỘT
        // cấu hình qua cổng, nhưng phép tính vẫn nhận `CustomerOrder`. Cổng này
        // đóng nốt, nên Payments không còn phải cầm aggregate đơn hàng để so một
        // con số.
        $this->app->bind(OrderSplitBillTotals::class, EloquentSplitBillTotals::class);

        // #962 — hai tầng còn lại của chuỗi thuế #1218 mà `TaxResolver` (Pricing)
        // tự đọc bảng của Catalog: tầng 3 (`menus`) và tầng 2 (pivot
        // `menu_menu_sections`). Cùng hình dạng như `BranchDefaultTaxType` ngay
        // trên — cổng trả ID, Pricing tự nạp `TaxType` — nên thứ tự tầng vẫn chỉ
        // có một định nghĩa, trong `TaxResolver::walk()`.
        //
        // KHÔNG `singleton`: adapter không giữ trạng thái, còn memo thì thuộc về
        // vòng đời của `TaxResolver`, không phải của container.
        $this->app->bind(MenuTaxTypeAnchors::class, EloquentMenuTaxTypeAnchors::class);

        // #962 — cùng chiều khai báo đảo như `TableOccupancy` ngay trên: giờ mở
        // cửa là dữ liệu của Organization, nhưng cổng nằm ở `Order\Contracts` vì
        // Ordering là bên TIÊU THỤ và namespace đó đã được công bố sẵn.
        $this->app->bind(BranchOpeningWindow::class, EloquentBranchOpeningWindow::class);

        // #962 · 7a-7 — ba cổng nữa cùng chiều đó, gỡ nút thắt THUẾ ở đường ghi đơn.
        // `WritesCustomerOrders` từng cầm thẳng `TaxResolver` + `TaxType` (Pricing),
        // `MenuProduct` + `Product` + `ToppingGroupItem` (Catalog). Nút này là một
        // nút DUY NHẤT: mọi model kể trên chỉ tồn tại trong trait để nạp đối số cho
        // `TaxResolver::resolveForLine()`, nên phải gỡ cả cụm hoặc không gỡ được gì.
        //
        // KHÔNG `singleton` cho `OrderLineTaxPricing`: mỗi `beginBatch()` phải đẻ ra
        // một `TaxResolver` mới, đúng vòng đời memo mà plan-043 §7 yêu cầu.
        $this->app->bind(OrderLineTaxPricing::class, TaxResolverLineTaxPricing::class);

        // #1596 — cùng chiều, cùng lý do KHÔNG `singleton`, nhưng cho đường HIỂN
        // THỊ: `CustomerMenuService` (Catalog) từng `new TaxResolver` thẳng tay.
        // Cổng riêng chứ không dùng lại `OrderLineTaxPricing` vì cổng kia cố ý
        // phát cảnh báo thu-thiếu-thuế, và endpoint thực đơn chạy ở mọi lượt xem
        // trang — xem `MenuDisplayTaxRates`.
        $this->app->bind(MenuDisplayTaxRates::class, TaxResolverMenuDisplayRates::class);
        $this->app->bind(OrderMenuLineDirectory::class, EloquentOrderMenuLineDirectory::class);
        $this->app->bind(ToppingSelectionExistence::class, EloquentToppingSelectionExistence::class);

        // #962 (7b) — bốn cổng Ordering công bố cho Organization + Payments.
        // Ba cái đầu hẹp một câu hỏi; `InvoiceOrderSource` là cái gộp cho đường
        // xuất 適格請求書 (xem docblock của nó về việc vì sao KHÔNG cắt nhỏ).
        $this->app->bind(OpenOrderTableUsage::class, EloquentOpenOrderTableUsage::class);
        $this->app->bind(TableStatusJournal::class, EloquentTableStatusJournal::class);
        $this->app->bind(TableSessionCloser::class, EloquentTableSessionCloser::class);
        $this->app->bind(OrderTaxBreakdownReads::class, EloquentOrderTaxBreakdownReads::class);

        // #962 — chiều NGƯỢC với `BranchOpeningWindow`: ở đây Ordering là bên SỞ
        // HỮU dữ liệu. Pricing (`MenuPromotionService`) cần biết một khuyến mãi
        // đã được áp lên những dòng món nào; trước đó nó tự import
        // `App\Models\CustomerOrderItem` và chạy aggregate thẳng trên bảng đơn.
        $this->app->bind(PromotionRedemptionReads::class, EloquentPromotionRedemptionReads::class);

        // #962 · 7a-8 — nút thắt TOPPING/SKU, cùng chiều khai báo đảo như trên.
        //
        // `OrderToppingSelectionPricing` KHÔNG cần `beginBatch()` như cổng thuế:
        // `ToppingSelectionPricer` không memo hoá gì, mọi truy vấn của nó nằm gọn
        // trong một lời gọi. Nên `bind` ở đây là quyết định về vòng đời, không phải
        // sao chép mẫu — và nếu ai đó thêm memo vào pricer thì phải quay lại đọc
        // đúng đoạn này trước.
        $this->app->bind(OrderToppingSelectionPricing::class, ToppingSelectionPricer::class);
        $this->app->bind(OrderLineCatalogAnchors::class, EloquentOrderLineCatalogAnchors::class);

        // #962 — hai cổng cuối mà `TillSessionService` (Payments) cần từ Ordering.
        //
        // `BranchOrderSettingsLock` KHÔNG phải bản hai-cột của `BranchCurrency`
        // ngay trên: nó đọc dưới `lockForUpdate()` (AUDIT FIX 3.6), và cái khoá
        // ấy đua với `PATCH /shops/{slug}/settings/order` (plan-031). Bind bằng
        // `bind` chứ không `singleton` là cố ý — cổng này không giữ trạng thái,
        // và một singleton mời người sau cache kết quả, tức cache một giá trị
        // vừa được khoá để đọc cho đúng.
        $this->app->bind(BranchOrderSettingsLock::class, EloquentBranchOrderSettingsLock::class);
    }

    /**
     * plan-035 / #962 — xoá cache chính sách đơn khi brand policy đổi.
     *
     * Hai hook này TRƯỚC ĐÂY nằm trong `BrandOrderPolicy::booted()`, nghĩa là một
     * **model** phải biết tên **service** của module khác đang cache mình. Chiều đó
     * ngược: model là thứ bị quan sát, không phải thứ đi thông báo.
     *
     * Đăng ký ở provider (tầng Composition — tầng duy nhất được phép biết cả hai
     * bên) giữ nguyên hành vi mà cắt hẳn cạnh. Hai điều kiện `brand_id` rỗng được
     * chép nguyên: một policy chưa gán brand thì không có gì để xoá, và gọi
     * `forgetForBrand(null)` sẽ nổ.
     */
    public function boot(): void
    {
        BrandOrderPolicy::saved(function (BrandOrderPolicy $policy): void {
            if ($policy->brand_id) {
                EffectiveOrderPolicyService::forgetForBrand($policy->brand_id);
            }
        });

        BrandOrderPolicy::deleted(function (BrandOrderPolicy $policy): void {
            if ($policy->brand_id) {
                EffectiveOrderPolicyService::forgetForBrand($policy->brand_id);
            }
        });

        // #962 — mặc định cấp brand cho luồng đơn. Cùng chiều khai báo với hai
        // cổng ngay trên: `EffectiveOrderPolicyService` (Ordering) hỏi,
        // Organization trả lời, `brand_order_policies` ở lại đúng chủ.
        $this->app->bind(BrandOrderPolicyDefaults::class, EloquentBrandOrderPolicyDefaults::class);
    }
}
