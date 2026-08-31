<?php

use App\Models\Brand;
use App\Models\TaxType;
use App\Models\TaxTypeRate;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * #1366 tầng 1 — hình dạng dữ liệu của kỳ hiệu lực thuế suất.
 *
 * Tầng này CHƯA đổi cách định giá: `TaxResolver` vẫn đọc `TaxType.rate`. Cái
 * được ghim ở đây là những thứ mà một tầng sau sẽ dựa vào và sẽ hỏng âm thầm
 * nếu sai — mốc backfill, tính idempotent, và rào chống hai kỳ cùng ngày bắt đầu.
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
});

function makeTaxType(float $rate, string $code): TaxType
{
    return TaxType::factory()->create([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'code' => $code,
        'rate' => $rate,
    ]);
}

it('lưu một kỳ hiệu lực mở, gắn với tax type', function () {
    $taxType = makeTaxType(10.00, 'STANDARD');

    $period = TaxTypeRate::create([
        'tax_type_id' => $taxType->id,
        'rate' => 10.00,
        'effective_from' => '1900-01-01',
        'effective_to' => null,
    ]);

    expect((string) $period->tax_type_id)->toBe((string) $taxType->id)
        ->and((float) $period->rate)->toBe(10.00)
        ->and($period->effective_to)->toBeNull();
});

it('chặn hai kỳ CÙNG ngày bắt đầu trên một tax type', function () {
    // Không chứng minh được "không chồng kỳ" — unique chỉ chặn ca rẻ nhất.
    // Ràng buộc đầy đủ là việc của service ở tầng sau; đây là rào DB.
    $taxType = makeTaxType(10.00, 'STANDARD');

    TaxTypeRate::create([
        'tax_type_id' => $taxType->id,
        'rate' => 10.00,
        'effective_from' => '2026-10-01',
        'effective_to' => null,
    ]);

    expect(fn () => TaxTypeRate::create([
        'tax_type_id' => $taxType->id,
        'rate' => 8.00,
        'effective_from' => '2026-10-01',
        'effective_to' => null,
    ]))->toThrow(QueryException::class);
});

it('cho phép hai tax type khác nhau cùng bắt đầu một ngày', function () {
    $standard = makeTaxType(10.00, 'STANDARD');
    $reduced = makeTaxType(8.00, 'REDUCED');

    TaxTypeRate::create([
        'tax_type_id' => $standard->id,
        'rate' => 10.00,
        'effective_from' => '2026-10-01',
    ]);
    TaxTypeRate::create([
        'tax_type_id' => $reduced->id,
        'rate' => 8.00,
        'effective_from' => '2026-10-01',
    ]);

    expect(TaxTypeRate::query()->count())->toBe(2);
});

it('bảng tax_type_rates khai FK CASCADE về tax_types', function () {
    // #2354 — HỎI BẢNG, đừng grep file migration.
    //
    // Bản trước đọc `file_get_contents(migrations/omnify/2000_01_01_000024_…)`
    // và tìm chuỗi `->onDelete('CASCADE')`. Hai chỗ sai độc lập:
    //
    //   1. Nó ghim TÊN FILE — một chi tiết generator sở hữu. `omnify reset`
    //      đánh số lại toàn thư mục và file đi từ `…_000024_…` sang
    //      `…_000220_…`, nên bài test đỏ vì lý do KHÔNG liên quan tới thứ nó
    //      bảo vệ.
    //   2. Nó kiểm SOURCE thay vì kiểm SCHEMA. Một chuỗi trong file PHP không
    //      chứng minh bảng thật mang ràng buộc đó — migration có thể bị sửa,
    //      thay thế, hoặc chưa từng chạy, và phép đo vẫn xanh.
    //
    // Lý do bản cũ né chuyện hỏi bảng là suite chạy SQLite với
    // `PRAGMA foreign_keys = 0`, nên xoá `tax_types` KHÔNG kích hoạt cascade và
    // một test "đếm bằng 0" sẽ xanh vì lý do sai. Điều đó vẫn đúng — nhưng nó
    // chỉ cấm kiểm HÀNH VI, không cấm kiểm CẤU TRÚC:
    // `PRAGMA foreign_key_list` báo `on_delete` **kể cả khi enforcement tắt**
    // (đo tại chỗ ngay dưới). Hành vi cascade thật vẫn kiểm trên MySQL — PR #1366.
    expect(DB::select('PRAGMA foreign_keys')[0]->foreign_keys)->toBe(0);

    $fks = DB::select('PRAGMA foreign_key_list(tax_type_rates)');

    $toTaxTypes = array_values(array_filter(
        $fks,
        fn ($fk): bool => $fk->table === 'tax_types' && $fk->from === 'tax_type_id',
    ));

    expect($toTaxTypes)->toHaveCount(1)
        ->and($toTaxTypes[0]->to)->toBe('id')
        ->and(strtoupper((string) $toTaxTypes[0]->on_delete))->toBe('CASCADE');
});

it('quan hệ rates() đọc được từ TaxType', function () {
    $taxType = makeTaxType(10.00, 'STANDARD');
    TaxTypeRate::create(['tax_type_id' => $taxType->id, 'rate' => 8.00, 'effective_from' => '1900-01-01', 'effective_to' => '2026-09-30']);
    TaxTypeRate::create(['tax_type_id' => $taxType->id, 'rate' => 10.00, 'effective_from' => '2026-10-01']);

    expect($taxType->rates()->count())->toBe(2);
});

it('kỳ đang hiệu lực chọn được bằng đúng vị từ ngày của quyết định', function () {
    // effective_from <= D AND (effective_to IS NULL OR D <= effective_to)
    $taxType = makeTaxType(10.00, 'STANDARD');
    TaxTypeRate::create(['tax_type_id' => $taxType->id, 'rate' => 8.00, 'effective_from' => '1900-01-01', 'effective_to' => '2026-09-30']);
    TaxTypeRate::create(['tax_type_id' => $taxType->id, 'rate' => 10.00, 'effective_from' => '2026-10-01']);

    $rateOn = function (string $date) use ($taxType) {
        // Bind a Carbon, NOT a bare 'Y-m-d' string. The `date` cast writes
        // 'Y-m-d 00:00:00', so on SQLite '2026-10-01 00:00:00' <= '2026-10-01'
        // is FALSE and the boundary day silently resolves to no rate at all.
        // See the storage-format test below.
        $d = Carbon::parse($date)->startOfDay();

        return (float) TaxTypeRate::query()
            ->where('tax_type_id', $taxType->id)
            ->where('effective_from', '<=', $d)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $d))
            ->value('rate');
    };

    // Ngày chuyển thuế suất là ngày đắt nhất để sai — kiểm cả hai mép.
    expect($rateOn('2026-09-30'))->toBe(8.00)
        ->and($rateOn('2026-10-01'))->toBe(10.00)
        ->and($rateOn('1990-01-01'))->toBe(8.00);
});

it('ghim hình dạng LƯU của cột ngày — bẫy chờ sẵn cho tầng resolver', function () {
    // Cast `date` của Eloquent GHI 'Y-m-d 00:00:00'. Trên SQLite (engine của
    // suite này) cột DATE giữ nguyên chuỗi đó; trên MySQL cột DATE cắt còn
    // 'Y-m-d'. Hệ quả: so sánh với một chuỗi ngày trần
    //
    //     ->where('effective_from', '<=', '2026-10-01')
    //
    // là ĐÚNG trên MySQL và SAI trên SQLite ('2026-10-01 00:00:00' > '2026-10-01'),
    // nên nó rơi mất ĐÚNG NGÀY CHUYỂN THUẾ SUẤT — ngày đắt nhất để sai — và chỉ
    // sai ở test, hoặc chỉ sai ở production, tuỳ chiều.
    //
    // Tầng resolver phải bind Carbon/DateTimeInterface, đừng bind chuỗi trần.
    $taxType = makeTaxType(10.00, 'STANDARD');
    TaxTypeRate::create(['tax_type_id' => $taxType->id, 'rate' => 10.00, 'effective_from' => '2026-10-01']);

    $stored = DB::table('tax_type_rates')->value('effective_from');

    // Ghim cả hai hình dạng hợp lệ, và chứng minh chuỗi trần KHÔNG khớp trên
    // engine đang chạy nếu giá trị lưu có phần giờ.
    expect($stored)->toBeIn(['2026-10-01', '2026-10-01 00:00:00']);

    if ($stored === '2026-10-01 00:00:00') {
        expect(TaxTypeRate::query()->where('effective_from', '<=', '2026-10-01')->count())
            ->toBe(0, 'chuỗi ngày trần khớp được ⇒ bẫy đã biến mất, cập nhật lại ghi chú này');
    }
});
