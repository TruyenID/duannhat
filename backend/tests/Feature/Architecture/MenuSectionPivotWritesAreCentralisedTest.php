<?php

declare(strict_types=1);

/**
 * #1661 — mọi lối GHI `menu_menu_sections` phải đi qua `MenuSectionPivotWriter`.
 *
 * ## Vì sao cần một rào thay vì "nhớ gọi markDirty"
 *
 * Bảng này khoá kép nên không observer nào bắt được nó: `belongsToMany` ghi bằng
 * query builder, và #1657 còn cấm hẳn việc ghi qua model. Nên thứ duy nhất giữ
 * cho bản catalog theo kịp là **kỷ luật của người viết code** — đúng loại thứ mà
 * docblock của `CatalogRevisionObserver` nói thẳng là nó tồn tại để KHỎI phải
 * dựa vào.
 *
 * #1218 đã thử cách "nhớ": nó đăng ký một observer cho model pivot kèm comment
 * giải thích rất kỹ vì sao cần. Dòng đó không làm gì cả suốt từ đó tới #1661, và
 * không có gì kêu. Test này là cái kêu.
 *
 * ## Nó đo GÌ
 *
 * Chỉ các động từ GHI (`sync` · `attach` · `detach` · `updateExistingPivot` ·
 * `syncWithoutDetaching` · `toggle`). Đọc thì vẫn tự do:
 * `$menu->menuSections()->whereKey($id)->exists()` là câu kiểm hợp lệ trong
 * `updateSectionTaxType` và không đụng gì tới bản catalog.
 */

use App\Services\Catalog\MenuSectionPivotWriter;
use Illuminate\Support\Facades\File;

/** Động từ nào của quan hệ là GHI. */
const PIVOT_WRITE_VERBS = [
    'sync',
    'syncWithoutDetaching',
    'attach',
    'detach',
    'toggle',
    'updateExistingPivot',
];

/**
 * File được phép ghi thẳng — chỉ chính writer.
 *
 * `MenuMenuSectionFactory` / seeder KHÔNG nằm trong danh sách: chúng không dùng
 * quan hệ `menuSections()`, nên regex dưới đây không chạm tới chúng.
 */
const PIVOT_WRITE_ALLOWLIST = [
    'app/Services/Catalog/MenuSectionPivotWriter.php',
];

it('#1661 không nơi nào ngoài MenuSectionPivotWriter ghi thẳng pivot menu↔section', function () {
    $verbs = implode('|', PIVOT_WRITE_VERBS);
    $pattern = '/menuSections\(\)\s*->\s*('.$verbs.')\s*\(/';

    $offenders = [];

    foreach (File::allFiles(base_path('app')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace(base_path().'/', '', $file->getPathname());
        if (in_array($relative, PIVOT_WRITE_ALLOWLIST, true)) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        if (preg_match_all($pattern, $source, $m) > 0) {
            $offenders[] = $relative.' → '.implode(', ', array_unique($m[1]));
        }
    }

    $message = "Ghi thẳng `menu_menu_sections` KHÔNG đi qua MenuSectionPivotWriter:\n  "
        .implode("\n  ", $offenders)
        ."\n\nBảng khoá kép ⇒ không sự kiện model nào bắn ra ⇒ bản catalog KHÔNG tiến\n"
        ."⇒ workstation nhận 304 và in theo thuế suất cũ (#1661). Dùng writer, hoặc\n"
        .'thêm vào PIVOT_WRITE_ALLOWLIST kèm lý do vì sao chỗ đó không cần đánh dấu.';

    // `toBe([])` chứ không phải `toBeEmpty()`: thông điệp phải LIỆT KÊ file, nếu
    // không người đọc lỗi phải tự đi tìm.
    expect($offenders)->toBe([], $message);
});

it('#1661 writer thật sự CÓ đủ ba động từ mà các service đang cần', function () {
    $writer = new ReflectionClass(MenuSectionPivotWriter::class);

    // Nếu một động từ bị đổi tên/xoá, test trên vẫn xanh (không ai gọi nữa) mà
    // đường ghi lại lặng lẽ mất chỗ đánh dấu. Ghim luôn mặt tiền.
    foreach (['sync', 'attach', 'updateExistingPivot'] as $method) {
        expect($writer->hasMethod($method))->toBeTrue("MenuSectionPivotWriter::{$method}() biến mất");
    }
});
