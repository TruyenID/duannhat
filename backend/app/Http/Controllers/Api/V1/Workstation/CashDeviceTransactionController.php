<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Workstation;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\Till\CashDeviceTransactionIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

/**
 * T1 của #2876 (#2878) — máy trạm đẩy sổ lượt thu tiền 釣銭機 lên Cloud.
 *
 * ## Có bảng mới ở Cloud, và đó là quyết định NGƯỢC với AlertController
 *
 * `AlertController` (#1806 S3) cố ý KHÔNG dựng bảng: alert đã có nhà ở nền
 * tảng thông báo, và bề mặt cảnh báo thứ hai sẽ thành bề mặt không ai nhìn.
 *
 * Ở đây thì ngược lại, vì thứ đẩy lên không phải cảnh báo mà là **chứng từ**.
 * Nó phải tra được theo mã, đối chiếu được theo ca, lưu được nhiều năm — ba
 * việc mà nền tảng thông báo không làm và không nên làm. Một alert nói "máy
 * đang có chuyện"; một hàng ở đây nói "lượt thu này đã xảy ra như thế này".
 *
 * ## Fail-open, giống hệt alert
 *
 * Endpoint này **không bao giờ** được làm hỏng vòng đồng bộ của máy trạm.
 * Hàng chưa đẩy được vẫn nằm trong SQLite của quán và lượt sau gửi lại — khoá
 * idempotent `(máy, mã giao dịch)` làm cho việc gửi lại là vô hại.
 *
 * ## `max:50` khớp phía Go
 *
 * Vượt ngưỡng thì Cloud trả 422 và **cả lô rơi**, kể cả hàng quan trọng nhất.
 * Nên máy trạm tự cắt trước khi gửi (`alertPushBatchSize`), và con số ở hai
 * đầu phải khớp.
 */
final class CashDeviceTransactionController extends Controller
{
    #[OA\Post(
        path: '/api/v1/workstation/cash-device-transactions',
        summary: 'Máy trạm đẩy sổ lượt thu tiền 釣銭機 lên Cloud (#2878)',
        tags: ['Workstation'],
        responses: [new OA\Response(response: 202, description: 'Đã nhận')],
    )]
    public function store(Request $request, CashDeviceTransactionIntake $intake): JsonResponse
    {
        $device = $request->attributes->get('device');
        $branchId = $device?->branch_id;

        if ($branchId === null) {
            // Thiết bị chưa gắn chi nhánh thì không quy được lượt thu về đâu.
            // 422 chứ không 500: trạng thái ghép cặp, không phải lỗi.
            return response()->json(['message' => 'Thiết bị chưa gắn chi nhánh.'], 422);
        }

        // #2622 — `validate()` STRIP mọi key không có rule. Thiếu một dòng ở
        // đây thì cột tương ứng đi hết đường service mà không bao giờ nhận
        // được giá trị, trong khi mọi test gọi thẳng service vẫn xanh. Thêm
        // cột vào schema thì phải thêm rule Ở ĐÂY, và test phải đi qua
        // endpoint chứ không chỉ qua service.
        $data = $request->validate([
            'transactions' => ['required', 'array', 'max:50'],
            'transactions.*.peripheral_device_id' => ['required', 'uuid'],
            'transactions.*.glory_transaction_id' => ['required', 'string', 'max:100'],
            'transactions.*.outcome' => ['required', 'string', 'in:finish,cancel,abort,timeout,failure'],
            'transactions.*.requested_minor' => ['nullable', 'integer'],
            'transactions.*.deposited_minor' => ['nullable', 'integer'],
            'transactions.*.change_minor' => ['nullable', 'integer'],
            'transactions.*.dispensed_minor' => ['nullable', 'integer'],
            'transactions.*.error_title' => ['nullable', 'string', 'max:50'],
            'transactions.*.machine_seq_no' => ['nullable', 'integer'],
            'transactions.*.started_at' => ['nullable', 'string', 'max:64'],
            'transactions.*.finished_at' => ['nullable', 'string', 'max:64'],
            'transactions.*.customer_order_id' => ['nullable', 'uuid'],
            'transactions.*.till_session_id' => ['nullable', 'uuid'],
        ]);

        $branch = Branch::query()->whereKey($branchId)->first();

        if ($branch === null) {
            return response()->json(['message' => 'Chi nhánh không tồn tại.'], 422);
        }

        try {
            $counts = $intake->ingest($branch, $data['transactions']);
        } catch (\Throwable $e) {
            // Fail-open. Máy trạm giữ nguyên hàng chưa xác nhận và gửi lại;
            // khoá idempotent làm cho lượt gửi lại vô hại.
            Log::warning('cash-device-transactions intake failed', [
                'branch_id' => (string) $branch->id,
                'count' => count($data['transactions']),
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Đã nhận, xử lý sau.'], 202);
        }

        return response()->json($counts, 202);
    }
}
