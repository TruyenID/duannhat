<?php

use App\Console\Commands\TaxClassificationBreakdown;
use App\Http\Requests\TaxTypeStoreRequest;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Organization;
use App\Models\TaxType;
use App\Omnify\Enums\TaxClassificationEnum;
use Illuminate\Support\Str;

/*
 * #1367 — trục phân loại thuế, và nơi tiêu thụ nó.
 *
 * Điều PHẢI chứng minh không phải "cột tồn tại" — mà là **ba loại 0% thôi gộp
 * thành một dòng**. Trước bản này, 非課税 / 不課税 / 免税 đều là "tax type rate
 * 0" và mọi báo cáo nhóm theo thuế suất sẽ trả về đúng MỘT dòng "0%", trong khi
 * trên tờ khai chúng nằm ở ba chỗ khác nhau.
 */

beforeEach(function () {
    $orgId = (string) Str::uuid();
    $this->organization = Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->organization->id]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->organization->id,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    $this->command = app(TaxClassificationBreakdown::class);
});

function taxTypeWith(?TaxClassificationEnum $classification, string $code, $ctx): TaxType
{
    return TaxType::factory()->create([
        'organization_id' => $ctx->organization->id,
        'brand_id' => $ctx->brand->id,
        'code' => $code,
        'rate' => 0,
        'classification' => $classification?->value,
    ]);
}

function orderLineWith(TaxType $type, $ctx, float $subtotal = 1000): void
{
    $order = CustomerOrder::factory()->create([
        'organization_id' => $ctx->organization->id,
        'branch_id' => $ctx->branch->id,
        'opened_at' => now()->subHour(),
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'tax_type_id' => $type->id,
        'tax_rate' => 0,
        'tax_amount' => 0,
        'subtotal' => $subtotal,
    ]);
}

it('#1367 enum có ĐÚNG bốn giá trị chuẩn quốc tế', function () {
    // Bốn giá trị này ánh xạ được cho cả Nhật và Việt Nam — xem bảng trong
    // `schemas/Shared/Enum/TaxClassification.yaml`. Thêm giá trị thứ năm nghĩa
    // là ai đó đang chẻ trục theo quốc gia, và điều đó chẻ đôi MỌI nơi tiêu thụ.
    expect(TaxClassificationBreakdown::classifications())
        ->toBe(['taxable', 'exempt', 'out_of_scope', 'zero_rated']);
});

it('#1367 ba loại 0% KHÔNG còn gộp thành một dòng', function () {
    $exempt = taxTypeWith(TaxClassificationEnum::Exempt, 'EXEMPT', $this);
    $outOfScope = taxTypeWith(TaxClassificationEnum::OutOfScope, 'OUTSCOPE', $this);
    $zeroRated = taxTypeWith(TaxClassificationEnum::ZeroRated, 'ZERO', $this);

    orderLineWith($exempt, $this, 1000);
    orderLineWith($outOfScope, $this, 2000);
    orderLineWith($zeroRated, $this, 3000);

    $rows = $this->command->breakdown($this->branch->id, now()->subDay(), now()->addDay());

    // Nhóm theo THUẾ SUẤT thì cả ba là một dòng "0%". Nhóm theo TRỤC thì là ba.
    expect($rows)->toHaveCount(3);

    $byClass = collect($rows)->keyBy('classification');
    expect((float) $byClass['exempt']['taxable'])->toBe(1000.0)
        ->and((float) $byClass['out_of_scope']['taxable'])->toBe(2000.0)
        ->and((float) $byClass['zero_rated']['taxable'])->toBe(3000.0);
});

it('#1367 dòng CHƯA PHÂN LOẠI hiện riêng, không dồn vào taxable', function () {
    // Đây là lý do cột nullable. Dồn vào `taxable` là in ra một tờ khai vẫn cân
    // và vẫn sai — sai kiểu không ai phát hiện được.
    $unclassified = taxTypeWith(null, 'LEGACY', $this);
    $exempt = taxTypeWith(TaxClassificationEnum::Exempt, 'EXEMPT', $this);

    orderLineWith($unclassified, $this, 500);
    orderLineWith($exempt, $this, 700);

    $rows = $this->command->breakdown($this->branch->id, now()->subDay(), now()->addDay());
    $nulls = array_values(array_filter($rows, fn ($r) => $r['classification'] === null));

    expect($nulls)->toHaveCount(1)
        ->and((float) $nulls[0]['taxable'])->toBe(500.0)
        // và nó đứng ĐẦU bảng — con số chưa phân loại phải đập vào mắt.
        ->and($rows[0]['classification'])->toBeNull();
});

it('#1367 lệnh THOÁT KHÁC 0 khi còn dòng chưa phân loại', function () {
    // Thoát 0 kèm một cảnh báo in ra màn hình là thứ dễ bị bỏ qua trong một
    // script xuất báo cáo. Mã thoát mới là thứ CI/cron đọc được.
    orderLineWith(taxTypeWith(null, 'LEGACY', $this), $this);

    $code = Artisan::call('tax:classification-breakdown', [
        '--branch' => $this->branch->id,
        '--from' => now()->subDay()->toDateString(),
        '--to' => now()->addDay()->toDateString(),
    ]);

    expect($code)->not->toBe(0);
});

it('#1367 phân loại KHÔNG suy từ thuế suất — hai loại cùng 0% khác nhóm', function () {
    // Ghim chính cái luận điểm của issue: `rate` không nói được phân loại.
    // Nếu ai đó "tối ưu" bằng cách suy phân loại từ rate, test này đỏ.
    $a = taxTypeWith(TaxClassificationEnum::Exempt, 'A', $this);
    $b = taxTypeWith(TaxClassificationEnum::OutOfScope, 'B', $this);

    expect((float) $a->rate)->toBe((float) $b->rate)
        ->and($a->classification)->not->toBe($b->classification);
});

it('#1367 validation từ chối chuỗi tự do, chỉ nhận bốn giá trị', function () {
    // Cả điểm của trục này là thoát khỏi chuỗi `code` tự nhập. Generator phát
    // `['nullable','string']` — nếu ai đó bỏ `Rule::enum` thì quay lại đúng
    // chỗ cũ, chỉ khác tên cột.
    $rules = (new TaxTypeStoreRequest)->rules();

    expect($rules)->toHaveKey('classification');
    $serialized = json_encode($rules['classification']);
    expect($serialized)->not->toContain('"string"');
});
