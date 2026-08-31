<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Workstation;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\Till\CashDeviceObservationIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

/**
 * T2 (#2879) + T5 (#2882) — máy trạm đẩy 在高 và sự cố của máy 釣銭機 lên Cloud.
 *
 * Cùng hợp đồng với {@see CashDeviceTransactionController}: lô `max:50`,
 * idempotent theo khoá tự nhiên, và **fail-open** — hỏng ở đây không được chặn
 * vòng đồng bộ của quán.
 *
 * ## Vì sao 在高 phải lên Cloud chứ không chỉ so tại quán
 *
 * Máy trạm biết 在高 và biết sổ của chính nó, nên về lý nó tự so được. Nhưng
 * phép so ba chân cần chân thứ ba — **người đếm tay** — mà con số đó sống ở
 * `till_cash_denomination_counts` trên Cloud (pos-web ghi lúc chốt ca). Và HQ
 * là nơi phải nhìn thấy lệch của nhiều quán cạnh nhau.
 */
final class CashDeviceObservationController extends Controller
{
    #[OA\Post(
        path: '/api/v1/workstation/cash-device-inventory',
        summary: 'Máy trạm đẩy 在高 (số tiền trong máy) tại ranh ca (#2879)',
        tags: ['Workstation'],
        responses: [new OA\Response(response: 202, description: 'Đã nhận')],
    )]
    public function inventory(Request $request, CashDeviceObservationIntake $intake): JsonResponse
    {
        $branch = $this->branchOrNull($request);

        if (! $branch instanceof Branch) {
            return $branch;
        }

        // #2622 — `validate()` strip mọi key không có rule.
        //
        // `total_minor` CỐ Ý không có ở đây: Cloud tự cộng từ `denominations`
        // trừ `uncertain_denominations`. Nhận tổng rời ra là mở đường cho một
        // tổng không khớp chi tiết mà vẫn trông hợp lệ.
        $data = $request->validate([
            'snapshots' => ['required', 'array', 'max:50'],
            'snapshots.*.peripheral_device_id' => ['required', 'uuid'],
            'snapshots.*.till_session_id' => ['required', 'uuid'],
            'snapshots.*.count_phase' => ['required', 'string', 'in:opening,closing'],
            'snapshots.*.denominations' => ['required', 'array'],
            'snapshots.*.uncertain_denominations' => ['nullable', 'array'],
            'snapshots.*.bill_reject_count' => ['nullable', 'integer', 'min:0'],
            'snapshots.*.machine_seq_no' => ['nullable', 'integer'],
            'snapshots.*.captured_at' => ['nullable', 'string', 'max:64'],
        ]);

        return $this->guarded(
            fn () => $intake->ingestInventory($branch, $data['snapshots']),
            $branch,
            count($data['snapshots']),
            'cash-device-inventory',
        );
    }

    #[OA\Post(
        path: '/api/v1/workstation/cash-device-errors',
        summary: 'Máy trạm đẩy sự cố máy 釣銭機 có dấu thời gian (#2882)',
        tags: ['Workstation'],
        responses: [new OA\Response(response: 202, description: 'Đã nhận')],
    )]
    public function errors(Request $request, CashDeviceObservationIntake $intake): JsonResponse
    {
        $branch = $this->branchOrNull($request);

        if (! $branch instanceof Branch) {
            return $branch;
        }

        $data = $request->validate([
            'events' => ['required', 'array', 'max:50'],
            'events.*.peripheral_device_id' => ['required', 'uuid'],
            'events.*.error_title' => ['required', 'string', 'max:50'],
            // Phân nhóm do MÁY TRẠM quyết (nó có `errors.go`), Cloud chỉ kiểm
            // hình dạng. Cloud phân nhóm lại sẽ là bảng phân loại thứ hai, và
            // hai bảng sẽ lệch nhau.
            'events.*.error_group' => ['required', 'string', 'in:change_shortage,needs_operator,connectivity,forbidden'],
            'events.*.occurred_at' => ['required', 'string', 'max:64'],
            'events.*.cleared_at' => ['nullable', 'string', 'max:64'],
            'events.*.glory_transaction_id' => ['nullable', 'string', 'max:100'],
            'events.*.till_session_id' => ['nullable', 'uuid'],
        ]);

        return $this->guarded(
            fn () => $intake->ingestErrors($branch, $data['events']),
            $branch,
            count($data['events']),
            'cash-device-errors',
        );
    }

    private function branchOrNull(Request $request): Branch|JsonResponse
    {
        $device = $request->attributes->get('device');
        $branchId = $device?->branch_id;

        if ($branchId === null) {
            return response()->json(['message' => 'Thiết bị chưa gắn chi nhánh.'], 422);
        }

        $branch = Branch::query()->whereKey($branchId)->first();

        if ($branch === null) {
            return response()->json(['message' => 'Chi nhánh không tồn tại.'], 422);
        }

        return $branch;
    }

    /**
     * Fail-open — hàng chưa đẩy được vẫn nằm trong SQLite của quán, và khoá
     * idempotent làm cho lượt gửi lại vô hại.
     *
     * @param  callable(): array<string, int>  $run
     */
    private function guarded(callable $run, Branch $branch, int $count, string $what): JsonResponse
    {
        try {
            return response()->json($run(), 202);
        } catch (\Throwable $e) {
            Log::warning($what.' intake failed', [
                'branch_id' => (string) $branch->id,
                'count' => $count,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Đã nhận, xử lý sau.'], 202);
        }
    }
}
