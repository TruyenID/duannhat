<?php

use Symfony\Component\Finder\Finder;

/**
 * Ruling #2776 — bootstrap két MAIN từ đường ĐỌC là CỐ Ý, mã do CALLER khai thì KHÔNG.
 *
 * `resolveTillForBranch()` là `firstOrCreate`. #2745 đóng lớp nguy hiểm của nó:
 * một `GET` nhận `?till_code=` tự do đẻ ra hàng `tills` mang mã người gọi bịa,
 * và két rác đó không đứng yên — nó lọt vào comparator "két có ca kết thúc gần
 * nhất" và bẻ ranh ca của cả chi nhánh.
 *
 * Ba đường đọc còn lại (`/pos/till/current`, `/pos/till/denominations`,
 * `/workstation/till/current`) bootstrap MAIN và ĐƯỢC PHÉP giữ nguyên. Chúng
 * khác loại ở đúng ba điểm đo được: mã đến từ hằng trong mã nguồn chứ không từ
 * caller, số hàng sinh ra bị chặn trên bởi lược đồ (một mỗi chi nhánh), và hàng
 * ấy là két quán sẽ dùng thật. Luật "đường đọc không có tác dụng phụ ghi" nhắm
 * vào việc tạo hàng KHÔNG CHẶN TRÊN do người gọi điều khiển; bootstrap MAIN
 * không có tính chất nào trong số đó. Bỏ nó đi thì chi nhánh mới toanh trả 404
 * ở `GET /current` và màn POS chết trước khi ai kịp mở ca.
 *
 * Rào này KHÔNG ghim "đường đọc có được ghi không" — đó là câu hỏi đã có ruling.
 * Nó ghim đúng RANH GIỚI của ruling, thứ khó thấy khi đọc diff: **tham số thứ
 * hai (mã két) chỉ được truyền từ đường GHI.** Một lời gọi hai tham số mọc lên ở
 * controller đọc trông vô hại trong diff và mở lại đúng lỗ #2745.
 */
function tillBootstrapCodeOnly(string $path): string
{
    // php_strip_whitespace bỏ comment — bắt buộc, không phải cho gọn: docblock
    // của chính `shiftBoundaryTillForBranch` NHẮC `resolveTillForBranch(...)`
    // kèm tham số để giải thích vì sao nó tồn tại. Quét thô sẽ đếm đúng lời giải
    // thích đó là vi phạm — cái bẫy "khớp trong comment" đã ba lần cho ra chỉ số
    // sai ở repo này.
    return php_strip_whitespace($path);
}

it('chỉ đường GHI được truyền mã két cho resolveTillForBranch (#2776)', function () {
    // Đường GHI duy nhất được phép: `lockTill()` nhận mã từ caller của `open()`
    // — mở ca là hành vi ghi, và ở đó chọn két là quyền của thu ngân.
    $allowed = ['app/Services/Pos/TillSessionService.php'];

    $offenders = [];
    foreach (Finder::create()->files()->in(base_path('app'))->name('*.php') as $file) {
        $relative = str_replace(base_path().'/', '', $file->getPathname());
        $code = tillBootstrapCodeOnly($file->getPathname());

        // Lời gọi HAI tham số: `resolveTillForBranch($x, $y)`. Một tham số là
        // MAIN và luôn hợp lệ, kể cả từ đường đọc — đó chính là ruling.
        if (! preg_match_all('/resolveTillForBranch\s*\(\s*[^)]*,/', $code, $m)) {
            continue;
        }
        if (in_array($relative, $allowed, true)) {
            continue;
        }
        $offenders[] = $relative.' ('.count($m[0]).' lời gọi mang mã két)';
    }

    expect($offenders)->toBe([], implode("\n", array_merge(
        ['Ruling #2776: mã két do CALLER khai chỉ được đi vào đường GHI.'],
        $offenders,
        ['Đường đọc cần két thì gọi MỘT tham số (bootstrap MAIN), hoặc dùng bản',
            'KHÔNG tạo `shiftBoundaryTillForBranch()`. Xem docs/guide/cashier-shift-recovery.md.'],
    )));
});

it('mẫu số: rào thật sự tìm thấy lời gọi để mà xét (#2776)', function () {
    // Không có bài này thì bài trên xanh vĩnh viễn khi hàm được đổi tên, gỡ đi,
    // hay chuyển sang một helper khác — đúng lớp lỗi "đổi bố cục làm tắt tiếng
    // cổng" mà repo đã trả giá năm lần khi gộp monorepo. Số 0 ở đây là một
    // KHẲNG ĐỊNH, không phải mặc định.
    $total = 0;
    foreach (Finder::create()->files()->in(base_path('app'))->name('*.php') as $file) {
        $total += preg_match_all(
            '/resolveTillForBranch\s*\(/',
            tillBootstrapCodeOnly($file->getPathname()),
            $ignored
        );
    }

    expect($total)->toBeGreaterThanOrEqual(4,
        "chỉ thấy {$total} lời gọi `resolveTillForBranch(` trong app/ — hàm đã đổi tên "
        .'hoặc bị gỡ? Rào trên đang không canh gì; cập nhật nó thay vì để xanh.');
});
