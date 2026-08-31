<?php

declare(strict_types=1);

use App\Services\Notification\Audience;

/**
 * #2450 — thông báo đơn hàng phải tới CẢ quản lý quán lẫn admin tổ chức.
 *
 * ## Vì sao không phải "cấp cao hơn nhận hết"
 *
 * Tầng policy có thứ bậc: `ChecksShopContext::isShopManager()` coi org-admin là
 * quản lý quán. Nhưng thứ bậc đó KHÔNG bê nguyên sang chuyện gửi thông báo —
 * áp máy móc thì `byRole('shop-staff')` sẽ dội cho cả admin lẫn mọi quản lý.
 * "Ai được phép làm" và "ai cần được báo" là hai câu hỏi khác nhau.
 *
 * Nên đây là quyết định cho RIÊNG sự kiện đơn hàng, viết thẳng ở chỗ phát sự
 * kiện, chứ không phải một luật chung trong engine.
 *
 * ## Vì sao cần
 *
 * Platform cấp vai theo `service_role` (admin/manager/member). Ở một doanh
 * nghiệp mà chủ quán là người dùng duy nhất, người đó là `admin`. Đo trên
 * production 2026-08-11 sau khi vá #2460: `org-admin` ở một chi nhánh phân giải
 * 1 người, `shop-manager` phân giải 0. Chỉ hỏi `shop-manager` = không bao giờ
 * báo cho ai, dù mọi tầng dịch vai đã đúng.
 */
it('#2450 — audience đơn hàng gồm cả shop-manager lẫn org-admin, cùng một chi nhánh', function () {
    $rule = Audience::byRolesInScope(['shop-manager', 'org-admin'], 'branch_id', 'branch-1')->toRule();

    expect($rule['combinator'])->toBe('or')
        ->and($rule['rules'])->toHaveCount(2);

    $roles = array_column($rule['rules'], 'role');
    expect($roles)->toContain('shop-manager')->toContain('org-admin');

    // Bất biến quan trọng nhất: MỌI rule mang phạm vi. Một rule sót phạm vi
    // nghĩa là vai đó bắn cho toàn bộ tổ chức thay vì một chi nhánh.
    foreach ($rule['rules'] as $r) {
        expect($r['scope'] ?? null)->toBe(['branch_id' => 'branch-1']);
    }
});

it('#2450 — byRole()->scopedToKey() CHỈ gắn phạm vi vào rule cuối (lý do factory tồn tại)', function () {
    // Ghim cái bẫy, không phải để khuyến khích cách dựng này mà để nếu ai đó
    // "đơn giản hoá" `byRolesInScope` thành chuỗi `byRole()` thì có test nói vì
    // sao không được.
    $trap = Audience::byRole('shop-manager')->scopedToKey('branch_id', 'branch-1')->toRule();

    expect($trap['rules'])->toHaveCount(1)
        ->and($trap['rules'][0]['scope'])->toBe(['branch_id' => 'branch-1']);
});

it('#2450 — một vai truyền dạng chuỗi vẫn ra đúng hình dạng rule cũ', function () {
    $viaFactory = Audience::byRolesInScope(['shop-manager'], 'branch_id', 'branch-1')->toRule();
    $viaBuilder = Audience::byRole('shop-manager')->scopedToKey('branch_id', 'branch-1')->toRule();

    expect($viaFactory)->toBe($viaBuilder);
});

it('#2450 — trùng vai bị gộp, không nhân đôi người nhận', function () {
    $rule = Audience::byRolesInScope(['org-admin', 'org-admin'], 'branch_id', 'branch-1')->toRule();

    expect($rule['rules'])->toHaveCount(1);
});

it('#2450 — observer đơn hàng hỏi đúng hai vai đó', function () {
    $source = file_get_contents(app_path('Observers/CustomerOrderNotificationObserver.php'));

    expect($source)->toContain("role: ['shop-manager', 'org-admin']");
});

// ĐÃ GỠ #2413: rào parity giữa `notifications:audit-rollout` và observer. Lệnh
// đó không còn, nên không còn hai chỗ để lệch — rào ngay trên (observer hỏi
// đúng hai vai) là thứ duy nhất còn đối tượng để canh.
