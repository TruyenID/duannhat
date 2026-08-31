<?php

namespace App\Services\Shop;

use App\Models\Table;
use App\Omnify\Enums\TableStatusEnum;
use App\Services\Shop\Contracts\TableOccupancy;
use App\Services\Shop\Contracts\TableOccupancySnapshot;
use App\Services\Shop\Contracts\TableQrSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * #1590 — hiện thực Eloquent của {@see TableOccupancy}. Đây là chỗ DUY NHẤT
 * ngoài Organization được phép chạm `tables.current_order_id` / `tables.status`.
 *
 * Mỗi method dưới đây là một câu truy vấn có thật, chép nguyên từ chỗ nó vừa rời
 * đi — không gộp, không "tối ưu" thêm. Gộp hai truy vấn khác điều kiện WHERE ở
 * bước dời chỗ là cách êm nhất để đổi hành vi mà test vẫn xanh.
 */
final class EloquentTableOccupancy implements TableOccupancy
{
    public function lockAll(array $tableIds): array
    {
        return Table::query()
            ->whereIn('id', $tableIds)
            ->lockForUpdate()
            ->get()
            ->map(fn (Table $t): TableOccupancySnapshot => self::snapshot($t))
            ->all();
    }

    public function lockInBranch(string $branchId, string $tableId): TableOccupancySnapshot
    {
        return self::snapshot(
            Table::query()
                ->where('branch_id', $branchId)
                ->lockForUpdate()
                ->findOrFail($tableId)
        );
    }

    public function lockHeldByOrder(string $tableId, string $orderId): TableOccupancySnapshot
    {
        return self::snapshot(
            Table::query()
                ->where('id', $tableId)
                ->where('current_order_id', $orderId)
                ->lockForUpdate()
                ->firstOrFail()
        );
    }

    public function assign(array $tableIds, string $orderId): void
    {
        if ($tableIds === []) {
            return;
        }

        Table::query()->whereIn('id', $tableIds)->update([
            'current_order_id' => $orderId,
            'status' => TableStatusEnum::Occupied->value,
        ]);
    }

    public function idsHeldBy(string $orderId): array
    {
        return Table::query()
            ->where('current_order_id', $orderId)
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }

    public function countHeldBy(string $orderId): int
    {
        return Table::query()->where('current_order_id', $orderId)->count();
    }

    public function releaseByOrder(string $orderId): array
    {
        $released = $this->idsHeldBy($orderId);

        Table::query()->where('current_order_id', $orderId)->update(self::freeColumns());

        return $released;
    }

    public function releaseByIds(array $tableIds, ?string $heldByOrderId = null): void
    {
        if ($tableIds === []) {
            return;
        }

        $query = Table::query()->whereIn('id', $tableIds);

        if ($heldByOrderId !== null) {
            $query->where('current_order_id', $heldByOrderId);
        }

        $query->update(self::freeColumns());
    }

    public function releaseByOrderAfterPayment(string $orderId, bool $needsCleaning): void
    {
        Table::query()->where('current_order_id', $orderId)->update([
            'current_order_id' => null,
            'status' => $needsCleaning
                ? TableStatusEnum::Cleaning->value
                : TableStatusEnum::Free->value,
        ]);
    }

    /**
     * @return array{current_order_id: null, status: string}
     */
    private static function freeColumns(): array
    {
        return [
            'current_order_id' => null,
            'status' => TableStatusEnum::Free->value,
        ];
    }

    private static function snapshot(Table $table): TableOccupancySnapshot
    {
        $status = $table->status instanceof TableStatusEnum
            ? $table->status->value
            : ($table->status === null ? null : (string) $table->status);

        return new TableOccupancySnapshot(
            id: (string) $table->id,
            code: $table->code === null ? null : (string) $table->code,
            currentOrderId: $table->current_order_id === null ? null : (string) $table->current_order_id,
            status: $status,
        );
    }

    public function markOccupied(string $tableId): void
    {
        // Chép NGUYÊN dạng ghi của bản cũ (`DB::table`), không đổi sang
        // `Table::query()` như các method bên cạnh — đây là lựa chọn TRUNG
        // THÀNH, không phải bắt buộc:
        //
        // Comment bản cũ nêu lý do "ghi thô để một giá trị `paid` cũ không ném
        // ValueError khi rehydrate". Đã KIỂM bằng mutation-check: đổi sang
        // `Table::query()->update()` thì bài test dựng đúng ca trạng thái-cũ
        // VẪN XANH — cập nhật hàng loạt qua builder không nạp model nên cast
        // không chạy, lo ngại đó chỉ đúng với `$table->update(...)` trên một
        // instance. Giữ nguyên vì đây là đường khách quét QR và PR này chỉ dời
        // ranh giới; đổi dạng ghi là việc khác, có rủi ro riêng (global scope).
        //
        // `tables` thuộc Organization, nên đọc/ghi thẳng Ở ĐÂY là trong module.
        DB::table('tables')->where('id', $tableId)->update(['status' => 'occupied']);
    }

    public function markFree(string $tableId): void
    {
        // Ghi thô, cùng dạng với `markOccupied` ngay trên và cùng lý do: đường
        // này chạy sau khi caller đã đọc trạng thái THÔ, nên một giá trị cũ
        // ngoài enum không được phép ném lúc ghi.
        DB::table('tables')->where('id', $tableId)->update(['status' => 'free']);
    }

    public function lockActiveByQrToken(string $qrToken): ?TableQrSnapshot
    {
        $table = Table::query()
            ->where('qr_token', $qrToken)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        return $table === null ? null : self::qrSnapshot($table);
    }

    public function snapshotById(string $tableId): ?TableQrSnapshot
    {
        $table = Table::query()->whereKey($tableId)->first();

        return $table === null ? null : self::qrSnapshot($table);
    }

    private static function qrSnapshot(Table $table): TableQrSnapshot
    {
        return new TableQrSnapshot(
            id: (string) $table->id,
            code: $table->code === null ? null : (string) $table->code,
            name: $table->name === null ? null : (string) $table->name,
            qrToken: $table->qr_token === null ? null : (string) $table->qr_token,
            // Cột THÔ, không qua cast — xem docblock của TableQrSnapshot.
            rawStatus: (string) $table->getRawOriginal('status'),
            organizationId: $table->organization_id === null ? null : (string) $table->organization_id,
            branchId: $table->branch_id === null ? null : (string) $table->branch_id,
            currentOrderId: $table->current_order_id === null ? null : (string) $table->current_order_id,
        );
    }
}
