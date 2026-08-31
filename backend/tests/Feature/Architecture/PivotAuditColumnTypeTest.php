<?php

/**
 * #1689 — cấm `options.audit` trên entity `kind: pivot`.
 *
 * Generator sinh cột `created_by_id` / `updated_by_id` SAI KIỂU khi entity là
 * pivot: `bigint unsigned` thay vì `uuid`, trong khi `users.id` ở repo này là
 * `char(36)`. Nhét UUID vào bigint ⇒ MySQL truncate ⇒ MỌI lần ghi dòng pivot
 * đều chết:
 *
 *   SQLSTATE[01000]: Warning: 1265 Data truncated for column 'created_by_id'
 *
 * Entity THƯỜNG không dính — `products`, `posts`, `coupons`, `devices`,
 * `point_rewards`, `post_categories` đều ra `char(36)` đúng. Chỉ nhánh pivot
 * của generator sai, nên đây là lỗi generator chứ không phải dùng sai tài liệu
 * (`AuditConfig` trong `omnify-config-schema.json` không có tuỳ chọn kiểu khoá
 * để mà khai sai).
 *
 * VÌ SAO PHẢI LÀ TEST QUÉT TĨNH, KHÔNG PHẢI TEST TÍNH NĂNG:
 * bộ test chạy trên **sqlite**, vốn có kiểu động — chuỗi UUID nhét vào cột
 * INTEGER không kêu một tiếng. #1684 có 14 test tính năng xanh mướt cho đúng
 * cái công tắc chết sạch trên MySQL. Chỉ quét YAML mới bắt được, vì nó không
 * phụ thuộc vào việc DB nào đang chạy.
 *
 * Cách né: khai `Association` → `User` (sinh cột uuid đúng, FK thật) rồi tự
 * điền ở service — xem `PostBranch.yaml` và `PostFaqService`.
 */

use Symfony\Component\Yaml\Yaml;

/**
 * Pivot ĐÃ dính lỗi từ trước, chờ PR riêng. **Nay rỗng** — #1723 đã sửa
 * `PointRewardBranch`, mục cuối cùng, nên luật dưới đây giờ áp cho MỌI pivot
 * không trừ cái nào.
 *
 * KHÔNG thêm gì vào danh sách này. Nó chỉ được RÚT NGẮN. Thêm tên vào nghĩa là
 * có người vừa ship một pivot chết trên MySQL rồi ghi tên nó ra đây thay vì sửa.
 *
 * @var list<string>
 */
const PIVOT_AUDIT_KNOWN_BROKEN = [];

it('không entity kind: pivot nào dùng options.audit — cột sai kiểu, MySQL truncate', function () {
    $schemaDir = base_path('../schemas');

    expect(is_dir($schemaDir))->toBeTrue("Không thấy thư mục schemas ở {$schemaDir}");

    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($schemaDir));

    foreach ($files as $file) {
        if (! $file->isFile() || ! in_array($file->getExtension(), ['yaml', 'yml'], true)) {
            continue;
        }

        $parsed = Yaml::parseFile($file->getPathname());

        if (! is_array($parsed) || ($parsed['kind'] ?? null) !== 'pivot') {
            continue;
        }

        $audit = $parsed['options']['audit'] ?? null;

        if (! is_array($audit)) {
            continue;
        }

        // `audit: true` cho bảng lịch sử (#94) là chuyện khác và không sinh cột
        // `*_by_id` — chỉ ba khoá này mới sinh.
        $byColumns = array_filter([
            'createdBy' => $audit['createdBy'] ?? false,
            'updatedBy' => $audit['updatedBy'] ?? false,
            'deletedBy' => $audit['deletedBy'] ?? false,
        ]);

        if ($byColumns === []) {
            continue;
        }

        $name = pathinfo($file->getFilename(), PATHINFO_FILENAME);

        if (in_array($name, PIVOT_AUDIT_KNOWN_BROKEN, true)) {
            continue;
        }

        $offenders[$name] = implode(', ', array_keys($byColumns));
    }

    expect($offenders)->toBe([], sprintf(
        'Entity `kind: pivot` dưới đây bật options.audit — generator sẽ sinh cột bigint '.
        'trong khi users.id là char(36), và MỌI lần ghi sẽ chết trên MySQL (sqlite trong '.
        "test sẽ KHÔNG bắt được):\n%s\n\nDùng Association → User rồi tự điền ở service. Xem #1689.",
        implode("\n", array_map(
            fn ($k, $v) => "  - {$k} ({$v})",
            array_keys($offenders),
            $offenders,
        ))
    ));
});

it('danh sách known-broken không phình ra', function () {
    // Danh sách này chỉ được RÚT NGẮN, và đã rút hết (#1723). Dài ra nghĩa là
    // có người vừa thêm một pivot hỏng và ghi tên nó vào đây thay vì sửa.
    expect(PIVOT_AUDIT_KNOWN_BROKEN)->toBe([]);
});
