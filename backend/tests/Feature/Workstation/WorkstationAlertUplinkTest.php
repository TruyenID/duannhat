<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Organization;
use App\Modules\Notifications\Contracts\NotificationDispatcher;
use App\Modules\Notifications\Contracts\NotificationRequest;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
 * #1806 S3 — alert của máy trạm đi lên HQ.
 *
 * Thay `NotificationDispatcher` bằng bản giả có chủ đích: nền tảng thông báo đã
 * có test riêng, thứ CHƯA được ghim là hợp đồng của endpoint này — nó gửi cái
 * gì, khử trùng theo gì, và nó có chịu được audience rỗng không.
 */

final class FakeUplinkDispatcher implements NotificationDispatcher
{
    /** @var list<NotificationRequest> */
    public array $sent = [];

    public bool $throw = false;

    public function toRole(NotificationRequest $request, string|array $role, string $scopeKey, string $scopeId, Brand $brand): string
    {
        if ($this->throw) {
            throw new RuntimeException('audience rỗng');
        }

        $this->sent[] = $request;

        return 'n-'.count($this->sent);
    }

    /** @param iterable<Model> $recipients */
    public function toRecipients(NotificationRequest $request, iterable $recipients): string
    {
        return 'unused';
    }

    public function coversEmitter(string $modelAlias, string $triggerEvent, string $organizationId): bool
    {
        return true;
    }
}

beforeEach(function () {
    $this->fake = new FakeUplinkDispatcher;
    $this->app->instance(NotificationDispatcher::class, $this->fake);

    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->wsToken = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->push = fn (array $alerts) => $this
        ->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/alerts', ['alerts' => $alerts]);
});

it('#1806 S3 nhận alert và gửi qua nền tảng thông báo', function () {
    $res = ($this->push)([[
        'kind' => 'no_printer',
        'subject' => 'receipt_printer',
        'severity' => 'critical',
        'title' => 'Chưa cấu hình máy in hoá đơn',
        'count' => 12,
    ]])->assertStatus(202);

    expect($res->json('accepted'))->toBe(1)
        ->and($this->fake->sent)->toHaveCount(1);

    $req = $this->fake->sent[0];

    expect($req->type)->toBe('workstation.alert')
        ->and($req->params['kind'])->toBe('no_printer')
        // Số lần xảy ra phải đi lên: HQ cần phân biệt một sự cố thoáng qua với
        // một sự cố đang diễn ra suốt buổi.
        ->and($req->params['count'])->toBe(12)
        // Tên quán phải có sẵn trong nội dung — HQ nhìn nhiều quán, một alert
        // không nói rõ quán nào thì không hành động được.
        ->and($req->params['branch_name'])->toBe($this->branch->name);
});

it('#1806 S3 khoá chặn lặp mang NGÀY — máy trạm đẩy theo tick', function () {
    // Không có ngày trong khoá thì mỗi vòng đồng bộ tạo một thông báo mới, và
    // HQ nhận vài trăm dòng giống hệt nhau mỗi ngày cho MỘT sự cố.
    $alert = [[
        'kind' => 'no_printer',
        'subject' => 'receipt_printer',
        'severity' => 'critical',
        'title' => 'Chưa cấu hình máy in hoá đơn',
    ]];

    ($this->push)($alert)->assertStatus(202);
    ($this->push)($alert)->assertStatus(202);

    $keys = array_map(static fn (NotificationRequest $r): ?string => $r->idempotencyKey, $this->fake->sent);

    expect($keys[0])->toBe($keys[1])
        // Ngày trong khoá là BUSINESS DATE của branch (#1091), không phải ngày
        // UTC của app — hai thứ lệch nhau trong cửa sổ 00:00–09:00 JST và
        // now()->toDateString() ở đây từng làm test đỏ đúng khung giờ đó.
        ->and($keys[0])->toContain(BusinessClock::businessDate((string) $this->branch->id));

    // #2890 — branch id nay nằm TRONG phần băm, không còn đọc thẳng ra được.
    // Nên tính chất phải ghim là "khoá PHÂN BIỆT theo chi nhánh", chứ không
    // phải "khoá CHỨA chuỗi id". Bản trước ghim vế thứ hai, và vế đó chỉ đúng
    // với một cách cài đặt cụ thể — nó đỏ ngay khi cách cài đặt đổi dù tính
    // chất thật vẫn còn nguyên.
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
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

    $this->withHeaders(['Authorization' => "Bearer {$otherToken}"])
        ->postJson('/api/v1/workstation/alerts', ['alerts' => $alert])
        ->assertStatus(202);

    expect($this->fake->sent[2]->idempotencyKey)->not->toBe($keys[0]);
});

it('#1806 S3 severity critical thành ưu tiên urgent', function () {
    ($this->push)([
        ['kind' => 'cash_retained', 'subject' => 'tx-1', 'severity' => 'critical', 'title' => 'Tiền kẹt'],
        ['kind' => 'sync_stalled', 'subject' => 'orders', 'severity' => 'warning', 'title' => 'Đồng bộ kẹt'],
    ])->assertStatus(202);

    expect($this->fake->sent[0]->priority)->toBe('urgent')
        ->and($this->fake->sent[1]->priority)->toBe('high');
});

it('#1806 S3 gửi hỏng KHÔNG làm hỏng vòng đồng bộ của máy trạm', function () {
    // Audience rỗng (chưa ai giữ role ở tổ chức đó) ném ngoại lệ. Trả 5xx ở đây
    // sẽ khiến máy trạm coi cả lượt sync là thất bại và thử lại mãi — vì một
    // cảnh báo. Alert vẫn còn nguyên trong SQLite của quán và vẫn hiện trên
    // panel tại chỗ, nên mất đường lên HQ là mất ít nhất trong ba thứ.
    $this->fake->throw = true;

    $res = ($this->push)([[
        'kind' => 'no_printer', 'subject' => 'receipt_printer',
        'severity' => 'critical', 'title' => 'Chưa cấu hình máy in',
    ]])->assertStatus(202);

    expect($res->json('accepted'))->toBe(0);
});

it('#2848 dòng log lúc gửi hỏng phải mang SUBJECT, không chỉ kind', function () {
    // Với `cloud_money_overwrite`, subject CHÍNH LÀ order id. Thiếu nó thì log
    // chỉ nói được "quán nào có chuyện", không nói được ĐƠN NÀO — mà chi tiết
    // lệch nằm trong SQLite tại quán, Cloud không có `order_money_overwrites`.
    // Đã trả giá: truy ba đơn nghi vấn phải lần ngược `audit_logs` bằng tay.
    $this->fake->throw = true;

    $seen = [];
    Event::listen(
        MessageLogged::class,
        function ($e) use (&$seen) {
            if ($e->message === 'workstation-alert.dispatch-failed') {
                $seen[] = $e->context;
            }
        },
    );

    ($this->push)([[
        'kind' => 'cloud_money_overwrite',
        'subject' => '019ff92b-6f8d-7070-91e2-c10f7283e1a0',
        'severity' => 'warning',
        'title' => 'Cloud tính lại tiền của đơn này khác số máy trạm đang giữ',
        'first_seen_at' => '2026-08-13T07:01:33Z',
    ]])->assertStatus(202);

    expect($seen)->toHaveCount(1)
        ->and($seen[0]['subject'])->toBe('019ff92b-6f8d-7070-91e2-c10f7283e1a0')
        ->and($seen[0]['kind'])->toBe('cloud_money_overwrite')
        // Phân biệt "sự cố mới" với "sự cố mở từ lâu mà giờ mới đẩy được" —
        // đúng ca máy trạm nâng lên v0.6.0 rồi đẩy nguyên tồn kho alert cũ.
        ->and($seen[0]['first_seen_at'])->toBe('2026-08-13T07:01:33Z');
});

it('#1806 S3 từ chối payload sai hình dạng', function () {
    ($this->push)([['kind' => 'no_printer']])->assertStatus(422);
    ($this->push)([[
        'kind' => 'no_printer', 'subject' => 's', 'title' => 't',
        'severity' => 'khong_ton_tai',
    ]])->assertStatus(422);
});

it('#1806 S3 chặn đẩy hàng loạt', function () {
    // Trần 50: một máy trạm có hơn 50 alert MỞ cùng lúc là bất thường tới mức
    // chính nó là sự cố; nhận không giới hạn chỉ biến một máy hỏng thành một
    // trận lụt thông báo ở HQ.
    $many = array_fill(0, 51, [
        'kind' => 'no_printer', 'subject' => 'p', 'severity' => 'warning', 'title' => 't',
    ]);

    ($this->push)($many)->assertStatus(422);
});

it('#1806 S3 không có token thì không vào được', function () {
    $this->postJson('/api/v1/workstation/alerts', ['alerts' => []])->assertStatus(401);
});

/*
 * #1921 — ngày trong khoá phải là ngày nghiệp vụ CỦA CHI NHÁNH.
 *
 * Ca `#1806 S3 khoá chặn lặp mang NGÀY` ở trên chỉ ghim rằng khoá CÓ một ngày.
 * Nó xanh y hệt khi ngày đó là đồng hồ app — và đó đúng là lỗi #1091: quán JP
 * (UTC+9) thì chín tiếng đầu mỗi ngày rơi vào ngày UTC HÔM TRƯỚC.
 *
 * Hậu quả cụ thể, không phải lý thuyết: một sự cố bắt đầu 08:00 JST nhận khoá
 * của ngày hôm trước; đến 09:00 JST khoá đổi và HQ nhận thông báo THỨ HAI cho
 * cùng một sự cố. Rồi suốt phần còn lại của ngày JST đó, khoá đã bị tiêu nên
 * mọi alert mới im lặng.
 *
 * Đóng băng đồng hồ ở một thời điểm UTC mà ba timezone rơi vào BA ngày khác
 * nhau, đúng như `docs/guide/business-time.md` yêu cầu.
 */
it('#1921 ngày trong khoá theo timezone CHI NHÁNH, không theo đồng hồ app', function (
    string $timezone,
    string $expectedDate,
) {
    // 2026-08-05T22:30:00Z — UTC là ngày 05, Tokyo (+9) đã sang 06,
    // Los_Angeles (-7) vẫn còn 05 nhưng buổi chiều.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-05T22:30:00Z'));

    $this->branch->update(['timezone' => $timezone]);

    ($this->push)([[
        'kind' => 'no_printer',
        'subject' => 'receipt_printer',
        'severity' => 'critical',
        'title' => 'Chưa cấu hình máy in hoá đơn',
    ]])->assertStatus(202);

    $req = $this->fake->sent[0];

    expect($req->idempotencyKey)->toContain($expectedDate)
        ->and($req->aggregationKey)->toContain($expectedDate);

    CarbonImmutable::setTestNow();
})->with([
    'Tokyo đã sang ngày mới' => ['Asia/Tokyo', '2026-08-06'],
    'Ho Chi Minh cũng vậy' => ['Asia/Ho_Chi_Minh', '2026-08-06'],
    'UTC vẫn ngày cũ' => ['UTC', '2026-08-05'],
    'Los Angeles vẫn ngày cũ' => ['America/Los_Angeles', '2026-08-05'],
]);

/*
 * #2890 — khoá chặn lặp phải có ĐỘ DÀI CỐ ĐỊNH, không phụ thuộc subject.
 *
 * `notifications.idempotency_key` là `varchar(120)`. Khoá dạng ghép thẳng
 * (branch uuid + kind + subject + ngày) ra 124 ký tự với
 * `cloud_money_overwrite` — thừa đúng 4 — và production ném
 * `SQLSTATE[22001] Data too long` MỖI PHÚT.
 *
 * Nó nấp nguyên vẹn phía sau #2847: trước khi org id được vá, audience rỗng đã
 * ném sớm hơn nên INSERT chưa bao giờ chạy tới. Vá lỗi thứ nhất mới lộ lỗi thứ
 * hai — và bài test này là thứ lẽ ra phải bắt được nó trước production.
 *
 * `subject` do MÁY TRẠM đặt, hợp đồng cho tới 255 ký tự, nên không có trần nào
 * an toàn nếu cứ ghép thẳng. Đo bằng subject DÀI NHẤT hợp đồng cho phép.
 */
it('#2890 khoá chặn lặp không bao giờ vượt cột, kể cả subject dài nhất hợp đồng', function () {
    $subjectMax = str_repeat('x', 255);

    ($this->push)([[
        'kind' => 'cloud_money_overwrite',
        'subject' => $subjectMax,
        'severity' => 'warning',
        'title' => 'Cloud tính lại tiền của đơn này khác số máy trạm đang giữ',
    ]])->assertStatus(202);

    $key = $this->fake->sent[0]->idempotencyKey;

    // 120 = độ rộng thật của `notifications.idempotency_key` trên production
    // (đo 2026-08-15). Ghi thẳng con số thay vì tra schema: bài test này tồn
    // tại vì cột đó CÓ trần, nên trần phải hiện ra ở đây cho người đọc thấy.
    expect(strlen($key))->toBeLessThanOrEqual(120)
        // Và ĐỘ DÀI KHÔNG ĐỔI theo subject — đó mới là tính chất chặn cả họ
        // lỗi, thay vì đẩy trần lên một con số khác rồi vỡ ở kind sau.
        ->and(strlen($key))->toBe(69);
});

it('#2890 subject KHÁC nhau vẫn cho khoá KHÁC nhau — băm không được làm mất khả năng phân biệt', function () {
    $alert = fn (string $subject): array => [[
        'kind' => 'cloud_money_overwrite',
        'subject' => $subject,
        'severity' => 'warning',
        'title' => 'Cloud tính lại tiền',
    ]];

    ($this->push)($alert('220a10ac-7720-4167-945a-e56c316df460'))->assertStatus(202);
    ($this->push)($alert('d6ef2368-9ffe-42d9-9348-f80bf9c6d5a7'))->assertStatus(202);

    // Hai đơn khác nhau ở CÙNG quán, CÙNG ngày, CÙNG kind. Nếu băm làm chúng
    // trùng khoá thì HQ chỉ nhận được một, và khoản lệch kia biến mất.
    expect($this->fake->sent[0]->idempotencyKey)
        ->not->toBe($this->fake->sent[1]->idempotencyKey);
});

it('#2890 cùng một sự cố trong cùng ngày vẫn CHỈ một khoá', function () {
    $alert = [[
        'kind' => 'cloud_money_overwrite',
        'subject' => '220a10ac-7720-4167-945a-e56c316df460',
        'severity' => 'warning',
        'title' => 'Cloud tính lại tiền',
    ]];

    ($this->push)($alert)->assertStatus(202);
    ($this->push)($alert)->assertStatus(202);

    // Chiều IM của cùng cái rào: băm xong vẫn phải khử trùng được, nếu không
    // HQ nhận lại 1.440 thông báo mỗi ngày cho một sự cố.
    expect($this->fake->sent[0]->idempotencyKey)
        ->toBe($this->fake->sent[1]->idempotencyKey);
});
