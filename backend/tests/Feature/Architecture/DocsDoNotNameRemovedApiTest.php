<?php

declare(strict_types=1);

/**
 * #2483 — tài liệu SỐNG không được dạy một API hay một cờ đã bị gỡ.
 *
 * ## Vì sao rào này tồn tại
 *
 * 2026-08-12 rà `docs/` sau chuỗi #2451→#2456→#2460→#2450 và tìm thấy sáu chỗ
 * dạy sai, trong đó `docs/contributing/emitting-notifications.md` — file hướng
 * dẫn người viết emitter MỚI — dạy trọn ba thứ đã chết:
 *
 *   - `Audience::byRole(...)->scopedTo($model)` — `scopedTo(Model)` gỡ ở #1568
 *   - `config('notifications.use_audience')` — gỡ ở plan-023 M1
 *   - mẫu "cap-N legacy fallback sau cờ" — gỡ ở T1.3
 *
 * Không test nào đỏ, vì test canh mã chứ không canh chữ. Người đọc tài liệu thì
 * không có CI. Đây đúng hình dạng của #2215 (xoá một thứ nhưng để nguyên chỗ trỏ
 * tới nó), chỉ khác là nạn nhân ở tầng tài liệu.
 *
 * ## Cái gì được miễn, và vì sao
 *
 * **Không còn thư mục nào được miễn.** Bản đầu của rào này chừa
 * `docs/superpowers/specs/` và `docs/testing/` với lý do "hồ sơ quá khứ, y như
 * `plans/`". Chủ dự án bác lại ở #2485: chưa release thì không giữ hồ sơ quá khứ
 * trong cây, git history đủ. Cả hai thư mục **đã xoá**, nên `docs/` giờ toàn là
 * tài liệu sống và được quét hết. Nếu sau này có ai thêm một thư mục lưu trữ
 * mới, hãy hỏi lại xem nó có nên tồn tại không, TRƯỚC khi thêm vào miễn trừ.
 *
 * **Removal record được phép.** Một dòng nói "`scopedTo` đã gỡ ở #1568" là thứ
 * ta MUỐN có — nó ngăn người sau dựng lại. Nhận diện bằng **quy ước của repo**:
 * removal record luôn dẫn số issue. Nên một lần nhắc được miễn nếu có `#1234`
 * hoặc một cụm "đã gỡ / removed / không còn" trong **cửa sổ ±2 dòng** — cửa sổ
 * chứ không phải đúng dòng, vì văn bản xuống dòng và neo hay rơi sang dòng kế.
 * Cùng tinh thần với luật "nhắc tên trong backtick không có neo" ở
 * `ArtisanCommandReferencesExistTest`.
 */
it('#2483 — docs sống không dạy API/cờ đã gỡ, và không dùng slug vai không tồn tại', function () {
    /** @var array<string, string> định danh đã chết ⇒ thay bằng gì */
    $removed = [
        'scopedTo(' => '#1568 — dùng scopedToKey(key, id); chữ ký nhận Model đã gỡ',
        'use_audience' => 'plan-023 M1 — cờ đã gỡ, đường audience chạy vô điều kiện',
        'NOTIFICATION_USE_AUDIENCE' => 'plan-023 M1 — cờ đã gỡ',
        "'shop_manager'" => "#2451 — từ vựng thật là 'shop-manager' (gạch ngang)",
        "'brand_admin'" => "#2456 — brand không có vai riêng; dùng 'org-admin'",
        "'branch_admin'" => "#2456 — dùng 'shop-manager'",
        "'org_owner'" => "#2456 — dùng 'org-admin'",
    ];

    // Dấu hiệu một đoạn đang GHI LẠI việc gỡ, không phải hướng dẫn dùng.
    $removalRecord = ['đã gỡ', 'ĐÃ GỠ', 'removed', 'Removed', 'không còn', 'Không còn', 'KHÔNG còn',
        'chưa bao giờ tồn tại', 'không tồn tại', 'từng hỏi', 'đừng dựng lại'];

    $docsDir = base_path('../docs');
    expect(is_dir($docsDir))->toBeTrue('không thấy thư mục docs/ — bố cục repo đã đổi?');

    /** Miễn trừ nếu có neo (số issue) hoặc cụm removal trong cửa sổ ±2 dòng. */
    $isRemovalRecord = static function (array $lines, int $idx) use ($removalRecord): bool {
        for ($i = max(0, $idx - 2); $i <= min(count($lines) - 1, $idx + 2); $i++) {
            $line = $lines[$i];

            if (preg_match('/#\d{3,}/', $line) === 1) {
                return true;
            }

            foreach ($removalRecord as $marker) {
                if (str_contains($line, $marker)) {
                    return true;
                }
            }
        }

        return false;
    };

    $offenders = [];
    $filesScanned = 0;

    $files = new AppendIterator;
    $files->append(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($docsDir, FilesystemIterator::SKIP_DOTS)));
    // CLAUDE.md luôn được nạp vào context nên một câu sai ở đó đắt hơn mọi file
    // trong docs/ — quét luôn.
    $files->append(new ArrayIterator([new SplFileInfo(base_path('../CLAUDE.md'))]));

    /** @var SplFileInfo $file */
    foreach ($files as $file) {
        if (! $file->isFile() || ! in_array($file->getExtension(), ['md', 'json'], true)) {
            continue;
        }

        $real = (string) realpath($file->getPathname());
        $relative = str_starts_with($real, (string) realpath($docsDir))
            ? 'docs'.substr($real, strlen((string) realpath($docsDir)))
            : basename($real);
        $relative = str_replace('\\', '/', $relative);

        $filesScanned++;
        $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES) ?: [];

        foreach ($lines as $n => $line) {
            if ($isRemovalRecord($lines, $n)) {
                continue;
            }

            foreach ($removed as $needle => $why) {
                if (str_contains($line, $needle)) {
                    $offenders[] = "{$relative}:".($n + 1)."  →  {$needle}\n      {$why}";
                }
            }
        }
    }

    // Chống rào rỗng: nếu docs/ dời chỗ hay phần mở rộng đổi, khẳng định dưới sẽ
    // xanh vĩnh viễn mà không canh gì — đúng bẫy ratchet đã mắc ở
    // ZDomainMutationBaselineTest.
    expect($filesScanned)->toBeGreaterThan(30, 'Rào không quét thấy file docs nào — bố cục đã đổi.');

    expect($offenders)->toBe([], implode("\n", array_merge(
        ['Tài liệu SỐNG đang dạy một định danh đã bị gỡ (#2483):', ''],
        $offenders,
        ['', 'Sửa dòng đó cho đúng hiện trạng, HOẶC viết lại nó thành removal record'],
        ['("… đã gỡ ở #xxxx") nếu ý là ghi lại để người sau đừng dựng lại.'],
    )));
});
