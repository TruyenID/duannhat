<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\TillTenderType;
use App\Models\User;
use Illuminate\Support\Str;

/*
 * #1881 — từ vựng tender ở HQ.
 *
 * Ba ràng buộc dưới đây KHÔNG phải quy tắc nghiệp vụ tuỳ chọn, chúng là hệ quả
 * của việc `tender_key` được chụp lên chứng từ tiền:
 *
 *   1. khoá bất biến
 *   2. nhóm bất biến khi đã có payment
 *   3. không xoá được khi đã có payment
 *
 * Cả ba đều "hỏng muộn": vi phạm hôm nay chỉ lộ ra vài tuần sau, qua một báo
 * cáo 精算 gom sai hoặc một dòng sổ cái không tra được về đâu. Test là chỗ duy
 * nhất bắt được chúng ngay.
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'hq-'.Str::lower(Str::random(4)),
        'is_active' => true,
    ]);

    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($this->user, $this->orgId);
    $this->actingAs($this->user);

    $this->base = "/api/v1/hq/{$this->brand->slug}/tender-types";

    $this->makeTender = function (string $key, ?string $parent = null): TillTenderType {
        return TillTenderType::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => null,
            'tender_key' => $key,
            'parent_tender_key' => $parent,
            'is_active' => true,
        ]);
    };

    // Một payment tham chiếu tender — đây là thứ biến mọi thao tác "quản trị"
    // thành thao tác trên dữ liệu bất biến.
    $this->usedBy = function (string $key): void {
        $order = CustomerOrder::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
        ]);
        OrderPayment::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'customer_order_id' => $order->id,
            'tender_key' => $key,
        ]);
    };
});

it('#1881 liệt kê từ vựng cấp tổ chức kèm số payment đang dùng', function () {
    ($this->makeTender)('cash');
    ($this->usedBy)('cash');
    ($this->usedBy)('cash');

    $row = $this->getJson($this->base)->assertOk()->json('data.0');

    expect($row['tender_key'])->toBe('cash')
        ->and($row['payment_count'])->toBe(2)
        // Ba cờ này để UI nói SỰ THẬT trước khi người dùng gõ, thay vì cho gõ
        // rồi trả 409.
        ->and($row['key_editable'])->toBeFalse()
        ->and($row['group_editable'])->toBeFalse()
        ->and($row['deletable'])->toBeFalse();
});

it('#1881 KHÔNG cho đổi tender_key — và không im lặng bỏ qua', function () {
    $t = ($this->makeTender)('cash');

    $res = $this->patchJson("{$this->base}/{$t->id}", ['tender_key' => 'tien_mat'])
        ->assertStatus(409);

    expect($res->json('error_code'))->toBe('TENDER_KEY_IMMUTABLE');

    // Bỏ qua trong im lặng còn tệ hơn từ chối: người dùng thấy 200, tin là đã
    // đổi, rồi phát hiện vài tuần sau qua một báo cáo.
    expect(TillTenderType::query()->find($t->id)->tender_key)->toBe('cash');
});

it('#1881 KHÔNG cho đổi nhóm khi tender đã có payment', function () {
    $t = ($this->makeTender)('paypay', 'cashless');
    ($this->usedBy)('paypay');

    $res = $this->patchJson("{$this->base}/{$t->id}", ['parent_tender_key' => 'qr'])
        ->assertStatus(409);

    expect($res->json('error_code'))->toBe('TENDER_GROUP_IMMUTABLE_ONCE_USED')
        ->and($res->json('payment_count'))->toBe(1)
        ->and(TillTenderType::query()->find($t->id)->parent_tender_key)->toBe('cashless');
});

it('#1881 CHO đổi nhóm khi chưa có payment nào', function () {
    // Ràng buộc phải hẹp đúng mức: chặn cả khi chưa ai dùng thì HQ không bao
    // giờ sửa được một lần gõ nhầm, và sẽ có người đi sửa thẳng vào DB.
    $t = ($this->makeTender)('paypay', 'cashless');

    $this->patchJson("{$this->base}/{$t->id}", ['parent_tender_key' => 'qr'])->assertOk();

    expect(TillTenderType::query()->find($t->id)->parent_tender_key)->toBe('qr');
});

it('#1881 KHÔNG cho xoá tender đã có payment, nhưng CHO tắt', function () {
    $t = ($this->makeTender)('cash');
    ($this->usedBy)('cash');

    $res = $this->deleteJson("{$this->base}/{$t->id}")->assertStatus(409);
    expect($res->json('error_code'))->toBe('TENDER_IN_USE');
    expect(TillTenderType::query()->find($t->id))->not->toBeNull();

    // Đường thay thế phải THẬT SỰ dùng được — nếu không thì 409 ở trên chỉ là
    // ngõ cụt, và người vận hành sẽ đi tìm cách khác.
    $this->patchJson("{$this->base}/{$t->id}", ['is_active' => false])->assertOk();
    expect((bool) TillTenderType::query()->find($t->id)->is_active)->toBeFalse();
});

it('#1881 CHO xoá tender chưa ai dùng', function () {
    $t = ($this->makeTender)('go_nham');

    $this->deleteJson("{$this->base}/{$t->id}")->assertOk();

    expect(TillTenderType::query()->find($t->id))->toBeNull();
});

it('#1881 category là BẮT BUỘC — cột NOT NULL và nó quyết định cách gom 精算', function () {
    $this->postJson($this->base, ['tender_key' => 'thieu_category', 'name' => 'X'])
        ->assertStatus(422);
});

it('#1881 khoá phải là chữ thường/số/gạch dưới', function () {
    // Khoá là thứ MÁY đọc và nằm lại trên chứng từ mười năm sau. Đặt bằng tiếng
    // Nhật thì nó vỡ lúc xuất CSV hoặc lúc so khớp với báo cáo của gateway —
    // hai chỗ phát hiện ra rất muộn.
    $this->postJson($this->base, ['tender_key' => '現金', 'name' => 'Tiền mặt', 'category' => 'cash'])
        ->assertStatus(422);

    $this->postJson($this->base, ['tender_key' => 'cash_jpy', 'name' => 'Tiền mặt', 'category' => 'cash'])
        ->assertStatus(201);
});

it('#1881 không thấy được từ vựng của tổ chức khác', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);
    TillTenderType::factory()->create([
        'organization_id' => $otherOrgId,
        'branch_id' => null,
        'tender_key' => 'cash',
    ]);

    ($this->makeTender)('paypay');

    $keys = collect($this->getJson($this->base)->assertOk()->json('data'))->pluck('tender_key');

    expect($keys)->toContain('paypay')->not->toContain('cash');
});

it('#1881 đếm payment KHÔNG rò chéo tổ chức', function () {
    // `cash` là khoá phổ biến nhất có thể có. Thiếu điều kiện tổ chức trong
    // phép đếm thì payment của một tổ chức khác sẽ chặn thao tác ở đây, và
    // người vận hành không có cách nào nhìn ra tại sao.
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);
    $otherBrand = Brand::factory()->create(['console_organization_id' => $otherOrgId]);
    $otherOrder = CustomerOrder::factory()->create([
        'organization_id' => $otherOrgId,
        'brand_id' => $otherBrand->id,
    ]);
    OrderPayment::factory()->create([
        'organization_id' => $otherOrgId,
        'brand_id' => $otherBrand->id,
        'customer_order_id' => $otherOrder->id,
        'tender_key' => 'cash',
    ]);

    $t = ($this->makeTender)('cash');

    // Tổ chức này chưa ai dùng `cash`, nên xoá được — bất kể tổ chức kia.
    $this->deleteJson("{$this->base}/{$t->id}")->assertOk();
});
