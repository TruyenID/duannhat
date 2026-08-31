<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Contracts;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Model;

/**
 * API công bố của module Notifications (#1568, epic #962).
 *
 * Đây là cổng hẹp nhất repo, và nó hẹp vì được **đo** chứ không vì được đoán:
 * bảy caller ngoài module dùng đúng **một** method (`NotificationService::
 * dispatch`), và sáu trong bảy chỉ dùng đúng một hình dạng audience —
 * `byRole(...)->scopedTo(...)`.
 *
 * ## Vì sao `Audience` KHÔNG được công bố
 *
 * `Audience` có 7 factory tĩnh + 5 modifier fluent, nhưng ngoài module chỉ dùng
 * `byRole()->scopedTo()`. Công bố cả builder là công bố 12 đường cho một nhu cầu
 * duy nhất, và mỗi đường thừa là một lời mời caller sau với tay vào chỗ chủ sở
 * hữu không định mở.
 *
 * Quan trọng hơn: `Audience::scopedTo(Model $scope)` phải dò `instanceof` để suy
 * ra khoá scope, nên **chính nó** phải import `Warehouse` (Inventory) và `Device`
 * (PlatformIntegration) — hai cạnh RA của Notifications sinh ra chỉ vì cổng nhận
 * Model thay vì nhận khoá. Cho caller tự nêu `scopeKey` thì cả hai biến mất.
 *
 * ## Vì sao trả `string` chứ không phải `Notification`
 *
 * Chỉ một trong bảy caller đọc giá trị trả về (`RecallService`), và nó chỉ lấy
 * `->id`. Trả model là công bố toàn bộ bảng cho một nhu cầu là một chuỗi.
 *
 * `Brand` xuất hiện trong chữ ký vì nó thuộc `TenancyKernel` — mọi module đều
 * được phép phụ thuộc. `Warehouse` thì **không** (Inventory), nên scope đi vào
 * dưới dạng khoá + id chứ không phải Model.
 */
interface NotificationDispatcher
{
    /**
     * Gửi tới một VAI trong một phạm vi — sáu trong bảy caller dùng đường này.
     *
     * @param  string|list<string>  $role  ví dụ `shop-manager`, `warehouse_manager`.
     *                                     Nhận danh sách khi một sự kiện thuộc về NHIỀU vai — chúng OR với nhau
     *                                     trong CÙNG phạm vi (#2450).
     * @param  string  $scopeKey  `branch_id` · `warehouse_id` · `brand_id` · `organization_id` · `device_id`
     * @param  string  $scopeId  khoá chính của bản ghi phạm vi
     * @return string id thông báo vừa tạo
     */
    public function toRole(
        NotificationRequest $request,
        string|array $role,
        string $scopeKey,
        string $scopeId,
        Brand $brand,
    ): string;

    /**
     * Gửi tới một danh sách người nhận đã biết trước.
     *
     * @param  iterable<Model>  $recipients  mỗi phần tử phải là `App\Contracts\Notifiable`
     * @return string id thông báo vừa tạo
     */
    public function toRecipients(NotificationRequest $request, iterable $recipients): string;

    /**
     * Máy luật đã có một luật SỐNG thay thế cho emitter cứng này chưa?
     *
     * #962 — thêm method thứ ba vào cổng "hẹp nhất repo" không phải là nới nó ra:
     * ba emitter Phase A đều hỏi đúng câu này ngay TRƯỚC khi gọi `toRecipients`,
     * và cho tới nay chúng hỏi bằng `NotificationRule::hasActiveCoverage(...)` —
     * tức với tay thẳng vào model của Notifications. Một trong ba nằm ở
     * `RecipeService` (Catalog), nên đó là một cạnh Catalog → Notifications sinh
     * ra bởi một câu hỏi mà module này vốn phải trả lời.
     *
     * "Có luật che phủ" nghĩa hẹp và cố ý hẹp: luật đang `is_active` VÀ mang
     * `action.audience_rule` không rỗng. Luật bóng của M7 (chỉ có `audience_name`)
     * KHÔNG tính — nếu tính, bật `NOTIFICATION_USE_RULES` sẽ tắt lặng một emitter
     * đang chạy được mà chưa có ai thay.
     *
     * Chữ ký toàn chuỗi: `$modelAlias` là bí danh emitter (`Recipe`,
     * `StockAlert`, `CustomerOrder`), KHÔNG phải tên class — cổng công bố không
     * được nêu tên model của module khác.
     */
    public function coversEmitter(string $modelAlias, string $triggerEvent, string $organizationId): bool;
}
