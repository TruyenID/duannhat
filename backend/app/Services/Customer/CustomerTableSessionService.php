<?php

namespace App\Services\Customer;

use App\Exceptions\TableNotFoundException;
use App\Exceptions\TableStateConflictException;
use App\Models\CustomerOrder;
use App\Models\TableSession;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Services\Shop\Contracts\TableOccupancy;
use App\Services\Shop\Contracts\TableQrSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Plan-047 thin-controller/fat-service — the anonymous customer-web table
 * state machine (occupy / join / release) extracted from CustomerTableController.
 * Each transition locks the `tables` row inside a transaction so concurrent QR
 * scans serialise; the controller keeps only response shaping.
 *
 * All three read the RAW `tables.status` column to dodge the TableStatusEnum
 * cast — a stuck `paid` value has no enum case and would throw ValueError
 * before we branch on it (plan-034 edge case 6.1).
 *
 * #962 — `tables` thuộc Organization, lớp này thuộc Ordering. Mọi lần
 * chạm bàn giờ đi qua {@see TableOccupancy}; lớp này không còn nhắc tới
 * `App\Models\Table`. Cái ĐỔI khi làm việc đó: bàn không còn là một model sống
 * trong tay, mà là một ảnh chụp bất biến ({@see TableQrSnapshot}) — nên sau mỗi
 * lần ghi, payload trả về được dựng từ trạng thái ĐÍCH đã biết, chứ không phải
 * từ một model vừa được cập nhật tại chỗ.
 */
class CustomerTableSessionService
{
    public function __construct(
        private readonly TableOccupancy $tables,
    ) {}

    /**
     * free → occupied. Idempotent when already occupied; 409 for any other
     * source status. Returns the shaped table payload.
     *
     * @return array<string, mixed>
     */
    public function occupy(string $qrToken): array
    {
        return DB::transaction(function () use ($qrToken) {
            $table = $this->lockTable($qrToken);
            $current = $table->rawStatus;

            if ($current === 'free') {
                $this->tables->markOccupied($table->id);
                $current = 'occupied';
            } elseif ($current !== 'occupied') {
                throw new TableStateConflictException('Table is not available.', $current, 409);
            }

            return ['table' => $this->tablePayload($table, $current)];
        });
    }

    /**
     * plan-034 multi-device join. free → occupied + open a TableSession;
     * occupied → return the open session (+ any pinned open order); paid →
     * paid_recent (or, with $forceNew, flip paid→occupied and open a new
     * session); cleaning/reserved/out_of_service → 423 Locked. Idempotent — a
     * device already holding the open session gets the same session back.
     *
     * @return array<string, mixed>
     */
    public function join(string $qrToken, bool $forceNew): array
    {
        return DB::transaction(function () use ($qrToken, $forceNew) {
            $table = $this->lockTable($qrToken);
            $current = $table->rawStatus;

            // Blockers: cleaning / reserved / out_of_service.
            if (in_array($current, ['cleaning', 'reserved', 'out_of_service'], true)) {
                throw new TableStateConflictException('Table is not available.', $current, 423);
            }

            // Paid table → stop here (FE shows PaidView + "Đặt thêm món") unless
            // the user confirmed force-open a new session on the same table.
            if ($current === 'paid' && ! $forceNew) {
                // "Paid" on a CustomerOrder maps to Closed (plan-024 closing flow).
                $paidOrder = CustomerOrder::where('branch_id', $table->branchId)
                    ->whereHas('tables', fn ($q) => $q->where('id', $table->id))
                    ->where('status', CustomerOrderStatusEnum::Closed)
                    ->latest('checkout_at')
                    ->first();

                return [
                    'status' => 'paid_recent',
                    'table' => $this->tablePayload($table, $current),
                    'paid_order' => $paidOrder ? [
                        'id' => $paidOrder->id,
                        'code' => $paidOrder->order_code,
                        'paid_at' => $paidOrder->checkout_at?->toIso8601String(),
                    ] : null,
                    'can_start_new_session' => true,
                ];
            }

            // free OR (paid AND force_new) → flip to occupied. Raw write so a
            // stale `paid` value doesn't trigger ValueError on rehydrate.
            if ($current === 'free' || ($current === 'paid' && $forceNew)) {
                $this->tables->markOccupied($table->id);
                $current = 'occupied';
            }

            // occupied → lookup or create the open session for this table.
            $session = TableSession::where('table_id', $table->id)
                ->where('status', TableSession::STATUS_OPEN)
                ->latest('opened_at')
                ->first();

            if (! $session) {
                $session = TableSession::create([
                    'id' => (string) Str::uuid(),
                    'organization_id' => $table->organizationId,
                    'branch_id' => $table->branchId,
                    'table_id' => $table->id,
                    'status' => TableSession::STATUS_OPEN,
                    'opened_at' => now(),
                ]);
            }

            // Any open order pinned to this session — what device A created and
            // device B joins into.
            $order = CustomerOrder::where('table_session_id', $session->id)
                ->active()
                ->first();

            return [
                'status' => 'joined',
                'table' => $this->tablePayload($table, $current),
                'session' => [
                    'id' => $session->id,
                    'opened_at' => $session->opened_at?->toIso8601String(),
                ],
                'order' => $order ? [
                    'id' => $order->id,
                    'code' => $order->order_code,
                    'status' => $order->status instanceof \BackedEnum
                        ? $order->status->value
                        : $order->status,
                    'items_count' => $order->items()->count(),
                ] : null,
            ];
        });
    }

    /**
     * occupied → free ("Đổi bàn"). Idempotent when already free; 409 for any
     * other source status. Returns the shaped table payload.
     *
     * @return array<string, mixed>
     */
    public function release(string $qrToken): array
    {
        return DB::transaction(function () use ($qrToken) {
            $table = $this->lockTable($qrToken);
            $current = $table->rawStatus;

            if ($current === 'occupied') {
                $this->tables->markFree($table->id);
                $current = 'free';
            } elseif ($current !== 'free') {
                throw new TableStateConflictException('Table cannot be released.', $current, 409);
            }

            return ['table' => $this->tablePayload($table, $current)];
        });
    }

    private function lockTable(string $qrToken): TableQrSnapshot
    {
        $table = $this->tables->lockActiveByQrToken($qrToken);

        if (! $table) {
            throw new TableNotFoundException;
        }

        return $table;
    }

    /**
     * Payload bàn trả về customer-web. `$status` là trạng thái ĐÍCH sau khi
     * chuyển (bằng `rawStatus` khi không có chuyển đổi nào), nên nó vừa đúng cho
     * hai đường idempotent vừa đúng cho đường `paid` kẹt: giá trị thô đi thẳng
     * ra ngoài, không qua enum cast.
     *
     * @return array<string, mixed>
     */
    private function tablePayload(TableQrSnapshot $table, string $status): array
    {
        return [
            'id' => $table->id,
            'number' => $table->displayNumber(),
            'status' => $status,
            'qr_token' => $table->qrToken,
        ];
    }
}
