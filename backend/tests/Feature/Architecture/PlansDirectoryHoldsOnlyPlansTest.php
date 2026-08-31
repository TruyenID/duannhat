<?php

declare(strict_types=1);

/**
 * Một plan là một THƯ MỤC `plans/plan-NNN/`. Mọi phép quét trạng thái plan — kể cả
 * của `tal` — liệt kê thư mục rồi đọc `README.md` frontmatter, nên một file
 * `plans/plan-<gì-đó>.md` nằm cạnh chúng là **vô hình với mọi phép đếm**.
 *
 * Nó vô hình theo hướng xấu nhất: với người đọc `plans/` thì nó trông y hệt một
 * plan, còn với công cụ thì nó không tồn tại. Ba file đã sống như vậy nhiều
 * tháng (#1900); hai trong số đó mô tả tính năng ĐÃ SHIP nhưng một cái vẫn tự
 * ghi "Final — chờ implement", nên bất kỳ ai mở ra đọc cũng kết luận sai.
 *
 * Rào này chỉ cấm cái bẫy đó: **file tên `plan-*.md` đặt thẳng trong `plans/`**.
 *
 * Nó KHÔNG cấm mọi file `.md` ở đó. `plans/README.md` là chỉ mục, và
 * `plans/material-system-deep-dive.md` là tài liệu tham chiếu (`status: reference`)
 * mà 13 plan khác trỏ tới bằng đường dẫn tương đối — dời nó chỉ tạo ra 13 liên
 * kết chết mà không sửa được gì, vì một tài liệu tham chiếu không mang trạng
 * thái nào để "đóng". Cái tên `plan-` mới là thứ đánh lừa.
 *
 * Chỗ đúng cho một bản ghi thiết kế: `docs/explanation/`.
 */
it('không có file plan-*.md nào nằm thẳng trong plans/ — plan phải là thư mục', function () {
    $plansDir = base_path('../plans');

    expect(is_dir($plansDir))->toBeTrue("không tìm thấy thư mục plans/ tại {$plansDir}");

    $strays = array_map(
        static fn (string $path): string => 'plans/'.basename($path),
        glob($plansDir.'/plan-*.md') ?: [],
    );

    sort($strays);

    expect($strays)->toBe([], $strays === [] ? '' : implode("\n", [
        'File trông như plan nhưng KHÔNG được phép quét plan nào nhìn thấy:',
        '  '.implode("\n  ", $strays),
        '',
        'Một plan chạy được là thư mục plans/plan-NNN/ có README.md + TASKS.md.',
        'Một bản ghi thiết kế thuộc về docs/explanation/ và không nên mang tiền tố "plan-".',
    ]));
});
