<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Organization;
use App\Models\PaymentGatewayConnection;
use App\Modules\Notifications\Contracts\NotificationDispatcher;
use App\Modules\Notifications\Contracts\NotificationRequest;
use App\Services\Payment\Settlement\SettlementAlertService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/*
 * plan-050 T4.3 — cảnh báo đối soát.
 *
 * Thay `NotificationDispatcher` bằng bản giả có chủ đích. Nền tảng thông báo đã
 * có bộ test riêng; thứ CHƯA được ghim là logic của lớp này — gom theo gì, chặn
 * lặp bằng gì, lọc cái gì ra. Chạy end-to-end qua nền tảng sẽ đo lại thứ đã đo
 * rồi, và làm hỏng test vì một lý do không liên quan (thiếu role, thiếu quyền).
 */

/** Ghi lại mọi lời gọi thay vì gửi thật. */
final class FakeNotificationDispatcher implements NotificationDispatcher
{
    /** @var list<array{request: NotificationRequest, role: string, scopeKey: string, scopeId: string}> */
    public array $calls = [];

    public bool $throwOnDispatch = false;

    public function toRole(
        NotificationRequest $request,
        string|array $role,
        string $scopeKey,
        string $scopeId,
        Brand $brand,
    ): string {
        if ($this->throwOnDispatch) {
            throw new RuntimeException('audience rỗng');
        }

        $this->calls[] = compact('request', 'role', 'scopeKey', 'scopeId');

        return 'notif-'.count($this->calls);
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
    $this->fake = new FakeNotificationDispatcher;
    $this->service = new SettlementAlertService($this->fake);

    // `brands` KHÔNG có cột `organization_id` — nó nối với tổ chức qua bản sao
    // `console_organization_id` (cùng cách StockAlertNotificationObserver dùng).
    $org = Organization::factory()->create();
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $org->console_organization_id,
    ]);
    $this->connection = PaymentGatewayConnection::factory()->create([
        'organization_id' => $org->id,
        'brand_id' => $this->brand->id,
    ]);
    $this->orgId = (string) $org->id;
});

it('#1155 T4.3 gom nhiều dòng lệch thành MỘT thông báo cho mỗi (connection, loại)', function () {
    // G4 — đây là vế chống nhiễu quan trọng nhất. Một ngày lệch 40 dòng phải ra
    // 1 thông báo, không phải 40. Trước khi có lớp này, không có gì đảm bảo điều
    // đó, và alert nhiễu là alert bị bỏ qua.
    $rows = array_map(fn (int $i): array => [
        'settlement_id' => "s-{$i}",
        'connection_id' => (string) $this->connection->id,
        'net_minor' => 1000,
        'currency' => 'JPY',
    ], range(1, 40));

    $raised = $this->service->raise(['orphans' => $rows], []);

    expect($this->fake->calls)->toHaveCount(1)
        ->and($raised)->toHaveCount(1)
        ->and($raised[0]['count'])->toBe(40)
        ->and($raised[0]['sent'])->toBeTrue();

    $params = $this->fake->calls[0]['request']->params;

    // Số tiền tổng phải là của CẢ 40 dòng, không phải của 5 dòng mẫu — nếu
    // không, người đọc sẽ đánh giá thấp mức độ nghiêm trọng đúng 8 lần.
    expect($params['count'])->toBe(40)
        ->and($params['total_minor'])->toBe(40_000)
        ->and($params['samples'])->toHaveCount(5)
        ->and($params['truncated'])->toBeTrue();
});

it('#1155 T4.3 khoá chặn lặp mang NGÀY nghiệp vụ', function () {
    // Lệnh đối soát chạy theo lịch; không có ngày trong khoá thì một tình trạng
    // kéo dài sẽ kêu mỗi lượt chạy. Có ngày thì nó kêu mỗi ngày một lần.
    $row = [['settlement_id' => 's-1', 'connection_id' => (string) $this->connection->id, 'net_minor' => 500]];

    $this->service->raise(['orphans' => $row], [], CarbonImmutable::parse('2026-08-05 06:30:00'));
    $this->service->raise(['orphans' => $row], [], CarbonImmutable::parse('2026-08-05 18:30:00'));
    $this->service->raise(['orphans' => $row], [], CarbonImmutable::parse('2026-08-06 06:30:00'));

    $keys = array_map(static fn (array $c): ?string => $c['request']->idempotencyKey, $this->fake->calls);

    // Hai lượt trong CÙNG ngày phải cho cùng một khoá (nền tảng sẽ khử trùng);
    // ngày khác phải cho khoá khác.
    expect($keys[0])->toBe($keys[1])
        ->and($keys[0])->toContain('2026-08-05')
        ->and($keys[2])->toContain('2026-08-06')
        ->and($keys[2])->not->toBe($keys[0]);
});

it('#1155 T4.3 aging CHƯA vượt ngưỡng thì không kêu', function () {
    // `pendingPayoutAging()` trả cả những khoản còn trong hạn — đó là trạng thái
    // BÌNH THƯỜNG của tiền chờ chi trả, không phải sự cố. Kêu lên là tạo ra
    // đúng thứ nhiễu mà G4 cấm.
    $aging = [
        ['connection_id' => (string) $this->connection->id, 'total_net_minor' => 900, 'over_threshold' => false],
        ['connection_id' => (string) $this->connection->id, 'total_net_minor' => 100, 'over_threshold' => true],
    ];

    $raised = $this->service->raise([], $aging);

    expect($raised)->toHaveCount(1)
        ->and($raised[0]['type'])->toBe('settlement.payout_aging')
        ->and($raised[0]['count'])->toBe(1)
        ->and($this->fake->calls[0]['request']->params['total_minor'])->toBe(100);
});

it('#1155 T4.3 mỗi cảnh báo kèm lệnh xử lý gợi ý', function () {
    // G4: "mọi alert phải actionable — kèm connection, số tiền, lệnh xử lý gợi
    // ý". Một dòng "có sai lệch" không nói được người đọc phải làm gì tiếp.
    $cid = (string) $this->connection->id;

    $this->service->raise([
        'orphans' => [['settlement_id' => 's-1', 'connection_id' => $cid, 'net_minor' => 100]],
    ], [
        ['connection_id' => $cid, 'total_net_minor' => 100, 'over_threshold' => true],
    ]);

    $byType = [];
    foreach ($this->fake->calls as $call) {
        $byType[$call['request']->type] = $call['request']->params;
    }

    expect($byType['settlement.orphan_overdue']['suggested_command'])
        ->toContain('settlements:reconcile')
        ->toContain($cid)
        // Gợi ý phải là lệnh AN TOÀN — người nhận cảnh báo lúc 6:30 sáng không
        // nên được mời chạy một lệnh ghi dữ liệu.
        ->toContain('--dry-run');

    // Aging KHÔNG sửa được bằng lệnh: đó là tiền gateway chưa chi. Gợi ý một
    // lệnh ở đây sẽ là gợi ý sai.
    expect($byType['settlement.payout_aging']['suggested_command'])
        ->not->toContain('artisan')
        ->toContain('provider');
});

it('#1155 T4.3 dòng KHÔNG quy được về connection thì báo, không nuốt', function () {
    $raised = $this->service->raise([
        'orphans' => [
            ['settlement_id' => 's-1', 'net_minor' => 100],
            ['settlement_id' => 's-2', 'connection_id' => (string) $this->connection->id, 'net_minor' => 200],
        ],
    ], []);

    // Dòng mồ côi không chặn dòng còn lại được gửi.
    expect($raised)->toHaveCount(1)
        ->and($raised[0]['count'])->toBe(1);
});

it('#1155 T4.3 connection không còn tồn tại thì KHÔNG mất số liệu', function () {
    // S-20 — connection bị thu hồi giữa lúc đối soát và lúc gửi. Không gửi được
    // là chấp nhận được; ĐÁNH MẤT con số thì không.
    $raised = $this->service->raise([
        'orphans' => [['settlement_id' => 's-1', 'connection_id' => '019f0000-0000-7000-8000-000000000000', 'net_minor' => 700]],
    ], []);

    expect($this->fake->calls)->toBeEmpty()
        ->and($raised)->toHaveCount(1)
        ->and($raised[0]['sent'])->toBeFalse()
        ->and($raised[0]['reason'])->toBe('unknown-connection')
        // Con số vẫn còn trong kết quả trả về, nên lệnh vẫn in ra được.
        ->and($raised[0]['count'])->toBe(1);
});

it('#1155 T4.3 gửi hỏng KHÔNG làm hỏng đối soát', function () {
    // Audience rỗng (chưa ai giữ role đó) ném ngoại lệ. Đối soát là thứ GHI dữ
    // liệu; cảnh báo chỉ nói về nó — để cảnh báo kéo đổ đối soát là đảo ngược
    // thứ tự quan trọng.
    $this->fake->throwOnDispatch = true;

    $raised = $this->service->raise([
        'orphans' => [['settlement_id' => 's-1', 'connection_id' => (string) $this->connection->id, 'net_minor' => 100]],
    ], []);

    expect($raised)->toHaveCount(1)
        ->and($raised[0]['sent'])->toBeFalse()
        ->and($raised[0]['reason'])->toBe('dispatch-failed');
});

it('#1155 T4.3 role người nhận là CẤU HÌNH, không hardcode', function () {
    config()->set('payments.settlement.alert_role', 'org-manager');

    $this->service->raise([
        'orphans' => [['settlement_id' => 's-1', 'connection_id' => (string) $this->connection->id, 'net_minor' => 100]],
    ], []);

    expect($this->fake->calls[0]['role'])->toBe('org-manager')
        ->and($this->fake->calls[0]['scopeKey'])->toBe('organization_id')
        ->and($this->fake->calls[0]['scopeId'])->toBe($this->orgId);
});
