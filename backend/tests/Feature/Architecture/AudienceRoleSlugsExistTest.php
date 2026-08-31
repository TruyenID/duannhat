<?php

declare(strict_types=1);

use App\Support\Iam\RoleTemplateMatrix;

/**
 * #2451 — mọi slug role mà tầng thông báo YÊU CẦU phải có thật trong từ vựng role.
 *
 * ## Vì sao rào này tồn tại
 *
 * `EloquentRoleAssignmentDirectory::withRole()` so `roles.slug` bằng **chuỗi
 * chính xác**, không chuẩn hoá gạch ngang/gạch dưới:
 *
 *     ->join('roles', ...)->where('roles.slug', $roleSlug)
 *
 * Từ vựng thật do {@see RoleTemplateMatrix::ROLES} định nghĩa và `IamSeeder`
 * dựng, toàn bộ dùng **gạch ngang**: `org-admin` · `org-manager` ·
 * `shop-manager` · `staff` · `shop-staff`.
 *
 * `CustomerOrderNotificationObserver` thì gọi `role: 'shop_manager'` — **gạch
 * dưới**. Không role nào mang slug đó, nên phép phân giải trả rỗng ở MỌI lần
 * gọi. Đo trên production 2026-08-11: **33/33 trigger đơn hàng trong 30 ngày
 * phân giải 0 người nhận** (#2450) — tức một tháng không ai được báo có đơn.
 *
 * Hai emitter khác (`PrintPipelineAlertService`, `OrderPaymentService`) dùng
 * `shop-manager` và chạy đúng, nên lỗi không lộ ra dưới dạng "thông báo hỏng"
 * mà chỉ dưới dạng "riêng đơn hàng thì im".
 *
 * ## Quét rộng ra ngoài audience — ba slug ma nữa
 *
 * Sửa xong `shop_manager` mới lộ ra rằng cùng một lỗi nằm ở ba chỗ khác, cũng
 * gạch dưới, cũng câm lặng. Ánh xạ về từ vựng thật theo TẦNG PHẠM VI mà
 * `RoleResolver` dùng để phân giải:
 *
 * | Slug ma        | Phạm vi      | Thay bằng      | Vì sao |
 * |----------------|--------------|----------------|--------|
 * | `brand_admin`  | brand        | `org-admin`    | brand KHÔNG có role riêng; `brandRole()` tra ngược lên tổ chức sở hữu brand |
 * | `branch_admin` | branch       | `shop-manager` | vai quản lý cấp chi nhánh trong ma trận |
 * | `org_owner`    | organization | `org-admin`    | vai cao nhất cấp tổ chức |
 *
 * `brand_admin` không chỉ nằm ở seeder: nó còn là điều kiện của
 * `Gate::define('viewNotificationCoverage')` trong `AppServiceProvider`, nên
 * cổng đó **từ chối mọi người** kể từ ngày được viết.
 *
 * Vì thế rào này quét cả `database/seeders/` chứ không riêng `app/`, và bắt cả
 * `hasRoleInContext('x', ...)` chứ không riêng `byRole('x')` — audience rule của
 * seeder được ghi thẳng vào DB rồi mới phân giải lúc chạy, nên một slug sai ở đó
 * không bao giờ ném lỗi, nó chỉ lặng lẽ không gửi cho ai.
 *
 * ## Vì sao test cũ không bắt được
 *
 * `tests/Unit/Notification/EmitterAudienceTest.php` **tự tạo** role slug
 * `shop_manager` rồi khẳng định resolver tìm ra nó. Một bài test dựng thế giới
 * riêng bằng từ vựng mà phần còn lại của hệ thống không dùng thì xanh vĩnh viễn
 * và không chứng minh gì. Rào này đối chiếu với NGUỒN CHÂN LÝ thay vì với dữ
 * liệu do chính test bịa ra.
 */
it('#2451 — slug role trong lời gọi audience đều có trong RoleTemplateMatrix', function () {
    $vocabulary = array_keys(RoleTemplateMatrix::ROLES);
    expect($vocabulary)->not->toBeEmpty();

    $scanDirs = [base_path('app'), base_path('database/seeders')];
    $offenders = [];
    $seen = 0;
    // #2859 — mẫu số RIÊNG cho dạng mảng. `$seen` gộp cả bốn dạng, nên nếu chỉ
    // canh nó thì phép quét mảng có thể hỏng hoàn toàn mà tổng vẫn vượt ngưỡng
    // — đúng cái bẫy ratchet-đếm mà chính rào này đã ghi ở cuối file.
    $seenArrays = 0;

    $files = new AppendIterator;
    foreach ($scanDirs as $dir) {
        $files->append(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)));
    }

    /** @var SplFileInfo $file */
    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = ltrim(str_replace(base_path(), '', $file->getPathname()), '/');
        $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES) ?: [];

        foreach ($lines as $n => $line) {
            // Bỏ comment/docblock: ví dụ minh hoạ trong docblock không phải
            // lời gọi, cùng lý lẽ với LegacyIdentifierBanTest.
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            // Bốn cách một slug role bị YÊU CẦU trong cây này:
            //   Audience::byRole('x')            — emitter dựng audience
            //   role: 'x'                        — tham số có tên, MỘT slug
            //   hasRoleInContext('x', ...)       — policy/gate hỏi trực tiếp
            //   'role' => 'x'                    — audience_rule trong seeder
            //
            // Dạng thứ năm — `role: ['a', 'b']` — quét ở vòng RIÊNG bên dưới,
            // không ở đây: nó có thể xuống dòng, mà vòng này đọc từng dòng một.
            if (! preg_match_all("/(?:byRole\(|hasRoleInContext\(|role:\s*|'role'\s*=>\s*)'([a-z0-9_-]+)'/", $line, $m)) {
                continue;
            }

            foreach ($m[1] as $slug) {
                // Kho `warehouse_*` không đi qua bảng roles — RoleResolver rẽ
                // chúng sang warehouse_members trước khi chạm `withRole()`.
                if (str_starts_with($slug, 'warehouse_')) {
                    continue;
                }
                $seen++;
                if (! in_array($slug, $vocabulary, true)) {
                    $offenders[] = "{$relative}:".($n + 1)."  →  '{$slug}'";
                }
            }
        }

        // #2859 — dạng MẢNG `role: ['shop-manager', 'org-admin']`.
        //
        // Đây nay là khuôn CHUẨN (#2450: ai cần được báo là quyết định của từng
        // sự kiện, và hầu hết sự kiện cần hơn một vai), nhưng vòng theo dòng ở
        // trên chỉ khớp một string đơn ngay sau `role:` nên nó **mù hoàn toàn**
        // với dạng này. Đo ở #2859: tiêm `'org_admin'` gạch dưới vào một call
        // site dạng mảng — rào vẫn XANH. Bốn call site đang dùng dạng đó.
        //
        // Đó chính là lớp lỗi đã cháy bốn lần (`shop_manager` #2451;
        // `brand_admin`/`branch_admin`/`org_owner` #2456): slug sai KHÔNG ném
        // lỗi, nó phân giải ra 0 người nhận và im lặng mãi mãi.
        //
        // Quét cả FILE chứ không theo dòng, vì một mảng dài sẽ xuống dòng — và
        // "lần mù tiếp theo" gần như chắc chắn có hình dạng đó. `[^\]]*` với cờ
        // `s` cho phép xuống dòng mà vẫn dừng ở dấu `]` đầu tiên.
        $source = (string) file_get_contents($file->getPathname());

        if (preg_match_all("/role:\s*\[([^\]]*)\]/s", $source, $arrays, PREG_OFFSET_CAPTURE)) {
            foreach ($arrays[1] as $i => [$body, $_]) {
                // Bỏ ví dụ trong docblock/comment: xét dòng nơi lời gọi BẮT ĐẦU.
                $startLine = substr_count(substr($source, 0, $arrays[0][$i][1]), "\n");
                $lineText = ltrim($lines[$startLine] ?? '');
                if (str_starts_with($lineText, '*') || str_starts_with($lineText, '//') || str_starts_with($lineText, '/*')) {
                    continue;
                }

                if (! preg_match_all("/'([a-z0-9_-]+)'/", $body, $inner)) {
                    continue;
                }

                foreach ($inner[1] as $slug) {
                    if (str_starts_with($slug, 'warehouse_')) {
                        continue;
                    }
                    $seen++;
                    $seenArrays++;
                    if (! in_array($slug, $vocabulary, true)) {
                        $offenders[] = "{$relative}:".($startLine + 1)."  →  '{$slug}' (trong mảng)";
                    }
                }
            }
        }
    }

    // Cổng phải THẤY thứ để canh. Nếu một lần đổi bố cục làm regex không khớp
    // gì nữa, khẳng định dưới sẽ xanh vĩnh viễn mà không canh gì — đúng cái bẫy
    // ratchet-đếm đã mắc ở ZDomainMutationBaselineTest.
    expect($seen)->toBeGreaterThan(50, 'Rào không quét thấy lời gọi role nào — regex hoặc bố cục thư mục đã đổi.');

    // #2859 — và mẫu số riêng cho dạng mảng. Bốn call site đang dùng nó, mỗi
    // cái hai slug. Ngưỡng đặt thấp hơn hẳn con số thật (8) vì mục đích là bắt
    // "phép quét mảng CHẾT HẲN", không phải ghim số call site — ghim số thì mỗi
    // lần thêm một emitter lại phải sửa rào, và rào hay phải sửa là rào sắp bị
    // nới cho qua.
    expect($seenArrays)->toBeGreaterThan(4, 'Rào không còn thấy dạng `role: [...]` — chính là lỗ #2859 mở lại.');

    expect($offenders)->toBe([], implode("\n", array_merge(
        ['Slug role không có trong RoleTemplateMatrix::ROLES (#2451):', ''],
        $offenders,
        ['', 'Từ vựng hợp lệ: '.implode(' · ', $vocabulary)],
        ['', '`withRole()` so chuỗi CHÍNH XÁC — một slug sai kiểu gạch không'],
        ['báo lỗi, nó chỉ phân giải ra 0 người nhận và im lặng mãi mãi.'],
    )));
});
