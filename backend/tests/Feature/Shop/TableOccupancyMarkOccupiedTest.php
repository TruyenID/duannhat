<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Table;
use App\Models\Zone;
use App\Services\Shop\Contracts\TableOccupancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * #1622 — `TableOccupancy::markOccupied()`.
 *
 * Đây là chỗ **GHI** duy nhất mà CustomerEngagement thực hiện trên bảng của
 * Organization, và nó vô hình với **cả ba** hàng rào của repo trước bản vá:
 *
 *   - `deptrac` — `DB::table('tables')` không import class nào;
 *   - `architecture:raw-table-reads` — chỉ đếm, không phân biệt đọc/ghi;
 *   - `architecture:domain-writers` — `tables` KHÔNG nằm trong danh sách
 *     aggregate được canh (product · menu · customer · promotion · order ·
 *     invoice · payment).
 *
 * Nói cách khác: một module ghi thẳng vào bảng của module khác, và không công
 * cụ nào của #962 nói ra. Bài test này ghim hành vi sau khi đưa nó qua cổng.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    // `zones` KHÔNG có cột `brand_id` — khu vực gắn theo chi nhánh.
    $this->zone = Zone::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->occupancy = app(TableOccupancy::class);

    $this->tableWith = function (string $status): Table {
        // `tables` cũng KHÔNG có `brand_id` — bàn gắn theo chi nhánh + khu vực.
        $table = Table::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => $this->branch->id,
            'zone_id' => $this->zone->id,
        ]);
        // Ghi thẳng để dựng được cả trạng thái mà cast hiện tại không nhận.
        DB::table('tables')->where('id', $table->id)->update(['status' => $status]);

        return $table;
    };
});

it('free → occupied', function () {
    $table = ($this->tableWith)('free');

    $this->occupancy->markOccupied((string) $table->id);

    expect(DB::table('tables')->where('id', $table->id)->value('status'))->toBe('occupied');
});

it('paid → occupied (ca force-new của phiên QR)', function () {
    $table = ($this->tableWith)('paid');

    $this->occupancy->markOccupied((string) $table->id);

    expect(DB::table('tables')->where('id', $table->id)->value('status'))->toBe('occupied');
});

/**
 * Lý do bản cũ ghi thô, chép nguyên vào adapter: giá trị đang nằm trong DB có
 * thể là một trạng thái CŨ mà enum hiện tại không còn nhận. Ghi qua model
 * builder sẽ hydrate + cast và ném `ValueError` ngay trên đường khách quét QR.
 * Bài này dựng đúng ca đó.
 */
it('trạng thái cũ enum không nhận vẫn ghi được, KHÔNG ném ValueError', function () {
    $table = ($this->tableWith)('legacy_status_no_longer_in_enum');

    $this->occupancy->markOccupied((string) $table->id);

    expect(DB::table('tables')->where('id', $table->id)->value('status'))->toBe('occupied');
});

it('chỉ chạm ĐÚNG bàn được nêu', function () {
    $target = ($this->tableWith)('free');
    $other = ($this->tableWith)('free');

    $this->occupancy->markOccupied((string) $target->id);

    expect(DB::table('tables')->where('id', $other->id)->value('status'))->toBe('free');
});

it('KHÔNG đụng current_order_id — phiên QR chưa có đơn nào', function () {
    $table = ($this->tableWith)('free');

    $this->occupancy->markOccupied((string) $table->id);

    expect(DB::table('tables')->where('id', $table->id)->value('current_order_id'))->toBeNull();
});
