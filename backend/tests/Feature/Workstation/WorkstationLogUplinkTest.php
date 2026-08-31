<?php

declare(strict_types=1);

/**
 * #2901 — log máy trạm đi từ quán lên Cloud theo cơ chế KÉO THEO YÊU CẦU.
 *
 * Đây là đường chở **PII khách** qua một ranh giới hệ thống mới, nên mọi bài
 * dưới đây đi QUA endpoint HTTP, không gọi thẳng vào tầng service. Bài học
 * #2622: `$request->validate()` strip mọi khoá không có rule, nên một trường
 * có thể đi hết đường service mà không bao giờ nhận được giá trị từ thiết bị —
 * và **mọi test service-level vẫn xanh** trong khi tính năng chết im lặng trên
 * đường thật.
 *
 * Bốn bất biến nặng nhất, theo thứ tự:
 *
 *   1. `level: "debug"` ⇒ **422 cả lô**. Chốt "info trở lên" phải cưỡng chế
 *      được ở Cloud; không tin đầu kia lọc đúng.
 *   2. Allowlist là **fail-closed** và Cloud kiểm LẠI. Message lạ ⇒ bỏ dòng,
 *      `rejected++`, lô vẫn 202. Attr lạ ⇒ bỏ attr, giữ dòng.
 *   3. `(device_id, local_id)` là duy nhất **ở tầng DB**. Không có bài chèn
 *      thô thì "idempotent" chỉ là lời hứa của code.
 *   4. `logged_at` phải là UTC có hậu tố `Z` — hạn giữ 14 ngày ĐẾM THEO cột
 *      này, nên một dòng lệch 9 tiếng là một dòng sống sai hạn.
 */

use App\Models\Branch;
use App\Models\Device;
use App\Models\Organization;
use App\Models\WorkstationLogRecord;
use App\Models\WorkstationLogRequest;
use App\Omnify\Enums\WorkstationLogRequestStatusEnum;
use App\Services\Device\DeviceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

const WS_LOG_RECORDS_URL = '/api/v1/workstation/log-records';
const WS_LOG_REQUESTS_URL = '/api/v1/workstation/log-requests';

/**
 * Một dòng đúng hợp đồng, mang một `message` CÓ THẬT trong allowlist.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function wsLogRow(array $overrides = []): array
{
    return array_merge([
        'local_id' => 8123,
        'logged_at' => '2026-08-16T03:14:43Z',
        'level' => 'warn',
        'message' => 'sync push failed',
        'attrs' => ['id' => 'row-1', 'entity' => 'payment', 'retryable' => false],
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

    $this->logRequest = WorkstationLogRequest::factory()->create([
        'device_id' => $this->device->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);

    $this->push = fn (array $rows, array $overrides = [], ?string $token = null) => $this
        ->withHeaders(['Authorization' => 'Bearer '.($token ?? $this->wsToken)])
        ->postJson(WS_LOG_RECORDS_URL, array_merge([
            'request_id' => (string) $this->logRequest->id,
            'final' => false,
            'records' => $rows,
        ], $overrides));

    $this->pull = fn (?string $token = null) => $this
        ->withHeaders(['Authorization' => 'Bearer '.($token ?? $this->wsToken)])
        ->getJson(WS_LOG_REQUESTS_URL);
});

// ─────────────────────────────────────────────────────────────────────────────
//  YÊU CẦU TREO — máy trạm hỏi "có gì cho tôi không"
// ─────────────────────────────────────────────────────────────────────────────

it('#2901 GET log-requests trả yêu cầu treo của CHÍNH thiết bị, đúng hình dạng wire', function () {
    $res = ($this->pull)();

    $res->assertOk()
        ->assertJsonCount(1, 'requests')
        ->assertJsonPath('requests.0.id', (string) $this->logRequest->id)
        ->assertJsonPath('requests.0.max_records', 2000);

    // `from`/`to` phải là RFC3339 UTC có hậu tố `Z` — cột tên `window_from`/
    // `window_to` (từ khoá SQL), nhưng wire thì không được đổi.
    expect($res->json('requests.0.from'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and($res->json('requests.0.to'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
});

it('#2901 KHÔNG có yêu cầu nào ⇒ danh sách RỖNG, và đó là ca THƯỜNG (200, không 404)', function () {
    $this->logRequest->delete();

    ($this->pull)()->assertOk()->assertExactJson(['requests' => []]);
});

it('#2901 yêu cầu của thiết bị KHÁC không bao giờ hiện ra', function () {
    $otherToken = Str::random(64);
    $other = Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $otherToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    // Máy thứ hai KHÔNG được thấy yêu cầu của máy thứ nhất. Đây là hàng rào
    // cách ly duy nhất trên đường này.
    $this->withHeaders(['Authorization' => 'Bearer '.$otherToken])
        ->getJson(WS_LOG_REQUESTS_URL)
        ->assertOk()
        ->assertExactJson(['requests' => []]);

    expect((string) $other->id)->not->toBe((string) $this->device->id);
});

it('#2901 yêu cầu ĐÃ ĐÓNG hoặc ĐÃ HẾT HẠN không được trao đi nữa', function () {
    $this->logRequest->update(['status' => WorkstationLogRequestStatusEnum::Fulfilled->value]);
    ($this->pull)()->assertOk()->assertExactJson(['requests' => []]);

    // Còn `pending` nhưng đồng hồ đã vượt hạn: khoảng giữa hai lượt quét đánh
    // dấu. Đúng đắn KHÔNG được phụ thuộc vào việc một cron có chạy đúng giờ.
    $this->logRequest->update([
        'status' => WorkstationLogRequestStatusEnum::Pending->value,
        'expires_at' => CarbonImmutable::now('UTC')->subHour(),
    ]);
    ($this->pull)()->assertOk()->assertExactJson(['requests' => []]);
});

it('#2901 GET log-requests không token ⇒ 401', function () {
    $this->getJson(WS_LOG_REQUESTS_URL)->assertStatus(401);
});

// ─────────────────────────────────────────────────────────────────────────────
//  MỨC LOG — "info trở lên" phải cưỡng chế được ở CLOUD
// ─────────────────────────────────────────────────────────────────────────────

it('#2901 level "debug" ⇒ 422 CẢ LÔ, không lưu dòng nào', function () {
    // Khác hẳn message lạ (bỏ một dòng): một dòng `debug` tới nơi nghĩa là bộ
    // lọc ở NGUỒN đã hỏng, nên mọi dòng khác trong lô cũng đáng ngờ.
    ($this->push)([wsLogRow(['level' => 'debug'])])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['records.0.level']);

    expect(WorkstationLogRecord::query()->count())->toBe(0);
});

it('#2901 một dòng debug làm rơi cả lô, kể cả khi những dòng khác hợp lệ', function () {
    ($this->push)([
        wsLogRow(['local_id' => 1]),
        wsLogRow(['local_id' => 2, 'level' => 'debug']),
        wsLogRow(['local_id' => 3]),
    ])->assertStatus(422);

    expect(WorkstationLogRecord::query()->count())->toBe(0);
});

it('#2901 ba mức hợp lệ info/warn/error đều đi qua', function () {
    ($this->push)([
        wsLogRow(['local_id' => 1, 'level' => 'info', 'message' => 'sync engine started', 'attrs' => []]),
        wsLogRow(['local_id' => 2, 'level' => 'warn']),
        wsLogRow(['local_id' => 3, 'level' => 'error', 'message' => 'sync: invalid payload', 'attrs' => ['key' => 'order.create']]),
    ])->assertStatus(202)->assertJsonPath('accepted', 3);

    expect(WorkstationLogRecord::query()->pluck('level')->map(fn ($l) => $l->value)->sort()->values()->all())
        ->toBe(['error', 'info', 'warn']);
});

// ─────────────────────────────────────────────────────────────────────────────
//  ALLOWLIST — Cloud kiểm LẠI, không tin máy trạm đã lọc đúng
// ─────────────────────────────────────────────────────────────────────────────

it('#2901 message NGOÀI allowlist ⇒ dòng bị bỏ, rejected đếm đúng, lô vẫn 202', function () {
    $res = ($this->push)([
        wsLogRow(['local_id' => 1]),
        wsLogRow(['local_id' => 2, 'message' => 'customer checked out', 'attrs' => ['name' => 'Nguyễn Văn A']]),
        wsLogRow(['local_id' => 3]),
    ]);

    // Cả lô KHÔNG bị 422: một dòng chưa ai khai không được làm rơi những dòng
    // đã khai đi cùng lô.
    $res->assertStatus(202)
        ->assertJsonPath('accepted', 2)
        ->assertJsonPath('rejected', 1)
        ->assertJsonPath('duplicates', 0);

    expect(WorkstationLogRecord::query()->pluck('local_id')->sort()->values()->all())->toBe([1, 3]);

    // Và dòng bị bỏ KHÔNG để lại mảnh nào — kể cả attr PII của nó.
    expect(WorkstationLogRecord::query()->where('message', 'customer checked out')->exists())->toBeFalse();
});

it('#2901 rejected cộng dồn lên chính YÊU CẦU — không có con số này thì lỗ hổng vô hình', function () {
    ($this->push)([
        wsLogRow(['local_id' => 1, 'message' => 'chưa ai khai cái này']),
        wsLogRow(['local_id' => 2, 'message' => 'cũng chưa ai khai']),
        wsLogRow(['local_id' => 3]),
    ])->assertStatus(202);

    $this->logRequest->refresh();

    expect($this->logRequest->rejected_count)->toBe(2)
        ->and($this->logRequest->received_count)->toBe(1);
});

it('#2901 attr NGOÀI allowlist ⇒ bỏ ATTR, dòng vẫn lưu', function () {
    ($this->push)([wsLogRow([
        'attrs' => [
            'id' => 'row-1',
            'entity' => 'payment',
            'retryable' => true,
            // Ba trường KHÔNG khai cho message này. `err` là trường hay gặp
            // nhất trên cây Go và cố ý KHÔNG bao giờ được khai — chuỗi lỗi là
            // văn bản tự do có thể chở nguyên thân phản hồi HTTP.
            'err' => 'dial tcp: lookup api.tempo.jp: no such host',
            'customer_name' => 'Nguyễn Văn A',
            'phone' => '090-1234-5678',
        ],
    ])])->assertStatus(202)->assertJsonPath('accepted', 1);

    $row = WorkstationLogRecord::query()->sole();

    expect($row->attrs)->toBe(['id' => 'row-1', 'entity' => 'payment', 'retryable' => true])
        ->and($row->attrs)->not->toHaveKey('err')
        ->and($row->attrs)->not->toHaveKey('customer_name')
        ->and($row->attrs)->not->toHaveKey('phone');
});

it('#2901 attr đúng tên nhưng thuộc message KHÁC vẫn bị bỏ — allowlist theo TỪNG message', function () {
    // `order_code` được phép trên hai message in ấn, KHÔNG được phép trên
    // `sync push failed`. Một allowlist phẳng theo tên attr sẽ để lọt.
    ($this->push)([wsLogRow(['attrs' => ['id' => 'row-1', 'order_code' => 'A-0007']])])
        ->assertStatus(202);

    expect(WorkstationLogRecord::query()->sole()->attrs)->toBe(['id' => 'row-1']);
});

it('#2901 message hợp lệ nhưng KHÔNG attr nào khớp ⇒ attrs là NULL, không phải object rỗng', function () {
    ($this->push)([wsLogRow([
        'message' => 'sync engine started',
        'attrs' => ['whatever' => 1],
    ])])->assertStatus(202);

    expect(WorkstationLogRecord::query()->sole()->attrs)->toBeNull();
});

it('#2901 thiếu hẳn attrs không phải lỗi', function () {
    $row = wsLogRow();
    unset($row['attrs']);

    ($this->push)([$row])->assertStatus(202)->assertJsonPath('accepted', 1);

    expect(WorkstationLogRecord::query()->sole()->attrs)->toBeNull();
});

it('#2901 lô toàn message lạ vẫn là 202 — máy trạm không được đẩy lại mãi mãi', function () {
    ($this->push)([
        wsLogRow(['local_id' => 1, 'message' => 'lạ 1']),
        wsLogRow(['local_id' => 2, 'message' => 'lạ 2']),
    ])->assertStatus(202)
        ->assertJsonPath('accepted', 0)
        ->assertJsonPath('rejected', 2);

    expect(WorkstationLogRecord::query()->count())->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────────
//  IDEMPOTENCY / REPLAY
// ─────────────────────────────────────────────────────────────────────────────

it('#2901 đẩy lại Y HỆT ⇒ duplicates++, KHÔNG thêm hàng', function () {
    ($this->push)([wsLogRow()])->assertStatus(202)
        ->assertJsonPath('accepted', 1)->assertJsonPath('duplicates', 0);

    ($this->push)([wsLogRow()])->assertStatus(202)
        ->assertJsonPath('accepted', 0)->assertJsonPath('duplicates', 1);

    expect(WorkstationLogRecord::query()->count())->toBe(1);
});

it('#2901 đẩy lại cùng khoá nhưng NỘI DUNG KHÁC ⇒ KHÔNG ghi đè, hàng cũ còn nguyên', function () {
    ($this->push)([wsLogRow()])->assertStatus(202);

    ($this->push)([wsLogRow([
        'level' => 'error',
        'message' => 'sync row dead-lettered',
        'logged_at' => '2027-01-01T00:00:00Z',
        'attrs' => ['id' => 'row-999', 'reason' => 'attempts exhausted'],
    ])])->assertStatus(202)->assertJsonPath('duplicates', 1);

    $row = WorkstationLogRecord::query()->sole();

    expect($row->message)->toBe('sync push failed')
        ->and($row->level->value)->toBe('warn')
        ->and($row->logged_at->toIso8601ZuluString())->toBe('2026-08-16T03:14:43Z')
        ->and($row->attrs)->toBe(['id' => 'row-1', 'entity' => 'payment', 'retryable' => false]);
});

it('#2901 unique index TỒN TẠI Ở TẦNG DB — chèn thô bỏ qua tầng ứng dụng phải ném', function () {
    ($this->push)([wsLogRow()])->assertStatus(202);

    $raw = [
        'id' => (string) Str::uuid(),
        'device_id' => (string) $this->device->id,
        'local_id' => 8123,            // ← trùng cặp khoá với dòng vừa ghi
        'branch_id' => (string) $this->branch->id,
        'organization_id' => $this->orgId,
        'request_id' => (string) $this->logRequest->id,
        'logged_at' => '2026-08-16 03:14:43',
        'level' => 'info',
        'message' => 'sync engine started',
        'attrs' => null,
    ];

    // `DB::table()` không đi qua Eloquent, không qua model guard, không qua
    // controller. Nếu bài này KHÔNG ném thì thứ duy nhất chống trùng là một
    // câu `catch` trong PHP — và hai lô song song sẽ lách qua nó.
    expect(fn () => DB::table('workstation_log_records')->insert($raw))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(WorkstationLogRecord::query()->count())->toBe(1);
});

it('#2901 index đó phải là UNIQUE và phủ đúng (device_id, local_id)', function () {
    // Bài trên chứng minh "có gì đó chặn". Bài này chứng minh thứ chặn là đúng
    // cặp cột đã chốt trong hợp đồng — chứ không phải một unique nào khác vô
    // tình trùng.
    $unique = collect(Schema::getIndexes('workstation_log_records'))
        ->filter(fn (array $i): bool => (bool) $i['unique'])
        ->map(fn (array $i): array => array_map('strtolower', $i['columns']))
        ->values()
        ->all();

    expect($unique)->toContain(['device_id', 'local_id']);
});

it('#2901 hai THIẾT BỊ khác nhau cùng local_id ⇒ HAI hàng', function () {
    // `local_id` là autoincrement CỤC BỘ, nên hai máy trạm phát ra cùng những
    // con số 1, 2, 3… Khoá thiếu vế `device_id` sẽ làm máy thứ hai bị coi là
    // trùng và log của nó biến mất trong im lặng.
    $secondToken = Str::random(64);
    $second = Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $secondToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $secondRequest = WorkstationLogRequest::factory()->create([
        'device_id' => $second->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);

    ($this->push)([wsLogRow(['local_id' => 7])])->assertStatus(202)->assertJsonPath('accepted', 1);

    $this->withHeaders(['Authorization' => 'Bearer '.$secondToken])
        ->postJson(WS_LOG_RECORDS_URL, [
            'request_id' => (string) $secondRequest->id,
            'final' => false,
            'records' => [wsLogRow(['local_id' => 7, 'message' => 'sync engine stopped', 'attrs' => []])],
        ])->assertStatus(202)->assertJsonPath('accepted', 1);

    expect(WorkstationLogRecord::query()->count())->toBe(2)
        ->and(WorkstationLogRecord::query()->pluck('device_id')->unique()->count())->toBe(2);
});

it('#2901 dòng đã ghi KHÔNG sửa được (nhưng XOÁ được — hạn giữ 14 ngày cần nó)', function () {
    ($this->push)([wsLogRow()])->assertStatus(202);

    $row = WorkstationLogRecord::query()->sole();

    expect(fn () => $row->update(['message' => 'sync engine started']))->toThrow(LogicException::class);

    // Khác #2885: bảng kia là bằng chứng tiền, append-only vĩnh viễn. Bảng này
    // chở PII và có hạn 14 ngày — chặn xoá ở đây sẽ biến một cam kết về quyền
    // riêng tư thành một exception hằng đêm.
    expect(fn () => $row->delete())->not->toThrow(LogicException::class);
    expect(WorkstationLogRecord::query()->count())->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────────
//  request_id — 422 và 404 nói HAI chuyện khác nhau
// ─────────────────────────────────────────────────────────────────────────────

it('#2901 request_id của thiết bị KHÁC ⇒ 422 (lỗi của người gọi, không phải hàng thiếu)', function () {
    $otherToken = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $otherToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    // Trả 404 ở đây sẽ dạy máy trạm "im lặng bỏ qua" đúng cái ca đáng phải sửa.
    $this->withHeaders(['Authorization' => 'Bearer '.$otherToken])
        ->postJson(WS_LOG_RECORDS_URL, [
            'request_id' => (string) $this->logRequest->id,
            'final' => true,
            'records' => [wsLogRow()],
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Yêu cầu này không thuộc thiết bị đang gọi.');

    expect(WorkstationLogRecord::query()->count())->toBe(0);
});

it('#2901 request_id không tồn tại ⇒ 404', function () {
    ($this->push)([wsLogRow()], ['request_id' => (string) Str::uuid()])->assertStatus(404);

    expect(WorkstationLogRecord::query()->count())->toBe(0);
});

it('#2901 yêu cầu ĐÃ ĐÓNG ⇒ 404 — máy trạm coi là "thôi, bỏ qua", KHÔNG alert', function () {
    $this->logRequest->update([
        'status' => WorkstationLogRequestStatusEnum::Fulfilled->value,
        'fulfilled_at' => CarbonImmutable::now('UTC'),
    ]);

    ($this->push)([wsLogRow()])->assertStatus(404);

    expect(WorkstationLogRecord::query()->count())->toBe(0);
});

it('#2901 yêu cầu ĐÃ HẾT HẠN ⇒ 404, kể cả khi cột status vẫn còn pending', function () {
    // Hết hạn trong lúc máy trạm đang lọc là chuyện bình thường, không phải sự
    // cố — và đúng đắn không được phụ thuộc vào lượt quét đánh dấu.
    $this->logRequest->update(['expires_at' => CarbonImmutable::now('UTC')->subMinute()]);

    ($this->push)([wsLogRow()])->assertStatus(404);

    expect(WorkstationLogRecord::query()->count())->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────────
//  ĐÓNG YÊU CẦU
// ─────────────────────────────────────────────────────────────────────────────

it('#2901 final=false giữ yêu cầu MỞ; final=true đóng nó và đóng dấu fulfilled_at', function () {
    ($this->push)([wsLogRow(['local_id' => 1])], ['final' => false])->assertStatus(202);

    $this->logRequest->refresh();
    expect($this->logRequest->status)->toBe(WorkstationLogRequestStatusEnum::Pending)
        ->and($this->logRequest->fulfilled_at)->toBeNull()
        ->and($this->logRequest->received_count)->toBe(1);

    ($this->push)([wsLogRow(['local_id' => 2])], ['final' => true])->assertStatus(202);

    $this->logRequest->refresh();
    expect($this->logRequest->status)->toBe(WorkstationLogRequestStatusEnum::Fulfilled)
        ->and($this->logRequest->fulfilled_at)->not->toBeNull()
        ->and($this->logRequest->received_count)->toBe(2);
});

it('#2901 fulfilled với 0 dòng là một KHẲNG ĐỊNH, không phải im lặng', function () {
    // Bẫy mẫu số bằng không mà issue nêu đích danh: `fulfilled` + 0 nghĩa là
    // "khoảng đó không có dòng nào qua allowlist"; `expired` thì không khẳng
    // định gì. Gộp hai thứ đó là đọc "không có log" thành "không có sự cố".
    ($this->push)([wsLogRow(['message' => 'chưa ai khai'])], ['final' => true])
        ->assertStatus(202);

    $this->logRequest->refresh();

    expect($this->logRequest->status)->toBe(WorkstationLogRequestStatusEnum::Fulfilled)
        ->and($this->logRequest->received_count)->toBe(0)
        ->and($this->logRequest->rejected_count)->toBe(1)
        ->and($this->logRequest->fulfilled_at)->not->toBeNull();
});

it('#2901 trần max_records của yêu cầu được cưỡng chế ở CLOUD, không chỉ ở máy trạm', function () {
    $this->logRequest->update(['max_records' => 2]);

    $res = ($this->push)([
        wsLogRow(['local_id' => 1]),
        wsLogRow(['local_id' => 2]),
        wsLogRow(['local_id' => 3]),
        wsLogRow(['local_id' => 4]),
    ]);

    $res->assertStatus(202)
        ->assertJsonPath('accepted', 2)
        // Tách khỏi `rejected` vì hai con số đòi hai hành động khác nhau: một
        // cái là mở rộng allowlist, cái kia là thu hẹp khoảng thời gian hỏi.
        ->assertJsonPath('over_limit', 2);

    expect(WorkstationLogRecord::query()->count())->toBe(2);

    // Chạm trần thì yêu cầu tự đóng — để máy trạm thôi gửi tiếp.
    $this->logRequest->refresh();
    expect($this->logRequest->status)->toBe(WorkstationLogRequestStatusEnum::Fulfilled);
});

// ─────────────────────────────────────────────────────────────────────────────
//  THỜI GIAN — #1091
// ─────────────────────────────────────────────────────────────────────────────

it('#2901 logged_at mang offset +09:00 ⇒ 422', function () {
    // Chấp nhận offset nghe rộng lượng, nhưng nó mở đúng cửa mà #1091 đóng —
    // và ở đây còn đắt hơn chỗ khác: hạn giữ 14 ngày ĐẾM THEO cột này.
    ($this->push)([wsLogRow(['logged_at' => '2026-08-16T12:14:43+09:00'])])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['records.0.logged_at']);

    expect(WorkstationLogRecord::query()->count())->toBe(0);
});

it('#2901 logged_at không có múi giờ nào ⇒ 422', function () {
    ($this->push)([wsLogRow(['logged_at' => '2026-08-16 03:14:43'])])->assertStatus(422);
    ($this->push)([wsLogRow(['logged_at' => '2026-08-16T03:14:43'])])->assertStatus(422);

    expect(WorkstationLogRecord::query()->count())->toBe(0);
});

it('#2901 instant lưu đúng, không phụ thuộc app.timezone của tiến trình', function () {
    // Đóng băng đồng hồ VÀ đổi timezone tiến trình: nếu cột phụ thuộc
    // `app.timezone` thì con số đọc lên sẽ lệch 9 tiếng ở đây.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-16T09:00:00Z'));
    config(['app.timezone' => 'Asia/Tokyo']);

    ($this->push)([wsLogRow(['logged_at' => '2026-08-16T03:14:43Z'])])->assertStatus(202);

    expect(WorkstationLogRecord::query()->sole()->logged_at->toIso8601ZuluString())
        ->toBe('2026-08-16T03:14:43Z');

    CarbonImmutable::setTestNow();
});

it('#2901 nhận cả phần giây lẻ (thư viện thời gian ở đầu kia có thể phát ra nó)', function () {
    ($this->push)([wsLogRow(['logged_at' => '2026-08-16T03:14:43.512Z'])])->assertStatus(202);

    expect(WorkstationLogRecord::query()->sole()->logged_at->toIso8601ZuluString())
        ->toBe('2026-08-16T03:14:43Z');
});

// ─────────────────────────────────────────────────────────────────────────────
//  HÌNH DẠNG PAYLOAD
// ─────────────────────────────────────────────────────────────────────────────

it('#2901 local_id là chuỗi hay boolean ⇒ 422, không âm thầm ép về 1', function () {
    // `filter_var(FILTER_VALIDATE_INT)` của Laravel nhận cả `"8123"` lẫn
    // `true`, và `true` đi tiếp vào `(int)` thành **1** — tức mọi dòng hỏng
    // cùng đâm vào một khoá idempotency.
    ($this->push)([wsLogRow(['local_id' => '8123'])])->assertStatus(422);
    ($this->push)([wsLogRow(['local_id' => true])])->assertStatus(422);
    ($this->push)([wsLogRow(['local_id' => 0])])->assertStatus(422);

    expect(WorkstationLogRecord::query()->count())->toBe(0);
});

it('#2901 lô rỗng ⇒ 422; lô vượt trần ⇒ 422', function () {
    ($this->push)([])->assertStatus(422);

    $tooMany = [];
    for ($i = 1; $i <= (int) config('workstation_logs.batch_max') + 1; $i++) {
        $tooMany[] = wsLogRow(['local_id' => $i]);
    }

    ($this->push)($tooMany)->assertStatus(422);

    expect(WorkstationLogRecord::query()->count())->toBe(0);
});

it('#2901 thiếu `final` ⇒ 422 — Cloud không được đoán có nên đóng yêu cầu hay không', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.$this->wsToken])
        ->postJson(WS_LOG_RECORDS_URL, [
            'request_id' => (string) $this->logRequest->id,
            'records' => [wsLogRow()],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['final']);
});

it('#2901 message dài quá cột (255) ⇒ 422', function () {
    ($this->push)([wsLogRow(['message' => str_repeat('x', 256)])])->assertStatus(422);
});

// ─────────────────────────────────────────────────────────────────────────────
//  AUTHZ / PHẠM VI
// ─────────────────────────────────────────────────────────────────────────────

it('#2901 không token ⇒ 401; token sai ⇒ 401', function () {
    $this->postJson(WS_LOG_RECORDS_URL, [
        'request_id' => (string) $this->logRequest->id,
        'final' => true,
        'records' => [wsLogRow()],
    ])->assertStatus(401);

    ($this->push)([wsLogRow()], [], Str::random(64))->assertStatus(401);

    expect(WorkstationLogRecord::query()->count())->toBe(0);
});

it('#2901 ba trường quy-về-đâu lấy từ TOKEN, không từ payload', function () {
    ($this->push)([wsLogRow()])->assertStatus(202);

    $row = WorkstationLogRecord::query()->sole();

    expect($row->device_id)->toBe((string) $this->device->id)
        ->and($row->branch_id)->toBe((string) $this->branch->id)
        ->and($row->organization_id)->toBe($this->orgId)
        ->and($row->request_id)->toBe((string) $this->logRequest->id);
});

it('#2901 thiết bị chưa gắn chi nhánh ⇒ 422, không phải 500', function () {
    // `devices.branch_id` là NOT NULL nên trạng thái này không dựng được bằng
    // factory — nhưng guard vẫn phải đúng.
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

    ($this->push)([wsLogRow()])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Thiết bị chưa gắn chi nhánh.');

    ($this->pull)()
        ->assertStatus(422)
        ->assertJsonPath('message', 'Thiết bị chưa gắn chi nhánh.');
});

it('#2901 tổ chức chưa nhân bản về Tempo ⇒ 422 (#2847 — org suy qua mirror console)', function () {
    // `branches` KHÔNG có cột `organization_id`; đọc nhầm nó ra chuỗi rỗng đã
    // làm chết 7.523 alert máy trạm trong hai ngày.
    $this->branch->update(['console_organization_id' => (string) Str::uuid()]);

    ($this->push)([wsLogRow()])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Tổ chức của chi nhánh chưa được nhân bản về Tempo.');

    expect(WorkstationLogRecord::query()->count())->toBe(0);
});

it('#2901 đường máy trạm KHÔNG phơi ra method sửa/xoá nào', function () {
    $methods = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r): bool => str_contains($r->uri(), 'workstation/log-'))
        ->flatMap(fn ($r): array => $r->methods())
        ->unique()
        ->sort()
        ->values()
        ->all();

    // GET (+ HEAD do Laravel tự thêm) cho `log-requests`, POST cho
    // `log-records`. PUT/PATCH/DELETE xuất hiện = có ai vừa biến đường chẩn
    // đoán này thành một bề mặt ghi.
    expect($methods)->toBe(['GET', 'HEAD', 'POST']);
});
