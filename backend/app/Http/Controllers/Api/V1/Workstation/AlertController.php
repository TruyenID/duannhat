<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Workstation;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Modules\Notifications\Contracts\NotificationDispatcher;
use App\Modules\Notifications\Contracts\NotificationRequest;
use App\Support\BusinessClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

/**
 * #1806 S3 — alert của máy trạm đi lên HQ.
 *
 * ## Không có bảng mới ở Cloud, và đó là quyết định
 *
 * Alert đã có nhà: nền tảng thông báo. Dựng một bảng `workstation_alerts`
 * riêng sẽ tạo ra **bề mặt cảnh báo thứ hai** ở HQ — và khi người vận hành có
 * hai chỗ phải nhìn, chỗ ít dùng hơn sẽ thành chỗ không ai nhìn.
 *
 * Đi qua nền tảng còn thừa hưởng bốn thứ đã giải xong: khử trùng theo
 * `idempotency_key`, gộp chuông theo `aggregationKey`, trạng thái đã-đọc, và
 * hộp thư của HQ. Tự dựng lại bốn thứ đó cho một bảng riêng là công việc thật,
 * không phải chi phí tưởng tượng.
 *
 * Đánh đổi phải biết: Cloud nhận **ảnh chụp lúc đẩy**, không phải toàn bộ lịch
 * sử `first_seen`/`count` nằm ở máy trạm. Chấp nhận được — HQ cần biết *quán
 * này đang có chuyện*, còn diễn biến chi tiết thì xem trên chính máy đó.
 *
 * ## Máy trạm quyết định cái gì được lên, không phải Cloud
 *
 * Allowlist sync-UP nằm ở phía Go (`alertRegistry`). Ở đây chỉ kiểm phạm vi và
 * hình dạng. Cloud lọc lại theo một danh sách thứ hai sẽ tạo ra hai nguồn chân
 * lý cho cùng một câu hỏi, và chúng sẽ lệch nhau.
 *
 * ## Fail-open, luôn luôn
 *
 * Endpoint này **không bao giờ** được làm hỏng vòng đồng bộ của máy trạm. Một
 * cảnh báo không đẩy lên được vẫn còn nguyên trong SQLite của quán và vẫn hiện
 * trên panel tại chỗ; chặn sync vì nó là đánh đổi sai chiều.
 */
final class AlertController extends Controller
{
    #[OA\Post(
        path: '/api/v1/workstation/alerts',
        summary: 'Máy trạm đẩy alert đang mở lên HQ (#1806 S3)',
        tags: ['Workstation'],
        responses: [new OA\Response(response: 202, description: 'Đã nhận')],
    )]
    public function store(Request $request, NotificationDispatcher $dispatcher): JsonResponse
    {
        $device = $request->attributes->get('device');
        $branchId = $device?->branch_id;

        if ($branchId === null) {
            // Thiết bị chưa gắn chi nhánh thì không quy được alert về đâu.
            // 422 chứ không 500: đây là trạng thái ghép cặp, không phải lỗi.
            return response()->json(['message' => 'Thiết bị chưa gắn chi nhánh.'], 422);
        }

        $data = $request->validate([
            'alerts' => ['required', 'array', 'max:50'],
            'alerts.*.kind' => ['required', 'string', 'max:64'],
            'alerts.*.subject' => ['required', 'string', 'max:255'],
            'alerts.*.severity' => ['required', 'string', 'in:critical,warning,info'],
            'alerts.*.title' => ['required', 'string', 'max:255'],
            'alerts.*.count' => ['nullable', 'integer', 'min:1'],
            'alerts.*.first_seen_at' => ['nullable', 'string', 'max:64'],
        ]);

        $branch = Branch::query()->whereKey($branchId)->first();

        if ($branch === null) {
            return response()->json(['message' => 'Chi nhánh không tồn tại.'], 422);
        }

        $brand = Brand::query()->where('console_brand_id', $branch->console_brand_id)->first();

        // #2847 — `branches` KHÔNG có cột `organization_id`; chỉ có
        // `console_organization_id`. Bản trước viết `(string) $branch->organization_id`,
        // ra chuỗi RỖNG, và chuỗi rỗng đi thẳng vào `scopeId` của audience —
        // `where('role_user_pivots.organization_id', '')` khớp 0 hàng, nên MỌI
        // alert máy trạm ném "requires at least one recipient" rồi bị `catch`
        // fail-open ở dưới nuốt mất. Đo trên production: 7.523 lần trong hai
        // ngày, tỷ lệ hỏng 100% kể từ dòng đầu tiên.
        //
        // Org local phải suy ra qua mirror console, cùng đường mà
        // `RoleResolver::brandRole()` và các policy đang dùng.
        $organizationId = Organization::query()
            ->where('console_organization_id', $branch->console_organization_id)
            ->value('id');

        if ($organizationId === null) {
            // Cùng lý lẽ với hai guard phía trên: chưa nhân bản tổ chức về
            // Tempo là trạng thái ghép cặp, không phải lỗi máy chủ. Và dispatch
            // với org rỗng chính là lỗi vừa sửa — thà 422 còn hơn im lặng.
            return response()->json(['message' => 'Tổ chức của chi nhánh chưa được nhân bản về Tempo.'], 422);
        }

        $organizationId = (string) $organizationId;

        // #1091/#1921 — MỘT lần cho cả lô, và theo timezone của CHI NHÁNH.
        //
        // Tính một lần chứ không mỗi vòng lặp là có chủ đích: một lô alert phải
        // rơi vào cùng một ngày nghiệp vụ, kể cả khi nó được xử lý ngay lúc
        // nửa đêm ở quán. Tính trong vòng lặp thì hai alert của cùng một lượt
        // đẩy có thể nhận hai khoá khác nhau, và HQ nhận đúp.
        $businessDate = BusinessClock::businessDate($branchId);

        $accepted = 0;

        foreach ($data['alerts'] as $alert) {
            if ($brand === null) {
                break;
            }

            try {
                $dispatcher->toRole(
                    new NotificationRequest(
                        type: 'workstation.alert',
                        params: [
                            'branch_name' => $branch->name,
                            'kind' => $alert['kind'],
                            'subject' => $alert['subject'],
                            'severity' => $alert['severity'],
                            'title' => $alert['title'],
                            'count' => $alert['count'] ?? 1,
                            'first_seen_at' => $alert['first_seen_at'] ?? null,
                        ],
                        organizationId: $organizationId,
                        subject: $branch,
                        // Ngày nghiệp vụ trong khoá: một sự cố kéo dài một tuần
                        // kêu 7 lần, không phải mỗi lượt đồng bộ một lần. Máy
                        // trạm đẩy theo tick, nên thiếu vế này thì HQ nhận vài
                        // trăm thông báo giống hệt nhau mỗi ngày.
                        //
                        // #1921 — ngày này phải là ngày nghiệp vụ CỦA CHI NHÁNH,
                        // không phải đồng hồ app. Trước đây là `now()->toDateString()`:
                        // với quán JP (UTC+9) thì chín tiếng đầu mỗi ngày rơi vào
                        // ngày UTC HÔM TRƯỚC, nên khoá đổi giữa ca đêm và cùng một
                        // sự cố kêu HAI lần — rồi im suốt phần còn lại của ngày
                        // hôm sau vì khoá đã bị tiêu.
                        // #2890 — phần BIẾN THIÊN được băm, để khoá có ĐỘ DÀI CỐ ĐỊNH.
                        //
                        // `notifications.idempotency_key` là `varchar(120)`. Khoá
                        // dạng cũ ghép thẳng branch uuid (36) + kind + subject +
                        // ngày, và với `cloud_money_overwrite` — subject là một
                        // uuid nữa — nó ra **124 ký tự**: thừa đúng 4. Production
                        // ném `SQLSTATE[22001] Data too long` mỗi phút, và cái
                        // hỏng đó nấp NGUYÊN VẸN phía sau bug org id (#2847):
                        // trước khi #2847 được vá, audience rỗng đã ném từ sớm
                        // hơn nên INSERT chưa bao giờ chạy tới. Vá lỗi thứ nhất
                        // mới lộ lỗi thứ hai.
                        //
                        // `subject` do MÁY TRẠM đặt và hợp đồng cho tới 255 ký
                        // tự, nên không có độ dài nào là an toàn nếu cứ ghép
                        // thẳng — hôm nay thừa 4, mai một kind mới thừa 100. Băm
                        // là cách duy nhất chặn được cả họ lỗi này thay vì đẩy
                        // trần lên một con số khác.
                        //
                        // Giữ tiền tố đọc được: một khoá toàn hash thì không ai
                        // grep nổi lúc điều tra. Tiền tố + ngày vẫn nguyên văn,
                        // chỉ `(branch, kind, subject)` bị băm — độ dài tổng
                        // luôn là 18 + 40 + 1 + 10 = 69.
                        idempotencyKey: sprintf(
                            'workstation.alert:%s:%s',
                            sha1($branchId.':'.$alert['kind'].':'.$alert['subject']),
                            $businessDate,
                        ),
                        priority: $alert['severity'] === 'critical' ? 'urgent' : 'high',
                        // Gộp mọi alert của MỘT quán trong ngày thành một dòng
                        // chuông: HQ cần biết "quán X có chuyện", không cần bốn
                        // tiếng chuông cho bốn máy in.
                        aggregationKey: sprintf('workstation.alert:%s:%s', $branchId, $businessDate),
                    ),
                    role: (string) config('notifications.workstation_alert_role', 'org-admin'),
                    scopeKey: 'organization_id',
                    scopeId: $organizationId,
                    brand: $brand,
                );

                $accepted++;
            } catch (\Throwable $e) {
                // Audience rỗng (chưa ai giữ role) rơi vào đây và KHÔNG được
                // chặn gì — xem docblock về fail-open.
                // #2848 — `subject` PHẢI có trong dòng này.
                //
                // Với `cloud_money_overwrite`, subject CHÍNH LÀ order id, và nó
                // là thứ duy nhất nối cảnh báo về một đơn cụ thể. Bản trước chỉ
                // ghi `kind`, nên 7.523 dòng log trong hai ngày nói được đúng
                // "có chuyện ở quán nào" chứ không nói được ĐƠN NÀO — mà chi
                // tiết lệch thì nằm trong SQLite tại quán, Cloud không có bảng
                // `order_money_overwrites`. Truy ra được ba đơn nghi vấn đòi
                // phải lần ngược `audit_logs` bằng tay; một trường ở đây là đủ
                // thay cho toàn bộ việc đó.
                //
                // `first_seen_at` đi kèm vì nó phân biệt "sự cố mới" với "sự cố
                // đã mở từ lâu mà mãi bây giờ mới đẩy được lên" — đúng ca xảy ra
                // khi máy trạm nâng lên v0.6.0 và đẩy nguyên tồn kho alert cũ.
                Log::warning('workstation-alert.dispatch-failed', [
                    'branch_id' => $branchId,
                    'kind' => $alert['kind'],
                    'subject' => $alert['subject'],
                    'first_seen_at' => $alert['first_seen_at'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 202 chứ không 200: Cloud đã NHẬN, việc tới được người nhận hay không
        // phụ thuộc kênh và audience. Nói "đã xử lý xong" là hứa quá tay.
        return response()->json(['accepted' => $accepted], 202);
    }
}
