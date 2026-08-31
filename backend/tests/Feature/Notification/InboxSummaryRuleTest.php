<?php

declare(strict_types=1);

/**
 * Năm luật của payload tóm tắt hộp thư — `GET /api/v1/me/notifications/summary`.
 *
 * Trước đây năm luật này sống trong `tests/Unit/Modules/Notifications/
 * InboxSummaryRuleTest.php`, chạy trên pilot hexagonal (#1360) qua một double
 * trong bộ nhớ. Pilot bị gỡ theo phán quyết "Laravel là base, không có tầng PHP
 * thuần" (ADR 0001 §1c, #1665) — nhưng LUẬT thì không đi theo pilot. Chúng nói
 * về payload mà khách thật đọc, nên nay chạy trên đường thật:
 * `NotificationService::summaryFor()`.
 *
 * Cái đắt nhất trong đây là R4. Một bản cài lại sẽ để `latest_created_at` đi
 * theo `unread_count`, và khi đó đọc hết hộp thư làm nó trông như chưa từng có
 * hoạt động nào. Chỉ thao tác "loại bỏ" mới được xoá một dòng khỏi mọi con số.
 */

use App\Models\Notification;
use App\Models\NotificationRecipient;
use App\Models\Organization;
use App\Models\User;
use App\Omnify\Enums\NotificationPriorityEnum;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);
});

function inboxSeed(User $user, string $orgId, NotificationPriorityEnum $priority, array $recipientAttrs = []): NotificationRecipient
{
    $notification = Notification::create([
        'id' => (string) Str::uuid(),
        'organization_id' => $orgId,
        'type' => 'system.notice',
        'template_key' => 'system.notice',
        'priority' => $priority,
        'payload' => [],
        'is_dispatched' => true,
    ]);

    return NotificationRecipient::create(array_merge([
        'id' => (string) Str::uuid(),
        'notification_id' => $notification->id,
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->getKey(),
    ], $recipientAttrs));
}

function inboxSummary(User $user): array
{
    return app(NotificationService::class)->summaryFor($user);
}

it('R1: mọi mức ưu tiên luôn có mặt, kể cả bằng 0', function () {
    inboxSeed($this->user, $this->orgId, NotificationPriorityEnum::Urgent);

    // Client vẽ một dãy chip cố định; thiếu một khoá là một chip biến mất im lặng.
    expect(array_keys(inboxSummary($this->user)['priority_breakdown']))
        ->toBe(['low', 'normal', 'high', 'urgent']);
});

it('R2: thứ tự khoá cố định — client đọc theo vị trí không được vỡ', function () {
    expect(array_keys(inboxSummary($this->user)))
        ->toBe(['unread_count', 'priority_breakdown', 'latest_created_at']);
});

it('R3: hộp thư rỗng ra 0 và null, không phải mảng rỗng', function () {
    $out = inboxSummary($this->user);

    expect($out['unread_count'])->toBe(0)
        ->and($out['latest_created_at'])->toBeNull()
        ->and($out['priority_breakdown']['low'])->toBe(0);
});

it('R4: ĐỌC HẾT vẫn còn latest_created_at — đọc không làm hộp thư trông như chưa từng có gì', function () {
    inboxSeed($this->user, $this->orgId, NotificationPriorityEnum::High, ['read_at' => now()]);

    $out = inboxSummary($this->user);

    expect($out['unread_count'])->toBe(0)
        ->and($out['latest_created_at'])->not->toBeNull();
});

it('R4b: LOẠI BỎ mới xoá dòng khỏi mọi con số', function () {
    inboxSeed($this->user, $this->orgId, NotificationPriorityEnum::High, ['dismissed_at' => now()]);

    $out = inboxSummary($this->user);

    expect($out['unread_count'])->toBe(0)
        ->and($out['latest_created_at'])->toBeNull();
});

it('R5: đếm được ép về int — driver trả chuỗi không lọt ra JSON', function () {
    foreach (range(1, 3) as $_) {
        inboxSeed($this->user, $this->orgId, NotificationPriorityEnum::High);
    }

    expect(inboxSummary($this->user)['priority_breakdown']['high'])->toBe(3);
});
