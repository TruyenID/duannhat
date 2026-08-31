<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Table;
use App\Models\Zone;
use App\Services\Shop\Contracts\TableOccupancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * #1666 (#962) — `TableOccupancy::releaseByOrderAfterPayment()`.
 *
 * Bước 6 của `OrderClosingService::close()` từng là `Table::where('current_order_id', …)
 * ->update([...])` viết thẳng trong Ordering — một module ghi vào bảng của
 * Organization. Cổng nhận về câu ghi đó; bài này ghim rằng nó ghi ĐÚNG NHƯ CŨ.
 *
 * Điểm dễ trượt nhất khi dời chỗ một câu `update` là làm rộng mệnh đề WHERE, vì
 * bài test hạnh phúc vẫn xanh. Nên ở đây luôn có một bàn của đơn KHÁC đứng cạnh.
 */
beforeEach(function () {
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
    ]);
    $zone = Zone::factory()->create(['organization_id' => $orgId, 'branch_id' => $branch->id]);

    $this->occupancy = app(TableOccupancy::class);

    $this->tableHeldBy = function (?string $orderId) use ($orgId, $branch, $zone): Table {
        $table = Table::factory()->create([
            'organization_id' => $orgId,
            'branch_id' => $branch->id,
            'zone_id' => $zone->id,
        ]);

        DB::table('tables')->where('id', $table->id)->update([
            'current_order_id' => $orderId,
            'status' => 'occupied',
        ]);

        return $table;
    };
});

it('nhả về free khi chi nhánh không bắt dọn bàn', function () {
    $orderId = (string) Str::uuid();
    $table = ($this->tableHeldBy)($orderId);

    $this->occupancy->releaseByOrderAfterPayment($orderId, needsCleaning: false);

    $row = DB::table('tables')->where('id', $table->id)->first();
    expect($row->status)->toBe('free')
        ->and($row->current_order_id)->toBeNull();
});

it('nhả về cleaning khi chi nhánh bắt dọn bàn (#491)', function () {
    $orderId = (string) Str::uuid();
    $table = ($this->tableHeldBy)($orderId);

    $this->occupancy->releaseByOrderAfterPayment($orderId, needsCleaning: true);

    $row = DB::table('tables')->where('id', $table->id)->first();
    expect($row->status)->toBe('cleaning')
        ->and($row->current_order_id)->toBeNull();
});

it('nhả MỌI bàn của đơn (đơn gộp bàn) và KHÔNG chạm bàn của đơn khác', function () {
    $orderId = (string) Str::uuid();
    $otherOrderId = (string) Str::uuid();

    $merged = [($this->tableHeldBy)($orderId), ($this->tableHeldBy)($orderId)];
    $foreign = ($this->tableHeldBy)($otherOrderId);
    $idle = ($this->tableHeldBy)(null);

    $this->occupancy->releaseByOrderAfterPayment($orderId, needsCleaning: false);

    foreach ($merged as $table) {
        expect(DB::table('tables')->where('id', $table->id)->value('status'))->toBe('free');
    }

    // Bàn của đơn khác và bàn chưa gán đơn đều phải nguyên trạng: một WHERE nới
    // ra sẽ "nhả" cả nhà hàng mà bài test hạnh phúc vẫn xanh.
    expect(DB::table('tables')->where('id', $foreign->id)->value('current_order_id'))->toBe($otherOrderId)
        ->and(DB::table('tables')->where('id', $foreign->id)->value('status'))->toBe('occupied')
        ->and(DB::table('tables')->where('id', $idle->id)->value('status'))->toBe('occupied');
});

it('đơn không giữ bàn nào là no-op, không ném', function () {
    $this->occupancy->releaseByOrderAfterPayment((string) Str::uuid(), needsCleaning: true);

    expect(true)->toBeTrue();
});
