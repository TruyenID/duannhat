<?php

namespace App\Providers;

use App\Broadcasting\BrandAwareBroadcastManager;
use App\Events\OrderPaid;
use App\Listeners\Loyalty\AwardPointsOnOrderPaid;
use App\Listeners\Notification\ModelEventToRuleBridge;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\MenuProductToppingItemOverride;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ProductSku;
use App\Models\ProductToppingGroup;
use App\Models\ProductToppingGroupItemOverride;
use App\Models\ShopOrderSetting;
use App\Models\StockAlert;
use App\Models\Table;
use App\Models\TaxType;
use App\Models\TillSession;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use App\Models\User;
use App\Modules\Kernel\ModuleRegistry;
use App\Observers\CatalogRevisionObserver;
use App\Observers\CustomerOrderNotificationObserver;
use App\Observers\ProductSkuObserver;
use App\Observers\ShopOrderSettingObserver;
use App\Observers\StockAlertNotificationObserver;
use App\Observers\TaxTypeObserver;
use App\Observers\ToppingGroupItemObserver;
use App\Policies\BranchMenuSchedulePolicy;
use App\Policies\ShopNotificationPolicy;
use App\Policies\ShopTillTrackingPolicy;
use App\Policies\TracePolicy;
use App\Services\Catalog\CatalogRevisionService;
use App\Services\Catalog\Contracts\CatalogRevisionMarker;
use App\Services\Catalog\Contracts\ProductCategoryLookup;
use App\Services\Catalog\EloquentProductCategoryLookup;
use App\Services\Customer\Contracts\CustomerAuthenticationPort;
use App\Services\Customer\Internal\EloquentCustomerAuthentication;
use App\Services\Device\Contracts\DeviceDirectory;
use App\Services\Device\Contracts\NotifiableDeviceDirectory;
use App\Services\Device\EloquentDeviceDirectory;
use App\Services\Device\Internal\EloquentNotifiableDeviceDirectory;
use App\Services\Iam\Contracts\RoleAssignmentDirectory;
use App\Services\Iam\Internal\EloquentRoleAssignmentDirectory;
use App\Services\Inventory\Contracts\ActiveMaterialDirectory;
use App\Services\Inventory\Contracts\MaterialDirectory;
use App\Services\Inventory\Contracts\WarehouseMemberDirectory;
use App\Services\Inventory\EloquentActiveMaterialDirectory;
use App\Services\Inventory\EloquentWarehouseMemberDirectory;
use App\Services\Inventory\Internal\EloquentMaterialDirectory;
use App\Services\Loyalty\Contracts\MembershipTierBackgrounds;
use App\Services\Loyalty\MembershipTierBackgroundService;
use App\Services\Menu\Contracts\ShopMenuSections;
use App\Services\Menu\Internal\EloquentShopMenuSections;
use App\Services\Notification\BrandApplicationProvider;
use App\Services\Notification\BrandReverbAppService;
use App\Services\Notification\Contracts\BrandEventBroadcaster;
use App\Services\Notification\MailWebhookManager;
use App\Services\Order\Contracts\BranchOrderMembership;
use App\Services\Order\Contracts\BranchOrderReads;
use App\Services\Order\Contracts\CustomerOrderPresence;
use App\Services\Order\Contracts\CustomerOrderReassignment;
use App\Services\Order\Contracts\OpenOrderSkuUsage;
use App\Services\Order\Contracts\OrderRowLock;
use App\Services\Order\Contracts\ReviewableOrderLines;
use App\Services\Order\Internal\EloquentBranchOrderMembership;
use App\Services\Order\Internal\EloquentBranchOrderReads;
use App\Services\Order\Internal\EloquentCustomerOrderPresence;
use App\Services\Order\Internal\EloquentCustomerOrderReassignment;
use App\Services\Order\Internal\EloquentOpenOrderSkuUsage;
use App\Services\Order\Internal\EloquentOrderRowLock;
use App\Services\Order\Internal\EloquentReviewableOrderLines;
use App\Services\Payment\Contracts\BranchPaymentTotals;
use App\Services\Payment\Gateway\PaymentGatewayRegistry;
use App\Services\Payment\Internal\EloquentBranchPaymentTotals;
use App\Services\Payment\Policy\Contracts\BranchManagementProjectionSource;
use App\Services\Payment\Policy\Contracts\PaymentPolicyPublicationClock;
use App\Services\Payment\Policy\Contracts\PaymentPolicyRevisionPersistence;
use App\Services\Payment\Policy\Persistence\EloquentPaymentPolicyRevisionPersistence;
use App\Services\Payment\Policy\SystemPaymentPolicyPublicationClock;
use App\Services\Payment\Policy\UnavailableBranchManagementProjectionSource;
use App\Services\Payment\Secret\Contracts\GatewayMasterKeyProvider;
use App\Services\Payment\Secret\Contracts\GatewaySecretStoreContract;
use App\Services\Payment\Secret\DatabaseGatewaySecretStore;
use App\Services\Payment\Secret\FileGatewayMasterKeyProvider;
use App\Services\Payment\Secret\GatewayConnectionSecretResolver;
use App\Services\Payment\Secret\GatewaySecretAuditProtection;
use App\Services\PeripheralDevice\Contracts\BranchCashDevices;
use App\Services\PeripheralDevice\Internal\EloquentBranchCashDevices;
use App\Services\Print\EscposRenderProbe;
use App\Services\Print\Renderer\BillKindPlans;
use App\Services\Print\Renderer\DocsKindPlans;
use App\Services\Print\Renderer\PrintKindRegistry;
use App\Services\Print\Renderer\ShiftKindPlans;
use App\Services\Print\RenderProbe;
use App\Services\Product\BrandCoreCatalogService;
use App\Services\Product\Contracts\BranchSkuPricing;
use App\Services\Product\Contracts\FloatingSectionAvailability;
use App\Services\Product\Contracts\MaterialAllergenPropagation;
use App\Services\Product\Contracts\ProductCatalogProjectionPort;
use App\Services\Product\Contracts\ProductReviewAggregates;
use App\Services\Product\Contracts\ProductTaxStamp;
use App\Services\Product\Contracts\RecipeDirectory;
use App\Services\Product\Contracts\RecipeGraph;
use App\Services\Product\Contracts\ReviewedSkuDirectory;
use App\Services\Product\Contracts\SkuDirectory;
use App\Services\Product\Internal\EloquentBranchSkuPricing;
use App\Services\Product\Internal\EloquentFloatingSectionAvailability;
use App\Services\Product\Internal\EloquentProductPersistence;
use App\Services\Product\Internal\EloquentProductTaxStamp;
use App\Services\Product\Internal\EloquentRecipeDirectory;
use App\Services\Product\Internal\EloquentRecipeGraph;
use App\Services\Product\Internal\EloquentReviewedSkuDirectory;
use App\Services\Product\Internal\EloquentSkuDirectory;
use App\Services\Product\Internal\RecipeAllergenPropagation;
use App\Services\Product\MenuService;
use App\Services\Promotion\Contracts\CustomerCouponReassignment;
use App\Services\Promotion\Contracts\CustomerOwnedCoupons;
use App\Services\Promotion\Contracts\FloatingSectionPricing;
use App\Services\Promotion\Contracts\MenuDisplayPromotions;
use App\Services\Promotion\Contracts\MenuPromotionResolver;
use App\Services\Promotion\Contracts\PersonalCouponMinting;
use App\Services\Promotion\FloatingSectionPriceResolver;
use App\Services\Promotion\Internal\EloquentCustomerCouponReassignment;
use App\Services\Promotion\Internal\EloquentCustomerOwnedCoupons;
use App\Services\Promotion\Internal\EloquentMenuDisplayPromotions;
use App\Services\Promotion\MenuPromotionService;
use App\Services\Promotion\PersonalCouponMinter;
use App\Services\Shop\Contracts\BranchCashVarianceTolerance;
use App\Services\Shop\Internal\EloquentBranchCashVarianceTolerance;
use App\Services\Tax\Contracts\TaxTypeDirectory;
use App\Services\Tax\Internal\EloquentTaxTypeDirectory;
use App\Services\Till\Contracts\OrgTenderVocabulary;
use App\Services\Till\EloquentOrgTenderVocabulary;
use App\Services\Topping\Contracts\ToppingGroupItemIntegrity;
use App\Services\Topping\Contracts\ToppingLinePricing;
use App\Services\Topping\Internal\EloquentToppingGroupItemIntegrity;
use App\Services\Topping\ToppingPricingService;
use App\Sso\DevSubjectValidator;
use App\Sso\UserProvisioner;
use App\Support\BusinessClock;
use Dxs\Auth\Contracts\ProvisionsUsers;
use Dxs\Auth\Contracts\ValidatesDevelopmentSubjects;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Reverb\ApplicationManager;
use Laravel\Reverb\Contracts\ApplicationProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Policy target and ability pairs a branch-scoped POS device token may be
     * granted through Gate::before. Both dimensions are required: allowing a
     * generic ability such as `delete` by name alone would silently grant it
     * on every current and future policy.
     *
     * @var array<class-string, list<string>>
     */
    private const DEVICE_GATE_ALLOWLIST = [
        Customer::class => ['create', 'view'],
        CustomerOrder::class => [
            'viewAny',
            'view',
            'create',
            'update',
            'delete',
            'checkout',
            'cancel',
            'refund',
            'applyCoupon',
            'releaseCoupon',
        ],
        Menu::class => ['shopView'],
        OrderPayment::class => ['viewAny', 'create', 'refund'],
        PaymentMethod::class => ['viewAny'],
        Table::class => ['viewAny', 'changeStatus'],
        TillSession::class => [
            'viewAny',
            'view',
            'viewCurrent',
            'open',
            'recordEvent',
            'close',
            'handover',
            'abandon',
        ],
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // #1095 — one instance per request so the dirty-branch registry can
        // collapse a multi-row menu mutation into a single revision bump.
        $this->app->singleton(CatalogRevisionService::class);

        // #1661 — cổng đánh dấu bản catalog. Chính `CatalogRevisionService`

        // hiện thực nó; cổng tồn tại để Ordering/Pricing báo được sang Catalog

        // mà không phải import service của Catalog.

        $this->app->bind(CatalogRevisionMarker::class, CatalogRevisionService::class);

        $this->app->bind(ProductCatalogProjectionPort::class, MenuService::class);

        // #1567 — cổng đọc CÔNG THỨC. Inventory (đóng lô nguyên liệu) hỏi
        // Catalog thay vì tự truy vấn `App\Models\Recipe`.
        $this->app->bind(RecipeDirectory::class, EloquentRecipeDirectory::class);

        // #2371 — cổng đọc DANH MỤC CỦA SẢN PHẨM. Ordering hỏi Catalog thay vì
        // tự `DB::table('product_category')`.
        $this->app->bind(ProductCategoryLookup::class, EloquentProductCategoryLookup::class);
        // #2522 — read-only port so Ordering can ask "has the kitchen been told?"
        // without reading Printing's table directly.
        $this->app->bind(SkuDirectory::class, EloquentSkuDirectory::class);

        // Bốn bind còn lại của ranh giới khách (đọc, xác minh quyền, ghi, và
        // mặt tiền) nằm ở `CustomerServiceProvider`. `DomainMutationContractsTest`
        // cấm TỆP NÀY chứa tên các hợp đồng chuẩn plan-047 — cấm theo chuỗi ký
        // tự, nên nó bắt cả chú thích: bản đầu của đúng đoạn này liệt kê bốn tên
        // ra và bị đỏ dù không bind gì. Muốn tra tên, đọc provider kia.
        //
        // `CustomerAuthenticationPort` ở lại đây vì nó KHÔNG thuộc plan-047 —
        // cổng đăng nhập, không đóng dấu đột biến nào.
        $this->app->bind(CustomerAuthenticationPort::class, EloquentCustomerAuthentication::class);

        // #1772 — cổng ảnh nền thẻ thành viên: Shop gọi qua interface, Loyalty
        // giữ cách làm. Thiếu bind này thì deptrac XANH mà container CHẾT
        // ("Target … is not instantiable") — hai rào đo hai thứ khác nhau.
        $this->app->bind(
            MembershipTierBackgrounds::class,
            MembershipTierBackgroundService::class,
        );

        // #962 — cổng TRA LOẠI THUẾ. Catalog (menu tier / section nổi / gán
        // theo danh mục / nhập CSV) hỏi Pricing "id này gán được không" thay
        // vì tự truy vấn `App\Models\TaxType` ở bốn chỗ với bốn điều kiện lọc
        // viết tay. Cổng KHÔNG trả mức thuế — mức thuế do `TaxResolver` phân
        // giải rồi snapshot bất biến lên dòng đơn (plan-043).
        $this->app->bind(TaxTypeDirectory::class, EloquentTaxTypeDirectory::class);

        // #962 — cổng DANH MỤC NGUYÊN VẬT LIỆU. Catalog (`RecipeService`,
        // `RecipeImporter`) hỏi Inventory thay vì tự truy vấn `App\Models\Material`.
        $this->app->bind(MaterialDirectory::class, EloquentMaterialDirectory::class);

        // #962 — chiều ngược lại: `MaterialService` về với Inventory (nó quản
        // aggregate `Material`), nên hai thứ nó cần từ Catalog đi qua cổng.
        $this->app->bind(RecipeGraph::class, EloquentRecipeGraph::class);
        $this->app->bind(MaterialAllergenPropagation::class, RecipeAllergenPropagation::class);

        // #1597 — cổng TRA GIÁ BÁN. Ordering hỏi Catalog "chi nhánh này bán
        // món này bao nhiêu" thay vì tự truy vấn `ProductSku`+`MenuProductSku`.
        $this->app->bind(BranchSkuPricing::class, EloquentBranchSkuPricing::class);
        $this->app->bind(FloatingSectionAvailability::class, EloquentFloatingSectionAvailability::class);

        // #1622 — cổng đọc PHÂN CÔNG VAI. Notifications hỏi PlatformIntegration
        // thay vì tự `DB::table('role_user_pivots')` ở 5 resolver.
        $this->app->bind(RoleAssignmentDirectory::class, EloquentRoleAssignmentDirectory::class);

        // #1666 — Payments hỏi PlatformIntegration "thiết bị này là ai" (tên hiển
        // thị + còn sống trong org/branch nào) thay vì cầm `App\Models\Device`.
        $this->app->bind(DeviceDirectory::class, EloquentDeviceDirectory::class);

        // #962 — sáu cổng hẹp khép nốt phần nợ "một class, một hai cạnh".
        // Mỗi cái thay đúng những câu truy vấn mà một module đang chạy trên
        // bảng của module khác; không cái nào mở thêm khả năng nào.
        //
        //  · Notifications → PlatformIntegration (audience theo thiết bị)
        //  · Notifications → Inventory          (vai theo kho)
        //  · PlatformIntegration → Payments     (từ vựng tender của tổ chức)
        //  · Catalog → Inventory                (nguyên liệu cha trong BOM)
        //  · Catalog → Pricing                  (loại thuế có đúng brand không)
        //  · CustomerEngagement → Pricing       (đúc coupon khi đổi điểm)
        $this->app->bind(NotifiableDeviceDirectory::class, EloquentNotifiableDeviceDirectory::class);
        $this->app->bind(WarehouseMemberDirectory::class, EloquentWarehouseMemberDirectory::class);
        $this->app->bind(OrgTenderVocabulary::class, EloquentOrgTenderVocabulary::class);
        $this->app->bind(ActiveMaterialDirectory::class, EloquentActiveMaterialDirectory::class);
        $this->app->bind(PersonalCouponMinting::class, PersonalCouponMinter::class);

        // #1622 — Catalog hỏi Ordering "SKU này còn nằm trong đơn chưa đóng
        // không" thay vì tự đọc ba bảng của Ordering (plan-042, đường xoá SKU).
        $this->app->bind(OpenOrderSkuUsage::class, EloquentOpenOrderSkuUsage::class);

        // #1622 — báo cáo doanh thu POS hỏi Catalog "cửa hàng này bày mục menu
        // nào" thay vì tự đọc `menus`/`menu_sections`/`menu_menu_sections`.
        $this->app->bind(ShopMenuSections::class, EloquentShopMenuSections::class);

        // #1622 — báo cáo doanh thu POS hỏi Payments "đã thu ròng bao nhiêu"
        // thay vì tự cộng `order_payments` (sổ phụ AR, #1151).
        $this->app->bind(BranchPaymentTotals::class, EloquentBranchPaymentTotals::class);

        // #1622 — quyền KHOÁ dòng đơn, tách khỏi quyền đọc (cổng đọc đơn).
        $this->app->bind(OrderRowLock::class, EloquentOrderRowLock::class);
        $this->app->bind(BranchOrderReads::class, EloquentBranchOrderReads::class);

        // #1596 — "khách còn đơn chưa đóng?" là câu hỏi của Ordering.
        $this->app->bind(CustomerOrderPresence::class, EloquentCustomerOrderPresence::class);
        // #2878 — hai cổng của sổ lượt thu 釣銭機.
        $this->app->bind(BranchOrderMembership::class, EloquentBranchOrderMembership::class);
        $this->app->bind(
            BranchCashVarianceTolerance::class,
            EloquentBranchCashVarianceTolerance::class,
        );
        $this->app->bind(
            BranchCashDevices::class,
            EloquentBranchCashDevices::class,
        );
        $this->app->bind(CustomerOrderReassignment::class, EloquentCustomerOrderReassignment::class);
        $this->app->bind(CustomerCouponReassignment::class, EloquentCustomerCouponReassignment::class);

        // #1597 — Ordering nhận KẾT QUẢ tính khuyến mãi, không nhận model.
        $this->app->bind(MenuPromotionResolver::class, MenuPromotionService::class);

        /*
         * #962 (cụm CustomerEngagement) — bốn cổng gỡ nợ ranh giới của thực đơn
         * khách, ví coupon và luồng đánh giá sau đơn. Mỗi cổng thay đúng một chỗ
         * CustomerEngagement đang tự đọc bảng của module khác:
         *
         *   MenuDisplayPromotions   Pricing  ← CustomerMenuService (khuyến mãi + endsAt)
         *   CustomerOwnedCoupons    Pricing  ← CustomerCouponWalletService
         *   ToppingGroupItemIntegrity Catalog ← MenuLocalizationIntegrityReporter
         *   ReviewableOrderLines    Ordering ┐
         *   ReviewedSkuDirectory    Catalog  ├ ProductReviewService
         *   ProductReviewAggregates Catalog  ┘
         */
        $this->app->bind(MenuDisplayPromotions::class, EloquentMenuDisplayPromotions::class);
        $this->app->bind(CustomerOwnedCoupons::class, EloquentCustomerOwnedCoupons::class);
        $this->app->bind(ToppingGroupItemIntegrity::class, EloquentToppingGroupItemIntegrity::class);
        $this->app->bind(ReviewableOrderLines::class, EloquentReviewableOrderLines::class);
        $this->app->bind(ReviewedSkuDirectory::class, EloquentReviewedSkuDirectory::class);
        $this->app->bind(ProductReviewAggregates::class, EloquentReviewedSkuDirectory::class);
        // #2346 — Provisioning đóng dấu loại thuế mặc định lên product qua cổng
        // này, thay vì ghi thẳng `products` (vi phạm ranh giới plan-047 gate 4).
        $this->app->bind(ProductTaxStamp::class, EloquentProductTaxStamp::class);
        $this->app->bind(FloatingSectionPricing::class, FloatingSectionPriceResolver::class);
        $this->app->bind(ToppingLinePricing::class, ToppingPricingService::class);
        $this->app->singleton(ProvisionsUsers::class, UserProvisioner::class);

        // #2037 — the SSO package binds both of its contracts with
        // `singletonIf`, so an app class merely IMPLEMENTING one is never
        // reached: the package's own default is already registered by the time
        // this provider runs. `ProvisionsUsers` above was wired; its sibling
        // was not, and `App\Sso\DevSubjectValidator` sat as dead code while the
        // package's subject-list validator guarded every dev-bypass request.
        // Additive by construction — the app validator still honours
        // `sso.dev_bypass.subjects` verbatim and only ADDS the email allowlist,
        // so no machine loses a login by this bind. See that class's docblock.
        $this->app->singleton(ValidatesDevelopmentSubjects::class, DevSubjectValidator::class);

        // plan-053 M5 (#1171 T5.1) — the publish-time render trial (DESIGN
        // §4.6), now backed by the real ESC/POS primitives.
        //
        // The old StructuralRenderProbe measured geometry with its own width
        // table, which disagreed with the workstation's on emoji and a few
        // other ranges — a ruler of a different length from the printer's. The
        // ESC/POS probe shares `Layout`/`Escpos` with the renderer, and adds
        // the check geometry alone could never make: whether the authored text
        // is representable in the printer's Shift_JIS codepage at all.
        $this->app->bind(RenderProbe::class, EscposRenderProbe::class);

        // plan-053 T5.1d slice 0 (#1897) — bảng dispatch của renderer ESC/POS.
        //
        // SINGLETON, và đó là điều kiện để nó hoạt động chứ không phải tối ưu:
        // các slice emitter (bill/docs/shift) đăng ký kind VÀO instance này.
        // Nếu mỗi `app()` trả một instance mới thì mỗi lượt đăng ký rơi vào
        // một bảng khác và kind "biến mất" — không có lỗi, chỉ là không in
        // được, đúng kiểu hỏng im lặng mà cổng parity sinh ra để chặn.
        //
        // Bên Go đây là biến package nạp qua `init()`; PHP không có đối ứng an
        // toàn (thứ tự require khác nhau giữa web request và lệnh artisan), nên
        // container là chỗ đúng.
        //
        // Slice 1a (#1908) đăng ký họ bill; slice 0 để trống có chủ đích.
        $this->app->singleton(PrintKindRegistry::class, static function (): PrintKindRegistry {
            $registry = new PrintKindRegistry;
            BillKindPlans::register($registry);
            DocsKindPlans::register($registry);
            ShiftKindPlans::register($registry);

            return $registry;
        });

        // #1208 item 6 — the `database` Reverb application driver, so the
        // running server recognises the per-brand keys this codebase has been
        // provisioning and handing to admin-web + KDS all along. Additive: it
        // answers from the config apps first, so the `.env` app is unaffected.
        // See BrandApplicationProvider for why there is no cache.
        $this->app->resolving(ApplicationManager::class, function (ApplicationManager $manager): void {
            $manager->extend('database', fn (): ApplicationProvider => new BrandApplicationProvider(
                $manager->createConfigDriver(),
            ));
        });

        // plan-012 T4.5 — wrap the framework's BroadcastManager so callers
        // can scope a broadcast to a brand's Reverb app creds via
        // `app(BrandAwareBroadcastManager::class)->brand($b)->broadcast(...)`.
        $this->app->singleton(BrandAwareBroadcastManager::class, function ($app) {
            return new BrandAwareBroadcastManager($app->make(BroadcastManager::class));
        });

        // #962 — cổng do Notifications KHAI, Composition HIỆN THỰC. Trước đây
        // `RealtimeChannel` inject thẳng class trên, tức một module phụ thuộc
        // vào tầng Composition — chiều duy nhất trong bản đồ layer có thể khép
        // vòng qua toàn hệ thống.
        $this->app->bind(BrandEventBroadcaster::class, BrandAwareBroadcastManager::class);

        // plan-023 M4 T4.4 — driver registry for inbound mail webhooks
        // (Postmark default; SES / Mailgun additive by calling
        // ->register($name, $factory) from a service provider).
        $this->app->singleton(MailWebhookManager::class);

        $this->app->singleton(PaymentGatewayRegistry::class, fn ($app) => new PaymentGatewayRegistry(
            $app,
            (array) config('payments.gateway_drivers', []),
        ));

        $this->app->singleton(GatewayMasterKeyProvider::class, fn () => new FileGatewayMasterKeyProvider(
            config('payments.secret_store.keyring_path'),
            [base_path(), public_path()],
        ));
        $this->app->singleton(GatewaySecretStoreContract::class, fn ($app) => new DatabaseGatewaySecretStore(
            $app->make('db')->connection(),
            $app->make(GatewayMasterKeyProvider::class),
            $app->make(GatewaySecretAuditProtection::class),
        ));
        $this->app->singleton(GatewaySecretAuditProtection::class, fn ($app) => new GatewaySecretAuditProtection(
            $app->make('db')->connection(),
        ));
        $this->app->singleton(GatewayConnectionSecretResolver::class);

        // Plan 047 T2.5 — fail closed. KHÔNG suy quyền sở hữu thương nhân từ cờ
        // trên bảng `branches` cục bộ: chúng quyết định tiền thuộc về ai.
        // Nguồn chân lý là PLATFORM (console org/brand/branch/role). Nó đã có sẵn
        // cả hai vế (brand→org = sở hữu, branch→org = vận hành) nhưng còn thiếu
        // vòng đời grant + endpoint đọc — chi tiết, cách tự kiểm và hệ quả đo
        // được nằm ở docblock của `UnavailableBranchManagementProjectionSource`.
        $this->app->singleton(
            BranchManagementProjectionSource::class,
            UnavailableBranchManagementProjectionSource::class,
        );
        $this->app->singleton(
            PaymentPolicyRevisionPersistence::class,
            EloquentPaymentPolicyRevisionPersistence::class,
        );
        $this->app->singleton(
            PaymentPolicyPublicationClock::class,
            SystemPaymentPolicyPublicationClock::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // BusinessClock memoises branch timezones inside one request/job. Queue
        // workers boot once and then live for hours, so clear between jobs or a
        // timezone edit remains invisible until the worker restarts (#2838).
        BusinessClock::flushTimezoneMemo();
        Queue::looping(fn () => BusinessClock::flushTimezoneMemo());

        // #962 Phase 1 (#1359) — mọi module tự khai wiring của nó. Kernel không
        // biết module chứa gì, chỉ biết cách bảo một module tự đăng ký.
        // Module nào chưa có mã thì bỏ qua, nên thêm module không phải sửa chỗ này.
        (new ModuleRegistry($this->app, (array) config('modules')))->boot();

        if (filled(config('sso.public_url'))) {
            $publicUrl = rtrim((string) config('sso.public_url'), '/');
            URL::forceRootUrl($publicUrl);

            $publicScheme = parse_url($publicUrl, PHP_URL_SCHEME);
            if (is_string($publicScheme) && in_array($publicScheme, ['http', 'https'], true)) {
                URL::forceScheme($publicScheme);
            }
        }

        $this->loadMigrationsFrom(database_path('migrations/omnify'));

        // Add SSO models to morph map (OmnifyServiceProvider only registers
        // Omnify-generated models, these are manual additions that survive regeneration)
        Relation::morphMap([
            'Brand' => Brand::class,
            'Branch' => Branch::class,
            'Customer' => Customer::class,
            'Organization' => Organization::class,
            'User' => User::class,
        ]);

        // Explicit route model bindings — required because product.php routes
        // are included in multiple prefix groups (legacy + brand-scoped).
        // Without this, implicit binding fails in nested groups.
        Route::bind('product', function (string $value) {
            return Product::findOrFail($value);
        });

        // Recompute ProductSku::option_signature on every save so the
        // (product_id, option_signature) unique index is enforced consistently
        // across services, factories, and direct model writes.
        ProductSku::observe(ProductSkuObserver::class);

        // plan-008: fire stock.alert.* notification when a StockAlert is created.
        StockAlert::observe(StockAlertNotificationObserver::class);

        /*
         * Per-brand provisioning on Brand::created — #962.
         *
         * TRƯỚC ĐÂY là `App\Observers\BrandObserver`, tức một class thuộc
         * TenancyKernel (mọi module đều được biết nó) lại constructor-inject hai
         * SERVICE của hai module khác. Chiều đó ngược: kernel là thứ ai cũng
         * biết, nên nó không được biết ai. Đăng ký ở provider (tầng Composition
         * — tầng duy nhất được phép biết cả hai bên) giữ nguyên hành vi mà cắt
         * hẳn hai cạnh.
         *
         *   - Reverb app credentials (plan-012 T4.3)
         *   - Combo ProductType + Combos Category (admin-web combo flow)
         *
         * Cả hai lệnh đều idempotent, nên bắn lại hook (replay event, reseed
         * thủ công) là an toàn.
         *
         * LƯU Ý (plan-043 audit fix 1.2): provisioning tax-type chuẩn CỐ Ý
         * không nằm ở đây — hàng trăm test tạo brand qua factory rồi tự seed
         * TaxType riêng (unique [brand_id, code] sẽ đụng).
         *
         * #2320 — "entrypoint provisioning của Platform" mà ghi chú này viện dẫn
         * KHÔNG TỒN TẠI cho tới #2320: không route, không listener, không lệnh
         * nào gọi `ensureStandardTypesForBrand`, và nhánh production của
         * `DatabaseSeeder` cũng không chạy `TaxTypeSeeder`. Brand sinh ra lúc
         * chạy (đồng bộ Platform khi có người đăng nhập) vì thế không có loại
         * thuế nào, và `TaxResolver` chỉ kịp ghi log.
         *
         * Nay entrypoint đó là thật: {@see \App\Sso\UserProvisioner::syncBrands}
         * gọi {@see \App\Services\Provisioning\BrandBaselineProvisioner}, cùng
         * provisioner mà `BaselineProvisioningSeeder` và
         * `php artisan provisioning:reconcile` dùng.
         *
         * Hook này CỐ Ý vẫn là tập con (không tax type) vì lý do test fixture ở
         * trên. Nó không phải bản cài đặt thứ hai — hai lời gọi dưới đây và
         * provisioner đều đi vào đúng hai service này.
         */
        Brand::created(function (Brand $brand): void {
            app(BrandReverbAppService::class)->provision($brand);
            app(BrandCoreCatalogService::class)->ensureCombo($brand);
        });

        // plan-008: fire order.status_changed notification on CustomerOrder status transitions.
        CustomerOrder::observe(CustomerOrderNotificationObserver::class);

        // plan-013: cascade hard-delete ToppingGroupItemSku when item is soft-deleted.
        ToppingGroupItem::observe(ToppingGroupItemObserver::class);

        // #1095 — per-branch catalog revision for offline-order evidence. Bound
        // as an observer (not inside MenuService) so EVERY writer bumps it:
        // a writer that silently skipped the bump would make an honest offline
        // order unverifiable later.
        Menu::observe(CatalogRevisionObserver::class);
        // #1661 — `MenuMenuSection::observe(...)` ĐÃ Ở ĐÂY và **không làm gì
        // cả**, theo hai lý do độc lập: observer không có nhánh nào cho model
        // đó, và `menu_menu_sections` khoá kép nên mọi lối ghi đều là query
        // builder qua `belongsToMany` — không sự kiện model nào bắn ra (#1657
        // còn cấm hẳn việc ghi qua model). Một dòng đăng ký kèm comment nói nó
        // chặn được rò thuế, mà thật ra chặn KHÔNG GÌ, tệ hơn là không có dòng
        // nào: nó làm người đọc sau tin rằng chỗ này đã được canh.
        //
        // Việc canh thật nằm ở `App\Services\Catalog\MenuSectionPivotWriter`
        // — chỗ ghi pivot DUY NHẤT, có test kiến trúc chặn đường ghi thẳng.
        MenuProduct::observe(CatalogRevisionObserver::class);
        // #1661 — ba tầng thuế cuối (4 · 5 · 6 + thuế suất). Xem docblock của
        // `CatalogRevisionObserver::mark()` cho lý do từng cái.
        Product::observe(CatalogRevisionObserver::class);
        // Tầng 5 (Ordering) và tầng 6 + thuế suất (Pricing): observer của CHÍNH
        // module sở hữu bảng, báo sang Catalog qua cổng công bố. Catalog tự đọc
        // hai bảng này là cạnh deptrac chặn — và chặn đúng.
        ShopOrderSetting::observe(ShopOrderSettingObserver::class);
        TaxType::observe(TaxTypeObserver::class);
        MenuProductSku::observe(CatalogRevisionObserver::class);
        ProductSku::observe(CatalogRevisionObserver::class);
        // #1114 — topping config is priced money; it versions the catalog too.
        ToppingGroup::observe(CatalogRevisionObserver::class);
        ProductToppingGroup::observe(CatalogRevisionObserver::class);
        ToppingGroupItem::observe(CatalogRevisionObserver::class);
        ToppingGroupItemSku::observe(CatalogRevisionObserver::class);
        ProductToppingGroupItemOverride::observe(CatalogRevisionObserver::class);
        MenuProductToppingItemOverride::observe(CatalogRevisionObserver::class);
        // #962 — cuộn lại bộ đếm review trên Product khi một dòng review bị xoá.
        // Trước là `App\Observers\ProductReviewObserver` (CustomerEngagement)
        // gọi thẳng persistence của Catalog; hook đăng ký ở provider giữ nguyên
        // hành vi mà không bắt module này biết tên class của module kia.
        ProductReview::deleting(function (ProductReview $review): void {
            app(EloquentProductPersistence::class)->decrementReviewAggregates(
                (string) $review->product_id,
                (int) $review->rating,
                $review->sentiment,
            );
        });

        // plan-014: branch-level schedule override gates — bound via named Gate::define
        // because BranchMenuSchedulePolicy is not auto-discoverable (no matching model name).
        Gate::define('branch-schedule.upsert', [BranchMenuSchedulePolicy::class, 'upsert']);
        Gate::define('branch-schedule.reset', [BranchMenuSchedulePolicy::class, 'reset']);

        // TracePolicy is not auto-discoverable either — there is no Trace model,
        // it guards a capability. It was written, documented as HQ-only, and
        // never wired: TraceController authorised MaterialLot::viewAny instead,
        // which allows shop-manager, staff and shop-staff. So the "only HQ roles
        // get access" in its docblock was never enforced (#1271).
        Gate::define('trace.view', [TracePolicy::class, 'view']);

        // Plan-023 M6 T6.4 — named shop-level notification gates. Distinct
        // ability names dodge the Laravel "one policy per model" dispatch
        // that would otherwise route every `manageAudiences` call against
        // the Notification model class through the HQ NotificationPolicy.
        Gate::define('shop.notifications.manageAudiences', [ShopNotificationPolicy::class, 'manageAudiences']);
        Gate::define('shop.notifications.manageTemplates', [ShopNotificationPolicy::class, 'manageTemplates']);
        Gate::define('shop.notifications.manageRouting', [ShopNotificationPolicy::class, 'manageRouting']);
        Gate::define('shop.notifications.compose', [ShopNotificationPolicy::class, 'compose']);
        Gate::define('shop.notifications.viewAudit', [ShopNotificationPolicy::class, 'viewAudit']);

        // Plan-023 M8 T8.13 — coverage catalogue gate. Granted to org admins:
        // brand KHÔNG có role riêng trong RoleTemplateMatrix, và mọi phép phân
        // giải phạm vi brand đều đi qua tổ chức sở hữu brand (xem
        // RoleResolver::brandRole). Slug cũ `brand_admin` không tồn tại nên
        // cổng này từ chối MỌI người kể từ khi được viết (#2451).
        Gate::define('viewNotificationCoverage', function ($user, Brand $brand) {
            return $user->hasRoleInContext('org-admin', $user->console_organization_id);
        });

        // Plan-036 — Manager Till Tracking. Four virtual abilities (no
        // single model maps to this policy — TillSession's policy slot is
        // already taken by TillSessionPolicy for the POS surface). Branch
        // isolation is enforced by ResolveShopFromSlug stamping
        // request.attributes.shop_id before the gate fires.
        Gate::define('shop.till.tracking.viewDashboard', [ShopTillTrackingPolicy::class, 'viewDashboard']);
        Gate::define('shop.till.tracking.viewSessions', [ShopTillTrackingPolicy::class, 'viewSessions']);
        Gate::define('shop.till.tracking.viewSession', [ShopTillTrackingPolicy::class, 'viewSession']);
        Gate::define('shop.till.tracking.printZReport', [ShopTillTrackingPolicy::class, 'printZReport']);

        // Device-authenticated POS requests bypass policy checks ONLY for the
        // abilities in DEVICE_GATE_ALLOWLIST below. Authorization is
        // otherwise enforced upstream by AuthenticateSsoOrDevice (device type
        // must be 'pos') + ResolvesShopContext (device branch_id must match
        // the resolved shop.id), so by the time a controller reaches
        // `$this->authorize(...)` the device is already proven to belong to
        // this branch. The bypass flag is set ONLY by AuthenticateSsoOrDevice
        // — the older `device.auth` middleware used by kiosk/kds/handy/
        // workstation/tms never sets it, so those namespaces stay in the
        // standard policy path.
        //
        // Issue #903: this used to bypass EVERY ability unconditionally, then
        // relied on a denylist that remained vulnerable whenever a new
        // sensitive ability was added. The allowlist is deliberately
        // fail-closed: manager-only abilities (viewStale, forceAbandon,
        // manualSettle, void) and every unknown future ability are denied.
        //
        // The `?Authenticatable $user = null` signature is deliberate.
        // On device auth we never call `Auth::setUser()`, so Laravel
        // invokes Gate::before with `$user = null`. Its guest-check
        // (Gate::callbackAllowsGuests) reflects on the first parameter
        // and only calls the closure when that parameter is nullable-
        // typed OR defaults to null. A zero-arg closure fails the check
        // and gets silently skipped — the exact scenario we need.
        Gate::before(function (?Authenticatable $user = null, ?string $ability = null, array $arguments = []) {
            if (! request()?->attributes->get('_device_bypass_gate')) {
                return null;
            }

            $target = $arguments[0] ?? null;
            $targetClass = is_object($target) ? $target::class : (is_string($target) ? $target : null);

            if ($targetClass !== null && in_array($ability, self::DEVICE_GATE_ALLOWLIST[$targetClass] ?? [], true)) {
                return true;
            }

            Log::channel('pos_auth')->warning('pos_device_gate_denied', [
                'ability' => $ability,
                'target' => $targetClass,
                'route' => request()?->route()?->getName(),
                'method' => request()?->method(),
                'path' => request()?->path(),
                'device_id' => request()?->attributes->get('device')?->id,
                'shop_id' => request()?->attributes->get('shop_id'),
                'request_id' => request()?->headers->get('X-Request-ID'),
            ]);

            return false;
        });

        // Plan-023 M7 T7.3 — wildcard Eloquent listener routing
        // model.{created,updated,deleted} events through the rule
        // engine. The bridge enforces its own static BLOCKLIST of
        // notification-domain models (arch test T7.15 guards the
        // literal list).
        $bridge = ModelEventToRuleBridge::class;
        Event::listen('eloquent.created: *', $bridge);
        Event::listen('eloquent.updated: *', $bridge);
        Event::listen('eloquent.deleted: *', $bridge);

        // Plan-023 M8 T8.2 — custom.* event bridge for non-Eloquent triggers.
        // Every dispatcher MUST wrap payload in CustomNotificationEvent.
        Event::listen('custom.*', [$bridge, 'onCustomEvent']);

        // #1441 — tích điểm khi đơn đóng. Đăng ký tay (không dựa vào event
        // discovery) để việc "cái gì chạy khi trả tiền xong" đọc được ở một
        // chỗ, giống ba dòng bridge ngay trên.
        Event::listen(OrderPaid::class, AwardPointsOnOrderPaid::class);

        $this->configureRateLimiters();

        VerifyEmail::createUrlUsing(function ($notifiable) {
            $routeName = $notifiable instanceof Customer
                ? 'api.v1.customer.verification.verify'
                : 'verification.verify';

            $parameters = [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ];

            if ($notifiable instanceof Customer) {
                // #1680 — link phải trả khách về ĐÚNG chỗ họ vừa rời đi, nên
                // ngôn ngữ và cửa hàng đi kèm ngay trong URL. Hai giá trị này
                // nằm TRONG chữ ký (Laravel ký cả query string), nên không ai
                // sửa được chúng để lái khách sang cửa hàng khác — và cũng vì
                // vậy chúng phải được chốt lúc GỬI: lúc khách bấm link, request
                // đến từ ứng dụng mail, không mang theo ngữ cảnh nào của phiên
                // đăng ký.
                $parameters['locale'] = App::getLocale();

                $shopSlug = $notifiable->branch?->slug;
                if ($shopSlug !== null && $shopSlug !== '') {
                    $parameters['shop'] = $shopSlug;
                }
            }

            return URL::temporarySignedRoute(
                $routeName,
                Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60)),
                $parameters
            );
        });
    }

    /**
     * Named rate limiters for sensitive endpoints (issue #171).
     *
     * Per-org keys (instead of per-user) prevent a compromised single admin
     * token from amplifying into queue exhaustion that would starve every
     * other tenant. Falls back to user id, then ip, when no org context.
     */
    private function configureRateLimiters(): void
    {
        $orgScopedKey = function (Request $request): string {
            $orgId = (string) ($request->user()?->console_organization_id ?? '');
            if ($orgId !== '') {
                return 'org:'.$orgId;
            }
            $userId = $request->user()?->id;
            if ($userId !== null) {
                return 'user:'.$userId;
            }

            return 'ip:'.$request->ip();
        };

        // Broadcast composer — fans out to N×M deliveries per call, by far
        // the heaviest write path in the notification subsystem.
        RateLimiter::for(
            'notifications-broadcast',
            fn (Request $request) => Limit::perMinute(30)->by($orgScopedKey($request)),
        );

        // CRUD on templates / audiences / channel-routes. Per-org cap so one
        // tenant cannot DOS the admin-write queue for everyone else.
        RateLimiter::for(
            'notifications-admin-mutations',
            fn (Request $request) => Limit::perMinute(60)->by($orgScopedKey($request)),
        );

        // Render / preview / lookup endpoints — read-heavy but cheap.
        RateLimiter::for(
            'notifications-admin-reads',
            fn (Request $request) => Limit::perMinute(120)->by($orgScopedKey($request)),
        );

        // Kiosk audit-log writer (POST /api/v1/kiosk/audit-logs). The
        // device.auth middleware stuffs the device in $request->attributes
        // but never calls Auth::shouldUse(), so Laravel's default
        // `throttle:60,1` falls back to client IP — multiple kiosks
        // behind a single branch NAT would share the budget. Key by
        // device id explicitly so each kiosk has its own bucket.
        RateLimiter::for(
            'kiosk-audit',
            fn (Request $request) => Limit::perMinute(60)
                ->by($request->attributes->get('device')?->id ?? $request->ip()),
        );

        // Kiosk payment sync (POST /api/v1/kiosk/payments). Same device.auth
        // caveat as the audit writer: a numeric `throttle:10,1` on the route
        // fell back to client IP AND was far too tight — a kiosk flushing an
        // offline batch of >10 queued payments on reconnect 429'd from the 11th
        // on. Key by device id and lift the ceiling to 60/min so a burst
        // drains without being rate-limited.
        RateLimiter::for(
            'kiosk-payments',
            fn (Request $request) => Limit::perMinute(60)
                ->by($request->attributes->get('device')?->id ?? $request->ip()),
        );

        // Kiosk config reads (GET /api/v1/kiosk/printers, + future kiosk read
        // feeds). Same device.auth caveat as kiosk-audit above: a numeric
        // `throttle:N,1` falls back to client IP, so several kiosks behind one
        // branch NAT would share the budget and 429 each other on a
        // coordinated startup config fetch. Key by device id so each kiosk
        // gets its own bucket.
        RateLimiter::for(
            'kiosk-reads',
            fn (Request $request) => Limit::perMinute(60)
                ->by($request->attributes->get('device')?->id ?? $request->ip()),
        );

        // Public unified QR resolve (GET /api/v1/customer/qr/{token}). Anonymous
        // endpoint keyed by IP — tight enough to blunt brute-force enumeration of
        // opaque 32-char tokens while leaving real customer scans comfortable
        // headroom.
        RateLimiter::for(
            'qr-resolve',
            fn (Request $request) => Limit::perMinute(30)->by($request->ip()),
        );

        // Public order read (GET /api/v1/customer/orders/{id}). Anonymous, and
        // until now completely unthrottled: the route carries no ->middleware()
        // and `throttleApi()` is never called anywhere, so the `api` group is
        // SubstituteBindings only.
        //
        // customer-web polls this while the guest waits on the payment screen
        // (5s for the first 5 min, then 15s, 30s once settled — see
        // customer-web `lib/order-settlement.ts`), so the ceiling should be a
        // deliberate number rather than an accidental absence of one.
        //
        // Keyed by ORDER ID, not IP. Every phone on a shop's wifi shares one
        // egress IP, and customer-web runs with auth off so every request is
        // anonymous — an IP key would drop all 40 tables into one bucket, the
        // exact failure already documented on `kiosk-audit` and `workstation`
        // above. Per-order also matches the real unit of load: a split bill has
        // a handful of phones on one order, never hundreds.
        //
        // 120/min ≈ 10 devices polling the same order at 5s. Deliberately NOT
        // also keyed by IP: the only thing a second key would buy is
        // enumeration defence, and order ids are UUID v7 — 74 random bits,
        // unreachable at any rate — while it would reintroduce the NAT trap for
        // no gain.
        // The parameter is `{id}` on most routes and `{orderId}` on the review
        // ones; both are read, then the IP as a last resort. A key that resolved
        // to the empty string would put every order in the fleet into one
        // bucket, and the symptom — sporadic 429s for unrelated guests — reads
        // like anything except a mis-keyed limiter.
        //
        // The ROUTE NAME is part of the key too, so each endpoint gets its own
        // per-order budget. Sharing one budget across endpoints means the
        // busiest starves the rest: the payment screen polls `orders/{id}` every
        // 5s and calls `split-status` on mount, so a shared bucket would 429 the
        // mount using up a poll allowance it never spent. That is pinned by
        // CustomerOrderReadThrottleTest, and adding routes to this limiter is
        // what would otherwise have broken it.
        RateLimiter::for(
            'customer-order-read',
            fn (Request $request) => Limit::perMinute(120)
                ->by($this->customerOrderLimiterKey($request, 'customer-order')),
        );

        // The write half of the same guest surface (#1256): apply-coupon,
        // the three payment-intent routes, confirm-payment, settle-zero,
        // commit, cancel, split-mode. The reasoning above was applied only to
        // the reads, so eight state-mutating routes on the same opaque-order-id
        // token had no ceiling at all.
        //
        // Not framed as enumeration defence — the read limiter's own comment
        // settles that: order ids are UUID v7 and unreachable by guessing. What
        // this bounds is LOAD, and a write costs more than a read (a Stripe
        // round trip, a coupon re-price, a status transition).
        //
        // Same per-order key, and for the same reason. `split-mode` moves onto
        // this limiter from a bare `throttle:30,1`: that was keyed by IP, which
        // put every phone in a shop into ONE bucket — the NAT trap documented
        // directly above it. A busy shop hit 429 on a normal split-bill screen.
        //
        // 30/min per order, a quarter of the read budget: a split bill is a
        // handful of phones each committing their own share, plus retries. A
        // guest cannot reach 30 writes a minute on one order by hand.
        //
        // Falls back to the IP when the route carries no {id}: no route here
        // does, but a future one keyed on a null id would silently collapse
        // every shop on the planet into a single shared bucket, and that failure
        // would look like random 429s rather than a mis-keyed limiter.
        RateLimiter::for(
            'customer-order-write',
            fn (Request $request) => Limit::perMinute(30)
                ->by($this->customerOrderLimiterKey($request, 'customer-order-write')),
        );

        // POST /customer/orders/batch takes the ids in the BODY, so there is no
        // per-order key to use — it is the one route here that can probe many
        // ids in a single request. IP is the only key available; the NAT
        // objection is weaker for it because a guest device fetches its own
        // order history occasionally rather than polling.
        RateLimiter::for(
            'customer-order-batch',
            fn (Request $request) => Limit::perMinute(60)->by($request->ip()),
        );

        // plan-054 — minting a PayPay QR. Keyed per order id for the same reason
        // as `customer-order-read` above: an IP limit would punish a busy shop
        // behind one NAT address. Much tighter than the read limiter because each
        // call is a write that costs a PayPay round trip AND invalidates the code
        // the customer may already be looking at. 10/min still covers a guest who
        // keeps refreshing, while a loop cannot churn the merchant account.
        RateLimiter::for(
            'customer-paypay-qr',
            fn (Request $request) => Limit::perMinute(10)
                ->by('customer-paypay-qr:'.$request->route('id')),
        );

        // Device pairing (POST /api/v1/devices/pair). Anonymous endpoint keyed
        // by IP — 5/min is tight enough to blunt brute-force enumeration of
        // 6-char codes (75 guesses per 15-min window vs 2.17B keyspace) while
        // leaving legitimate pairing attempts comfortable headroom (a branch
        // typically pairs 1–5 devices during setup, never in a burst).
        RateLimiter::for(
            'devices-pair',
            fn (Request $request) => Limit::perMinute(5)->by($request->ip()),
        );

        // Star CloudPRNT (plan-053 T5.4). TWO limits, because the two threats
        // point opposite ways.
        //
        // Per TOKEN: a machine polls on its own schedule (Star's default is a
        // few seconds) and each job costs three requests — poll, GET, DELETE.
        // 180/min is one poll per second with headroom, and keying by token
        // rather than IP is essential: four printers in one shop share one NAT
        // address, so an IP bucket would make the busiest machine 429 the
        // others and receipts would stop printing at the till that sells most.
        //
        // Per IP: the token bucket alone gives a guesser a fresh budget for
        // every guess, so it cannot bound enumeration by itself. This does. It
        // is generous against a legitimate shop (4 machines × 12 polls/min × 3
        // requests = 144) and irrelevant to a 32-byte keyspace, which is the
        // honest accounting: the token's length is the defence, this is only a
        // ceiling on noise.
        RateLimiter::for('cloudprnt', fn (Request $request) => [
            Limit::perMinute(180)->by('cloudprnt-token:'.(string) $request->route('printerToken')),
            Limit::perMinute(600)->by('cloudprnt-ip:'.$request->ip()),
        ]);

        // Workstation sync (all /api/v1/workstation/* routes). Same problem as
        // kiosk-audit above: device.auth stuffs the device in $request->attributes
        // but never calls Auth::shouldUse(), so a numeric `throttle:300,1` falls
        // back to client IP — every workstation, pos-web cloud-fallback, and kiosk
        // behind one branch NAT would drain a single bucket and 429 the pull/push
        // loop. Key by device id so each workstation has its own 300/min budget
        // (see routes/api/workstation.php for the per-loop call-rate breakdown).
        RateLimiter::for(
            'workstation',
            fn (Request $request) => Limit::perMinute(300)
                ->by($request->attributes->get('device')?->id ?? $request->ip()),
        );

        // POS compound-auth namespace (pos-web direct-to-cloud). Same
        // device-keyed rationale as `workstation` above — a pos-web falling
        // back from a downed workstation must not share its budget with the
        // TMS tablet + kiosks on the same branch NAT. AuthenticateSsoOrDevice
        // is prepended above ThrottleRequests, so $request->attributes->get
        // ('device') is populated whenever the caller is a POS device;
        // authenticated SSO users key by user id via $request->user()->id;
        // anonymous requests never reach here (middleware 401s first).
        RateLimiter::for(
            'pos',
            fn (Request $request) => Limit::perMinute(120)
                ->by($request->attributes->get('device')?->id ?? $request->user()?->id ?? $request->ip()),
        );

        // Tighter per-device overrides for the sensitive payment sub-routes.
        // Same device-keyed rationale as `workstation` above — never IP, so
        // co-located workstations don't share a payment budget.
        RateLimiter::for(
            'workstation-payments',
            fn (Request $request) => Limit::perMinute(30)
                ->by($request->attributes->get('device')?->id ?? $request->ip()),
        );
        RateLimiter::for(
            'workstation-payment-status',
            fn (Request $request) => Limit::perMinute(60)
                ->by($request->attributes->get('device')?->id ?? $request->ip()),
        );
    }

    /**
     * Rate-limiter key for the public guest-order surface: one bucket per
     * (endpoint, order).
     *
     * The order parameter is `{id}` on most routes and `{orderId}` on the review
     * ones — reading only one name would resolve the other to an empty string
     * and share a single bucket across every order in the fleet. The IP is the
     * last resort, and only reachable from a future route with no order
     * parameter at all.
     *
     * The route name keeps endpoints off each other's budget; see the
     * `customer-order-read` registration for why that matters.
     */
    private function customerOrderLimiterKey(Request $request, string $prefix): string
    {
        $order = $request->route('id') ?? $request->route('orderId') ?? $request->ip();

        return $prefix.':'.($request->route()?->getName() ?? $request->path()).':'.$order;
    }
}
