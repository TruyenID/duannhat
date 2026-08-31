<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Services\Loyalty\CustomerPointService;
use App\Services\Loyalty\MembershipTierBackgroundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * #1441 — đặc quyền thành viên.
 *
 * Hạng được SUY RA từ tổng điểm đã tích, không lưu cột nào: không có bảng
 * hạng, không có job xét hạng ban đêm, và do đó không có khoảng thời gian nào
 * mà hạng hiển thị lệch với điểm thực tế. Mốc hạng + quyền lợi nằm ở
 * `config/loyalty.php`.
 *
 * `benefits` trả về KHOÁ i18n, không phải câu chữ — customer-web dịch qua
 * `membership.benefits.<key>`, nên câu chữ nằm cùng chỗ với mọi câu chữ khác
 * của app thay vì rải một nửa xuống config PHP.
 */
class CustomerMembershipController extends Controller
{
    public function __construct(
        private CustomerPointService $points,
        private MembershipTierBackgroundService $tierBackgrounds,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $customer = $request->user('customer');
        $tier = $this->points->tier($customer);

        // #1772 — ảnh nền thẻ do brand cấu hình, mỗi hạng một hình. Giải URL
        // MỘT lần rồi gắn vào từng hạng, thay vì trả map riêng bắt FE tự join:
        // client nào quên join thì thẻ im lặng rơi về nền mặc định, và lỗi đó
        // không nhìn ra được từ payload.
        //
        // Khách chưa gắn brand (`brand` nullable) ⇒ map rỗng ⇒ mọi hạng null.
        $urls = $this->tierBackgrounds->urls($customer?->brand);

        return response()->json([
            'data' => [
                'lifetime_points' => $tier['lifetime_points'],
                'balance' => $this->points->balance($customer),
                'current_tier' => $this->tierBackgrounds->decorate($tier['current'], $urls),
                'next_tier' => $this->tierBackgrounds->decorate($tier['next'], $urls),
                'points_to_next_tier' => $tier['points_to_next'],
                // Cả thang hạng, để FE vẽ được tiến trình chứ không chỉ hạng
                // hiện tại — khách muốn thấy mình đang ở đâu trên đường đi.
                'tiers' => array_map(
                    fn (array $entry) => $this->tierBackgrounds->decorate($entry, $urls),
                    array_values(config('loyalty.tiers', []))
                ),
            ],
        ]);
    }
}
