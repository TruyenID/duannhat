<?php

declare(strict_types=1);

namespace App\Services\Menu\Internal;

use App\Models\Menu;
use App\Services\Menu\Contracts\MenuQueryPort;
use App\Services\Menu\Contracts\MenuSnapshot;
use App\Services\Product\MenuService;

/**
 * Phía ĐỌC của ranh giới Menu (#1550).
 *
 * `MenuQueryPort` + `MenuSnapshot` tồn tại như interface **không có hiện thực
 * và không có binding** — `app()->make(MenuQueryPort::class)` ném. Đúng cùng
 * hình dạng mà #1544 đã mô tả cho `OrderQueryPort`: *"một đường ống rỗng qua
 * được cổng suốt bao lâu nó tồn tại"*. Class này dựng theo khuôn
 * `Order\Internal\EloquentOrderQuery`, không sáng chế hình dạng mới.
 *
 * ## Vì sao ĐỌC làm được ngay còn GHI thì không
 *
 * 54 method GHI của `MenuMutationFacade` nhận Command mang **id do người gọi
 * cấp** và **fingerprint đã xác minh** — hai ngữ nghĩa mà `MenuService` hôm nay
 * không có (nó tự sinh id, nhận `array`). Nên chúng không uỷ quyền được; chúng
 * cần một persistence adapter viết lại đường ghi menu, tức việc cỡ plan. Phía
 * đọc không có ngữ nghĩa nào phải phát minh: hai câu hỏi này đã có câu trả lời
 * chạy trong production.
 *
 * ## `findEffectiveForBranch` uỷ quyền, KHÔNG chép lại truy vấn
 *
 * `MenuService::getCurrentMenu()` mang bốn luật mà một bản chép sẽ đánh rơi
 * từng cái một: đồng hồ theo **giờ chi nhánh** (#1091, không phải đồng hồ DB),
 * cửa sổ `valid_from`/`valid_to`, "always-on = không có hàng lịch nào", và
 * `COALESCE` ghi đè `days_of_week` theo chi nhánh. Viết lại nó ở đây là dựng
 * bộ giải thứ hai cho câu "thực đơn nào đang phát" — đúng loại nợ mà #962 xếp
 * nặng nhất (xem `TaxResolver`).
 */
final class EloquentMenuQuery implements MenuQueryPort
{
    public function __construct(private readonly MenuService $menus) {}

    public function findById(string $organizationId, string $menuId): ?MenuSnapshot
    {
        $menu = Menu::query()
            ->where('organization_id', $organizationId)
            ->whereKey($menuId)
            ->first();

        return $menu === null ? null : MenuAggregateSnapshot::fromModel($menu);
    }

    /**
     * Thực đơn đang PHÁT của chi nhánh, ngay lúc này.
     *
     * `null` khi chi nhánh không có thực đơn nào đang phát — trạng thái hợp lệ
     * (ngoài giờ mở cửa, hoặc mọi thực đơn còn ở nháp), không phải lỗi.
     */
    public function findEffectiveForBranch(string $organizationId, string $branchId): ?MenuSnapshot
    {
        $menu = $this->menus->getCurrentMenu($branchId, $organizationId);

        return $menu === null ? null : MenuAggregateSnapshot::fromModel($menu);
    }
}
