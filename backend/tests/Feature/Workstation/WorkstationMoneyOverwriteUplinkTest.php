<?php

declare(strict_types=1);

/**
 * #2885 — bằng chứng lệch tiền đi từ máy trạm lên Cloud.
 *
 * Đây là bằng chứng đối soát TIỀN trên sản phẩm ĐÃ RELEASE, nên mọi bài dưới
 * đây đi QUA endpoint HTTP, không gọi thẳng vào tầng service. Bài học #2622:
 * `$request->validate()` strip mọi khoá không có rule, nên một trường có thể
 * đi hết đường service mà không bao giờ nhận được giá trị từ thiết bị — và
 * **mọi test service-level vẫn xanh** trong khi tính năng chết im lặng trên
 * đường thật.
 *
 * Ba bất biến nặng nhất, theo thứ tự:
 *
 *   1. `(device_id, local_id)` là duy nhất **ở tầng DB**. Không có bài chèn
 *      thô thì "idempotent" chỉ là lời hứa của code.
 *   2. Đẩy lại KHÔNG ghi đè — kể cả khi số khác. Số khác nghĩa là có bug ở đầu
 *      kia, và ghi đè sẽ xoá mất dấu vết của chính bug đó.
 *   3. Một dòng hỏng/trùng không làm rơi cả lô.
 */

use App\Models\Branch;
use App\Models\Device;
use App\Models\OrderMoneyOverwrite;
use App\Models\Organization;
use App\Services\Device\DeviceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

const MONEY_OVERWRITE_URL = '/api/v1/workstation/money-overwrites';

/**
 * Mười một trường tiền của hợp đồng wire: `paid_locally` + năm cặp local/cloud.
 *
 * @return list<string>
 */
function moneyOverwriteMoneyFields(): array
{
    return [
        'paid_locally',
        'total_amount_local', 'total_amount_cloud',
        'subtotal_local', 'subtotal_cloud',
        'tax_amount_local', 'tax_amount_cloud',
        'service_charge_local', 'service_charge_cloud',
        'discount_amount_local', 'discount_amount_cloud',
    ];
}

/**
 * Một dòng đúng hợp đồng. Mọi số đều KHÁC NHAU có chủ đích: nếu controller
 * gán nhầm cột (copy-paste `subtotal_cloud` vào `subtotal_local`…), một bộ giá
 * trị giống nhau sẽ nuốt lỗi đó.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function moneyOverwriteRow(array $overrides = []): array
{
    return array_merge([
        'local_id' => 123,
        'order_id' => '019feb37-0000-7000-8000-000000000001',
        'occurred_at' => '2026-08-13T07:01:33Z',
        'paid_locally' => 297,
        'total_amount_local' => 1190, 'total_amount_cloud' => 297,
        'subtotal_local' => 1081, 'subtotal_cloud' => 270,
        'tax_amount_local' => 109, 'tax_amount_cloud' => 27,
        'service_charge_local' => 50, 'service_charge_cloud' => 40,
        'discount_amount_local' => 30, 'discount_amount_cloud' => 20,
    ], $overrides);
}

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->wsToken = Str::random(64);
    $this->device = Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->push = fn (array $rows, ?string $token = null) => $this
        ->withHeaders(['Authorization' => 'Bearer '.($token ?? $this->wsToken)])
        ->postJson(MONEY_OVERWRITE_URL, ['overwrites' => $rows]);
});

// ─────────────────────────────────────────────────────────────────────────────
//  HỢP ĐỒNG
// ─────────────────────────────────────────────────────────────────────────────

it('#2885 nhận một dòng: 202 + thân {accepted, duplicates} và ĐỦ 11 trường tiền vào DB', function () {
    $res = ($this->push)([moneyOverwriteRow()]);

    // 202 chứ không 200, cùng lý lẽ với `/alerts`: Cloud đã NHẬN, không hứa gì
    // thêm. Ghim đúng mã số vì máy trạm phân biệt 202 với 200 khi quyết định
    // đánh dấu `synced_at`.
    $res->assertStatus(202)->assertExactJson(['accepted' => 1, 'duplicates' => 0]);

    $row = OrderMoneyOverwrite::query()->sole();

    expect($row->local_id)->toBe(123)
        ->and($row->order_id)->toBe('019feb37-0000-7000-8000-000000000001')
        // Ba trường quy-về-đâu lấy từ TOKEN, không từ payload.
        ->and($row->device_id)->toBe((string) $this->device->id)
        ->and($row->branch_id)->toBe((string) $this->branch->id)
        ->and($row->organization_id)->toBe($this->orgId);

    // Mười một trường tiền, từng cái một, đúng giá trị đã gửi. Đây là bài duy
    // nhất bắt được lỗi gán nhầm cột — kiểu `subtotal_cloud` rơi vào
    // `subtotal_local` — vì mọi số trong fixture đều khác nhau.
    $sent = moneyOverwriteRow();
    foreach (moneyOverwriteMoneyFields() as $field) {
        expect($row->{$field})->toBe($sent[$field], "trường tiền `{$field}` sai");
    }
});

it('#2885 giá trị ÂM và 0 lưu nguyên vẹn — đó chính là những dòng cần bằng chứng nhất', function () {
    // `min:0` trên trường tiền sẽ từ chối đúng những dòng bất thường nhất.
    // Giảm giá vượt sinh ra số âm thật; 0 phân biệt "không có khoản này" với
    // "không gửi lên".
    ($this->push)([moneyOverwriteRow([
        'paid_locally' => 0,
        'total_amount_local' => -1500, 'total_amount_cloud' => 0,
        'subtotal_local' => 0, 'subtotal_cloud' => -1,
        'tax_amount_local' => 0, 'tax_amount_cloud' => 0,
        'service_charge_local' => -50, 'service_charge_cloud' => 0,
        'discount_amount_local' => -2000, 'discount_amount_cloud' => -1999,
    ])])->assertStatus(202);

    $row = OrderMoneyOverwrite::query()->sole();

    expect($row->paid_locally)->toBe(0)
        ->and($row->total_amount_local)->toBe(-1500)
        ->and($row->total_amount_cloud)->toBe(0)
        ->and($row->subtotal_cloud)->toBe(-1)
        ->and($row->service_charge_local)->toBe(-50)
        ->and($row->discount_amount_local)->toBe(-2000)
        ->and($row->discount_amount_cloud)->toBe(-1999);
});

// ─────────────────────────────────────────────────────────────────────────────
//  IDEMPOTENCY / REPLAY — bất biến quan trọng nhất
// ─────────────────────────────────────────────────────────────────────────────

it('#2885 đẩy lại Y HỆT ⇒ duplicates++, KHÔNG thêm hàng', function () {
    ($this->push)([moneyOverwriteRow()])->assertStatus(202)
        ->assertExactJson(['accepted' => 1, 'duplicates' => 0]);

    ($this->push)([moneyOverwriteRow()])->assertStatus(202)
        ->assertExactJson(['accepted' => 0, 'duplicates' => 1]);

    expect(OrderMoneyOverwrite::query()->count())->toBe(1);
});

it('#2885 đẩy lại cùng khoá nhưng SỐ KHÁC ⇒ vẫn KHÔNG ghi đè, giá trị cũ còn nguyên', function () {
    // Bất biến nặng nhất của cả issue. Nếu đầu kia có bug làm số đổi giữa hai
    // lượt đẩy, ghi đè sẽ xoá mất dấu vết của chính bug đó — và bằng chứng
    // kiểm toán bị sửa lặng lẽ còn tệ hơn không có bằng chứng.
    ($this->push)([moneyOverwriteRow()])->assertStatus(202);

    ($this->push)([moneyOverwriteRow([
        'total_amount_local' => 999999,
        'total_amount_cloud' => 888888,
        'paid_locally' => 777777,
        'order_id' => '019feb37-0000-7000-8000-0000000000ff',
        'occurred_at' => '2027-01-01T00:00:00Z',
    ])])->assertStatus(202)->assertExactJson(['accepted' => 0, 'duplicates' => 1]);

    $row = OrderMoneyOverwrite::query()->sole();

    expect($row->total_amount_local)->toBe(1190)
        ->and($row->total_amount_cloud)->toBe(297)
        ->and($row->paid_locally)->toBe(297)
        // Cả trường KHÔNG phải tiền cũng không được đổi.
        ->and($row->order_id)->toBe('019feb37-0000-7000-8000-000000000001')
        ->and($row->occurred_at->toIso8601ZuluString())->toBe('2026-08-13T07:01:33Z');
});

it('#2885 unique index TỒN TẠI Ở TẦNG DB — chèn thô bỏ qua tầng ứng dụng phải ném', function () {
    ($this->push)([moneyOverwriteRow()])->assertStatus(202);

    $raw = [
        'id' => (string) Str::uuid(),
        'device_id' => (string) $this->device->id,
        'local_id' => 123,            // ← trùng cặp khoá với dòng vừa ghi
        'branch_id' => (string) $this->branch->id,
        'organization_id' => $this->orgId,
        'order_id' => (string) Str::uuid(),
        'occurred_at' => '2026-08-13 07:01:33',
    ];

    foreach (moneyOverwriteMoneyFields() as $field) {
        $raw[$field] = 1;
    }

    // `DB::table()` không đi qua Eloquent, không qua model guard, không qua
    // controller. Nếu bài này KHÔNG ném thì ràng buộc duy nhất chống trùng là
    // một câu `catch` trong PHP — và hai request song song sẽ lách qua nó.
    expect(fn () => DB::table('order_money_overwrites')->insert($raw))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(OrderMoneyOverwrite::query()->count())->toBe(1);
});

it('#2885 index đó phải là UNIQUE và phủ đúng (device_id, local_id)', function () {
    // Bài trên chứng minh "có gì đó chặn". Bài này chứng minh thứ chặn là đúng
    // cặp cột đã chốt trong hợp đồng — chứ không phải một unique nào khác vô
    // tình trùng (ví dụ `order_id`), thứ sẽ gộp hai lần ghi đè cùng một đơn.
    $unique = collect(Schema::getIndexes('order_money_overwrites'))
        ->filter(fn (array $i): bool => (bool) $i['unique'])
        ->map(fn (array $i): array => array_map('strtolower', $i['columns']))
        ->values()
        ->all();

    expect($unique)->toContain(['device_id', 'local_id']);
});

it('#2885 hai THIẾT BỊ khác nhau cùng local_id ⇒ HAI hàng, không đụng nhau', function () {
    // `local_id` là autoincrement CỤC BỘ, nên hai máy trạm trong cùng một quán
    // gần như chắc chắn phát ra cùng những con số 1, 2, 3… Khoá thiếu vế
    // `device_id` sẽ làm máy thứ hai bị coi là trùng và bằng chứng của nó biến
    // mất trong im lặng.
    $secondToken = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $secondToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    ($this->push)([moneyOverwriteRow(['local_id' => 7, 'total_amount_local' => 100])])
        ->assertStatus(202)->assertExactJson(['accepted' => 1, 'duplicates' => 0]);

    ($this->push)([moneyOverwriteRow(['local_id' => 7, 'total_amount_local' => 200])], $secondToken)
        ->assertStatus(202)->assertExactJson(['accepted' => 1, 'duplicates' => 0]);

    expect(OrderMoneyOverwrite::query()->count())->toBe(2)
        ->and(OrderMoneyOverwrite::query()->pluck('total_amount_local')->sort()->values()->all())
        ->toBe([100, 200]);
});

it('#2885 lô hỗn hợp 3 mới + 2 trùng ⇒ accepted=3, duplicates=2 và không dòng nào bị rơi', function () {
    ($this->push)([
        moneyOverwriteRow(['local_id' => 1]),
        moneyOverwriteRow(['local_id' => 2]),
    ])->assertStatus(202);

    $res = ($this->push)([
        moneyOverwriteRow(['local_id' => 1]),   // trùng
        moneyOverwriteRow(['local_id' => 3]),
        moneyOverwriteRow(['local_id' => 2]),   // trùng
        moneyOverwriteRow(['local_id' => 4]),
        moneyOverwriteRow(['local_id' => 5]),
    ]);

    // Bắt ngoại lệ quanh CẢ vòng lặp thay vì từng dòng sẽ cho accepted=1 ở đây
    // (dừng ở phần tử đầu) — đúng cái làm mất bằng chứng của những dòng đi sau
    // một dòng trùng.
    $res->assertStatus(202)->assertExactJson(['accepted' => 3, 'duplicates' => 2]);

    expect(OrderMoneyOverwrite::query()->pluck('local_id')->sort()->values()->all())
        ->toBe([1, 2, 3, 4, 5]);
});

it('#2885 lô toàn dòng trùng vẫn là 202, không phải lỗi', function () {
    // Máy trạm đánh dấu `synced_at` dựa trên phản hồi. Trả 4xx/5xx cho một lô
    // đã nằm sẵn ở Cloud sẽ làm nó đẩy lại mãi mãi.
    ($this->push)([moneyOverwriteRow(['local_id' => 9])])->assertStatus(202);

    ($this->push)([moneyOverwriteRow(['local_id' => 9])])
        ->assertStatus(202)->assertExactJson(['accepted' => 0, 'duplicates' => 1]);
});

it('#2885 trùng NGAY TRONG một request cũng đếm là duplicates, không tạo hai hàng', function () {
    $res = ($this->push)([
        moneyOverwriteRow(['local_id' => 42, 'total_amount_local' => 111]),
        moneyOverwriteRow(['local_id' => 42, 'total_amount_local' => 222]),
    ]);

    $res->assertStatus(202)->assertExactJson(['accepted' => 1, 'duplicates' => 1]);

    expect(OrderMoneyOverwrite::query()->sole()->total_amount_local)->toBe(111);
});

// ─────────────────────────────────────────────────────────────────────────────
//  BẤT BIẾN — không đường nào sửa/xoá dòng đã ghi
// ─────────────────────────────────────────────────────────────────────────────

it('#2885 dòng đã ghi KHÔNG sửa được và KHÔNG xoá được', function () {
    ($this->push)([moneyOverwriteRow()])->assertStatus(202);

    $row = OrderMoneyOverwrite::query()->sole();

    // Unique index chặn TẠO trùng, nhưng không chặn `updateOrCreate()` hay
    // `firstOrNew()->save()` mà một tính năng sau này có thể viết. Hai guard
    // trên model là thứ chặn chuyện đó.
    expect(fn () => $row->update(['total_amount_cloud' => 1]))->toThrow(LogicException::class);
    expect(fn () => $row->delete())->toThrow(LogicException::class);

    $fresh = OrderMoneyOverwrite::query()->sole();
    expect($fresh->total_amount_cloud)->toBe(297);
});

it('#2885 HTTP không phơi ra đường sửa/xoá nào cho money-overwrites', function () {
    $methods = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r): bool => str_contains($r->uri(), 'money-overwrites'))
        ->flatMap(fn ($r): array => $r->methods())
        ->unique()
        ->sort()
        ->values()
        ->all();

    // Chỉ POST (Laravel tự đăng ký HEAD kèm GET; ở đây không có GET nên danh
    // sách phải sạch). PUT/PATCH/DELETE xuất hiện = có ai đó vừa mở đường ghi
    // đè lên bằng chứng.
    expect($methods)->toBe(['POST']);
});

// ─────────────────────────────────────────────────────────────────────────────
//  AUTHZ / PHẠM VI
// ─────────────────────────────────────────────────────────────────────────────

it('#2885 không có token ⇒ 401', function () {
    $this->postJson(MONEY_OVERWRITE_URL, ['overwrites' => [moneyOverwriteRow()]])
        ->assertStatus(401);

    expect(OrderMoneyOverwrite::query()->count())->toBe(0);
});

it('#2885 token sai ⇒ 401', function () {
    ($this->push)([moneyOverwriteRow()], Str::random(64))->assertStatus(401);

    expect(OrderMoneyOverwrite::query()->count())->toBe(0);
});

it('#2885 thiết bị chưa gắn chi nhánh ⇒ 422, không phải 500', function () {
    // `devices.branch_id` là NOT NULL nên trạng thái này không dựng được bằng
    // factory — nhưng guard vẫn phải đúng, vì nó là thứ đứng giữa một thiết bị
    // ghép cặp dở dang và một dòng bằng chứng không quy được về đâu. Thay
    // `DeviceService` để middleware trả về đúng thiết bị đó.
    $orphan = Device::factory()->make([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'branch_id' => null,
    ]);

    $service = Mockery::mock(DeviceService::class);
    $service->shouldReceive('findByToken')->andReturn($orphan);
    $service->shouldReceive('heartbeat')->andReturnNull();
    $this->app->instance(DeviceService::class, $service);

    ($this->push)([moneyOverwriteRow()])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Thiết bị chưa gắn chi nhánh.');

    expect(OrderMoneyOverwrite::query()->count())->toBe(0);
});

it('#2885 chi nhánh không còn ⇒ 422', function () {
    $this->branch->delete();

    ($this->push)([moneyOverwriteRow()])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Chi nhánh không tồn tại.');

    expect(OrderMoneyOverwrite::query()->count())->toBe(0);
});

it('#2885 tổ chức chưa nhân bản về Tempo ⇒ 422 (#2847 — org suy qua mirror console)', function () {
    // `branches` KHÔNG có cột `organization_id`; đọc nhầm nó ra chuỗi rỗng đã
    // làm chết 7.523 alert máy trạm trong hai ngày. Ở đây chuỗi rỗng sẽ thành
    // một `organization_id` rỗng nằm trong bằng chứng kiểm toán — thà 422.
    $this->branch->update(['console_organization_id' => (string) Str::uuid()]);

    ($this->push)([moneyOverwriteRow()])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Tổ chức của chi nhánh chưa được nhân bản về Tempo.');

    expect(OrderMoneyOverwrite::query()->count())->toBe(0);
});

it('#2885 thiết bị của chi nhánh khác ghi vào CHI NHÁNH CỦA NÓ, không sang chi nhánh này', function () {
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $otherToken = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $otherToken,
        'organization_id' => $this->orgId,
        'branch_id' => $otherBranch->id,
    ]);

    // Payload KHÔNG mang branch_id — và kể cả có mang thì `validate()` cũng
    // strip nó. Đây là bài chứng minh phạm vi đến từ TOKEN.
    ($this->push)(
        [moneyOverwriteRow(['local_id' => 5, 'branch_id' => (string) $this->branch->id])],
        $otherToken,
    )->assertStatus(202);

    expect(OrderMoneyOverwrite::query()->sole()->branch_id)->toBe((string) $otherBranch->id);
    expect(OrderMoneyOverwrite::query()->where('branch_id', $this->branch->id)->count())->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────────
//  VALIDATION
// ─────────────────────────────────────────────────────────────────────────────

it('#2885 thiếu bất kỳ trường tiền nào ⇒ 422 và không ghi gì', function (string $missing) {
    $row = moneyOverwriteRow();
    unset($row[$missing]);

    ($this->push)([$row])
        ->assertStatus(422)
        ->assertJsonValidationErrors(["overwrites.0.{$missing}"]);

    expect(OrderMoneyOverwrite::query()->count())->toBe(0);
})->with(moneyOverwriteMoneyFields());

it('#2885 trường tiền không phải số nguyên ⇒ 422', function (mixed $bad) {
    ($this->push)([moneyOverwriteRow(['total_amount_local' => $bad])])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['overwrites.0.total_amount_local']);
})->with([
    // `"1190"` và `true` đều lọt qua luật `integer` của Laravel
    // (`filter_var(FILTER_VALIDATE_INT)`), và `true` đi tiếp vào `(int)` thành
    // **1** — một con số bịa nằm im trong sổ đối soát tiền. Đo được: bỏ luật
    // `strictlyInteger()` ra thì đúng hai ca này chuyển từ 422 sang 202.
    'chuỗi số' => ['1190'],
    'thập phân' => [1190.5],
    'null' => [null],
    'mảng' => [[1190]],
    'boolean true' => [true],
    'boolean false' => [false],
    'chuỗi rác' => ['một nghìn'],
]);

it('#2885 local_id cũng phải là số nguyên JSON thật', function (mixed $bad) {
    ($this->push)([moneyOverwriteRow(['local_id' => $bad])])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['overwrites.0.local_id']);
})->with([
    'chuỗi số' => ['123'],
    'boolean' => [true],
    'thập phân' => [1.5],
    'null' => [null],
]);

it('#2885 local_id ≤ 0 ⇒ 422 — SQLite đánh số từ 1', function (int $bad) {
    ($this->push)([moneyOverwriteRow(['local_id' => $bad])])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['overwrites.0.local_id']);
})->with([
    'không' => [0],
    'âm' => [-1],
]);

it('#2885 order_id không phải uuid ⇒ 422', function (mixed $bad) {
    ($this->push)([moneyOverwriteRow(['order_id' => $bad])])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['overwrites.0.order_id']);
})->with([
    'chuỗi thường' => ['ORD-2026-0018'],
    'uuid cụt' => ['019feb37-0000-7000-8000'],
    'rỗng' => [''],
    'số' => [12345],
]);

it('#2885 occurred_at sai định dạng ⇒ 422', function (mixed $bad) {
    ($this->push)([moneyOverwriteRow(['occurred_at' => $bad])])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['overwrites.0.occurred_at']);
})->with([
    'thiếu Z' => ['2026-08-13T07:01:33'],
    // Đây là ca đáng sợ nhất: một offset khác UTC lọt qua sẽ được diễn giải
    // thành một instant khác, và bằng chứng kiểm toán lệch 9 tiếng.
    'offset +09:00' => ['2026-08-13T07:01:33+09:00'],
    'giờ tường có dấu cách' => ['2026-08-13 07:01:33'],
    'chỉ có ngày' => ['2026-08-13'],
    'epoch' => [1786000000],
    'rác' => ['hôm qua'],
    'rỗng' => [''],
]);

it('#2885 mảng rỗng ⇒ 422', function () {
    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson(MONEY_OVERWRITE_URL, ['overwrites' => []])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['overwrites']);
});

it('#2885 thiếu hẳn khoá overwrites ⇒ 422', function () {
    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson(MONEY_OVERWRITE_URL, [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['overwrites']);
});

it('#2885 lô 51 dòng ⇒ 422; đúng 50 dòng vẫn qua', function () {
    $tooMany = [];
    for ($i = 1; $i <= 51; $i++) {
        $tooMany[] = moneyOverwriteRow(['local_id' => $i]);
    }

    ($this->push)($tooMany)->assertStatus(422)->assertJsonValidationErrors(['overwrites']);

    // Không dòng nào được ghi: `validate()` từ chối cả request, đúng như mong
    // muốn — một lô quá trần là lỗi của bên gửi, không phải dữ liệu bán phần.
    expect(OrderMoneyOverwrite::query()->count())->toBe(0);

    ($this->push)(array_slice($tooMany, 0, 50))
        ->assertStatus(202)
        ->assertExactJson(['accepted' => 50, 'duplicates' => 0]);
});

// ─────────────────────────────────────────────────────────────────────────────
//  THỜI GIAN — #1091 / docs/guide/business-time.md
// ─────────────────────────────────────────────────────────────────────────────

it('#2885 occurred_at lưu đúng INSTANT, không lệch theo timezone của chi nhánh', function (
    string $timezone,
) {
    // Đóng băng đồng hồ: `created_at` (lúc Cloud nhận) và `occurred_at` (lúc
    // ghi đè xảy ra trên thiết bị) là hai mốc khác nhau, và bài này phải chứng
    // minh cái thứ hai không bị đồng hồ máy chủ chạm vào.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-05T22:30:00Z'));

    $this->branch->update(['timezone' => $timezone]);

    ($this->push)([moneyOverwriteRow(['occurred_at' => '2026-08-13T07:01:33Z'])])
        ->assertStatus(202);

    // Đọc THÔ, không qua cast của Eloquent: cast có thể che một cột lưu sai
    // bằng cách diễn giải ngược cùng một timezone.
    $stored = DB::table('order_money_overwrites')->value('occurred_at');

    expect(substr((string) $stored, 0, 19))->toBe('2026-08-13 07:01:33');

    expect(OrderMoneyOverwrite::query()->sole()->occurred_at->toIso8601ZuluString())
        ->toBe('2026-08-13T07:01:33Z');

    CarbonImmutable::setTestNow();
})->with([
    'Tokyo (+9)' => ['Asia/Tokyo'],
    'Ho Chi Minh (+7)' => ['Asia/Ho_Chi_Minh'],
    'UTC' => ['UTC'],
    'Los Angeles (-7)' => ['America/Los_Angeles'],
]);

it('#2885 created_at là lúc CLOUD nhận, tách rời occurred_at của thiết bị', function () {
    // Khoảng lệch giữa hai mốc đúng bằng thời gian quán mất mạng — tự nó là dữ
    // liệu. Gộp hai mốc làm một sẽ xoá mất điều đó.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-15T03:00:00Z'));

    ($this->push)([moneyOverwriteRow(['occurred_at' => '2026-08-13T07:01:33Z'])])
        ->assertStatus(202);

    $row = OrderMoneyOverwrite::query()->sole();

    expect($row->occurred_at->toIso8601ZuluString())->toBe('2026-08-13T07:01:33Z')
        ->and($row->created_at->toIso8601ZuluString())->toBe('2026-08-15T03:00:00Z');

    CarbonImmutable::setTestNow();
});

it('#2885 occurred_at có phần giây lẻ vẫn nhận', function () {
    // Thư viện thời gian ở đầu kia có thể phát ra mili/micro giây. Từ chối nó
    // sẽ làm cả một fleet không đẩy được bằng chứng lên, mà lỗi thì im lặng —
    // máy trạm fail-open theo #2695.
    ($this->push)([moneyOverwriteRow(['occurred_at' => '2026-08-13T07:01:33.250Z'])])
        ->assertStatus(202);

    expect(OrderMoneyOverwrite::query()->sole()->occurred_at->toIso8601ZuluString())
        ->toBe('2026-08-13T07:01:33Z');
});
