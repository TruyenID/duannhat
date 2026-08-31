<?php

namespace App\Providers;

use App\Services\Menu\Contracts\MenuMutationFacade;
use App\Services\Menu\Contracts\MenuPersistencePort;
use App\Services\Menu\Contracts\MenuQueryPort;
use App\Services\Menu\Internal\EloquentMenuPersistence;
use App\Services\Menu\Internal\EloquentMenuQuery;
use App\Services\Menu\MenuMutationService;
use App\Services\Product\Contracts\ProductMutationFacade;
use App\Services\Product\ProductService;
use App\Services\Promotion\Contracts\MenuPromotionMutationFacade;
use App\Services\Promotion\MenuPromotionService;
use Illuminate\Support\ServiceProvider;

final class ProductServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProductMutationFacade::class, ProductService::class);

        // #1550 — phía ĐỌC của ranh giới Menu. Trước đây `MenuQueryPort` là một
        // interface không ai implement và không ai bind: `app()->make()` ném, và
        // không cổng nào bắt được vì P2 của `CanonicalPortsAreBindableTest` chỉ
        // liệt kê Order + Payment. Cùng hình dạng "đường ống rỗng" mà #1544 đã
        // mô tả cho `OrderQueryPort`.
        //
        // Phía GHI (54 method) đã xây, ngay dưới đây. Câu cũ ở chỗ này nói nó
        // "không uỷ quyền được vì Command mang id-do-người-gọi-cấp" — SAI, và
        // sai theo kiểu tự khoá tay: `EloquentProductPersistence` vốn đã uỷ
        // quyền theo đúng khuôn đó, chỉ cần bọc `Model::unguarded()` để id đi
        // qua `$fillable`. `UNIMPLEMENTED_BY_DESIGN` giờ RỖNG.
        $this->app->bind(MenuQueryPort::class, EloquentMenuQuery::class);

        // #1550 — phía GHI. `MenuMutationService` mỏng (uỷ quyền), mọi quyết
        // định nằm ở `EloquentMenuPersistence`, và persistence uỷ quyền tiếp cho
        // sáu service menu đang chạy production — cùng khuôn
        // `ProductService` + `EloquentProductPersistence`.
        $this->app->bind(MenuPersistencePort::class, EloquentMenuPersistence::class);
        $this->app->bind(MenuMutationFacade::class, MenuMutationService::class);
        // MenuPromotion belongs to the menu aggregate (see the `menu` boundary
        // list in config/domain-mutation-guard.php), so its facade binding lives
        // with the catalog domain rather than in AppServiceProvider —
        // DomainMutationContractsTest forbids canonical contracts there.
        $this->app->bind(MenuPromotionMutationFacade::class, MenuPromotionService::class);
    }
}
