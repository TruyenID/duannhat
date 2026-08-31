<?php

declare(strict_types=1);

namespace App\Services\Inventory\Contracts;

use Illuminate\Support\Collection;

/**
 * #962 — người nhận thông báo theo id khách, do CustomerEngagement hiện thực.
 *
 * `RecallService::notify()` phải trao cho `NotificationDispatcher::toRecipients()`
 * những đối tượng NHẬN ĐƯỢC thông báo, và hôm nay đó là `App\Models\Customer`
 * — model của CustomerEngagement. Đó là cạnh Inventory → CustomerEngagement
 * duy nhất của service này.
 *
 * **Vì sao trả `Collection` chứ không phải danh sách id.** `toRecipients()` đã
 * nhận `iterable` các notifiable (`NotificationDispatcher` là hợp đồng công bố
 * của Notifications, không nêu tên model). Nên cổng này không cần biết KHÁCH
 * là model gì — nó chỉ chuyển tiếp thứ mà Notifications vốn đã nhận ẩn danh.
 * Đổi lại, chữ ký không nêu `App\Models\Customer`, tức "khách hàng là hàng nào
 * trong bảng nào" ở lại đúng chỗ sở hữu nó.
 *
 * Cái giá, nói thẳng: cổng trả về đối tượng mờ, người gọi không đọc trường nào
 * của chúng được (và không nên). Đó là đủ cho ca này — `notify()` chỉ đếm
 * (`isNotEmpty` / `count`) rồi chuyển tiếp — nhưng đừng chép hình dạng này sang
 * một chỗ thực sự cần dữ liệu của khách: chỗ đó cần một ảnh chụp có trường.
 */
interface CustomerNotifiableDirectory
{
    /**
     * Khách có id nằm trong danh sách. Danh sách rỗng ⇒ collection rỗng, không
     * chạm DB.
     *
     * @param  list<string>  $customerIds
     * @return Collection<int, object>
     */
    public function notifiablesForIds(array $customerIds): Collection;
}
