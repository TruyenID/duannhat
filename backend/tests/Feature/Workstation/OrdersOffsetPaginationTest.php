<?php

/**
 * #3196 — feed đơn của máy trạm phải ĐI TRANG được, và phải NÓI khi còn nữa.
 *
 * Trước bản này, nhánh `since` sắp `created_at` DESC với `limit`, mà `since` là
 * cận DƯỚI — nên không có đường nào với tới phần cũ hơn trang đầu. Tệ hơn:
 * `count` trả về là số dòng của TRANG, nên "vừa đủ 500" và "bị cắt" đọc y hệt
 * nhau, và `SyncPuller.Recover()` trả về một con số nghe như thành công.
 *
 * Đường này chạy sau khi pair lại hoặc crash — đúng lúc máy không còn state cục
 * bộ. Đo 2026-08-18 trên production: 人形町 421 đơn/30 ngày, tức 84% của trần,
 * với tốc độ ~400 đơn/tháng. Bộ test này ra đời TRƯỚC khi nó nổ.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Device;
use App\Models\Organization;
use App\Omnify\Enums\DeviceStatusEnum;
use App\Omnify\Enums\DeviceTypeEnum;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();

    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->wsToken = Str::random(64);

    Device::factory()->create([
        'type' => DeviceTypeEnum::Workstation,
        'status' => DeviceStatusEnum::Active,
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
});

function seedOrders(int $n): void
{
    CustomerOrder::factory()->count($n)->create([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'branch_id' => test()->branch->id,
    ]);
}

function pullOrders(array $query): TestResponse
{
    return test()
        ->withHeader('Authorization', 'Bearer '.test()->wsToken)
        ->getJson('/api/v1/workstation/orders?'.http_build_query($query));
}

it('#3196 KÊU "còn nữa": trang đầy thì has_more=true và next_offset trỏ tiếp', function () {
    seedOrders(7);

    $res = pullOrders(['limit' => 3, 'offset' => 0])->assertOk();

    expect($res->json('count'))->toBe(3)
        ->and($res->json('has_more'))->toBeTrue()
        ->and($res->json('next_offset'))->toBe(3);
});

it('#3196 IM khi đã hết: trang cuối không khai còn nữa', function () {
    seedOrders(7);

    $res = pullOrders(['limit' => 3, 'offset' => 6])->assertOk();

    expect($res->json('count'))->toBe(1)
        ->and($res->json('has_more'))->toBeFalse()
        ->and($res->json('next_offset'))->toBeNull();
});

it('#3196 tổng vừa ĐÚNG limit thì KHÔNG khai còn nữa — đây là ranh giới dễ sai nhất', function () {
    // Ranh giới off-by-one: 3 đơn với limit=3. Nếu `has_more` tính bằng
    // `count === limit` thay vì lấy dư một dòng, nó sẽ khai còn nữa và client
    // gọi thêm một lượt rỗng mãi mãi.
    seedOrders(3);

    $res = pullOrders(['limit' => 3, 'offset' => 0])->assertOk();

    expect($res->json('count'))->toBe(3)
        ->and($res->json('has_more'))->toBeFalse()
        ->and($res->json('next_offset'))->toBeNull();
});

it('#3196 offset thật sự BỎ QUA — hai trang không giao nhau', function () {
    // Không có phép so này thì `offset` có thể bị bỏ qua hoàn toàn mà mọi
    // khẳng định về `has_more` vẫn xanh, và client sẽ kéo cùng một trang N lần
    // rồi tưởng mình đã khôi phục đủ.
    seedOrders(6);

    $first = collect(pullOrders(['limit' => 3, 'offset' => 0])->json('data'))->pluck('id');
    $second = collect(pullOrders(['limit' => 3, 'offset' => 3])->json('data'))->pluck('id');

    expect($first)->toHaveCount(3)
        ->and($second)->toHaveCount(3)
        ->and($first->intersect($second))->toBeEmpty();
});

it('#3196 đi hết mọi trang thì lấy đủ, không dòng nào lặp', function () {
    seedOrders(10);

    $seen = collect();
    $offset = 0;
    for ($page = 0; $page < 10; $page++) {
        $res = pullOrders(['limit' => 4, 'offset' => $offset])->assertOk();
        $seen = $seen->merge(collect($res->json('data'))->pluck('id'));
        if (! $res->json('has_more')) {
            break;
        }
        $offset = $res->json('next_offset');
    }

    expect($seen)->toHaveCount(10)
        ->and($seen->unique())->toHaveCount(10);
});

it('#3196 thiếu offset vẫn chạy như cũ — máy trạm chưa cập nhật không hỏng', function () {
    // Luật của repo là backend deploy TRƯỚC workstation, nên trạng thái "Cloud
    // mới, máy trạm cũ" tồn tại thật. Máy cũ không gửi `offset`.
    seedOrders(5);

    $res = pullOrders(['limit' => 500])->assertOk();

    expect($res->json('count'))->toBe(5)
        ->and($res->json('has_more'))->toBeFalse();
});

it('#3196 nhánh `id` không bị đụng — nó bỏ qua ngữ nghĩa trang', function () {
    // Nhánh chiếu-một-đơn `return` sớm, trước cả `offset`/`limit`. Ghim lại vì
    // bản vá này chèn code ngay cạnh nó.
    seedOrders(3);
    $one = CustomerOrder::query()->where('branch_id', $this->branch->id)->first();

    $res = pullOrders(['id' => $one->id])->assertOk();

    expect($res->json('count'))->toBe(1)
        ->and($res->json('data.0.id'))->toBe($one->id);
});
