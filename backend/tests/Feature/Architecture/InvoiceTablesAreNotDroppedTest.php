<?php

/*
 * #1797 — bốn bảng hoá đơn KHÔNG được xoá bằng migration.
 *
 * ## Bối cảnh
 *
 * #1779 (PR #1791) gỡ toàn bộ đường tạo hoá đơn: xoá YAML, xoá module, xoá
 * migration CREATE. Nhưng bảng trên staging/production VẪN CÒN, kèm dữ liệu.
 * Lượt regen đầu tiên sau đó sinh ra bốn migration `dropIfExists(...)`; chúng
 * KHÔNG được commit (#1784), và sau khi `.omnify/schemas.json` được đồng bộ thì
 * regen thôi đề nghị xoá — đã đo bằng `omnify diff`: *No changes detected*.
 *
 * ## Vì sao GIỮ chứ không xoá
 *
 * Hoá đơn là **chứng từ pháp định**, không phải dữ liệu ứng dụng:
 *
 *   - Nhật: 帳簿書類 lưu 7 năm (một số loại 10), và 電子帳簿保存法 đòi giữ ở
 *     dạng gốc điện tử — xoá bảng là xoá bản gốc đó.
 *   - Việt Nam: chứng từ kế toán lưu 10 năm (Luật Kế toán).
 *
 * Không code nào còn đọc bốn bảng này (đã đo: 0 tham chiếu trong `app/`), nên
 * giữ lại không tốn gì ngoài dung lượng. Đổi lại, xoá nhầm là mất chứng từ và
 * KHÔNG khôi phục được từ chỗ khác trong hệ thống.
 *
 * Cân nhắc này bất đối xứng đến mức không cần đợi ai chốt: chi phí giữ là gần 0,
 * chi phí xoá nhầm là một nghĩa vụ pháp lý không hoàn thành được.
 *
 * ## Rào này canh gì
 *
 * Một migration mới — dù do regen sinh ra trong một trạng thái nào đó, hay do
 * người viết tay khi "dọn dẹp bảng không dùng" — mà xoá một trong bốn bảng.
 * Đó chính xác là loại việc trông vô hại: không code nào đọc chúng, nên mọi
 * test khác vẫn xanh sau khi xoá.
 *
 * Nếu ngày nào đó thật sự phải xoá (hết thời hạn lưu trữ, đã trích xuất ra kho
 * lưu trữ riêng), hãy xoá tên khỏi `PROTECTED_TABLES` TRONG CÙNG commit với
 * migration đó — để lượt review nhìn thấy quyết định, thay vì thấy một file
 * migration lẫn trong đống generated.
 */

/** @var list<string> */
const PROTECTED_TABLES = [
    'customer_invoices',
    'customer_return_invoices',
    'vn_einvoice_settings',
    'vn_einvoice_transmissions',
];

/** @return list<string> đường dẫn mọi migration trong repo */
function allMigrationFiles(): array
{
    $files = [];
    foreach ([database_path('migrations'), base_path('packages')] as $root) {
        if (! is_dir($root)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php') && str_contains($file->getPathname(), 'migrations')) {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}

it('#1797 quét được migration thật — nếu không thì test dưới đo cái rỗng', function () {
    // Một danh sách rỗng sẽ khiến test chính xanh mãi mãi mà không kiểm gì.
    expect(count(allMigrationFiles()))->toBeGreaterThan(100);
});

it('#1797 KHÔNG migration nào xoá bảng hoá đơn', function () {
    $offenders = [];

    foreach (allMigrationFiles() as $path) {
        $source = (string) file_get_contents($path);

        foreach (PROTECTED_TABLES as $table) {
            // Ba cách xoá một bảng, rào phải thấy cả ba. Cách thứ ba (SQL thô)
            // là cách đi vòng qua mọi kiểm tra dựa trên Schema builder — đúng
            // bài học của `pre-commit`, vốn đã phải mở rộng để bắt DB::statement.
            $patterns = [
                "/Schema::dropIfExists\(\s*['\"]".preg_quote($table, '/')."['\"]/",
                "/Schema::drop\(\s*['\"]".preg_quote($table, '/')."['\"]/",
                '/DROP\s+TABLE\s+(IF\s+EXISTS\s+)?[`\'"]?'.preg_quote($table, '/').'/i',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $source) === 1) {
                    $offenders[] = basename($path).' → '.$table;
                    break;
                }
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", array_merge(
        ['Migration dưới đây XOÁ bảng chứng từ hoá đơn:'],
        array_map(static fn (string $o): string => '  '.$o, $offenders),
        [
            '',
            'Hoá đơn là chứng từ pháp định: Nhật 帳簿書類 7 năm (電子帳簿保存法 đòi',
            'giữ dạng gốc điện tử), Việt Nam chứng từ kế toán 10 năm.',
            '',
            'Không code nào còn đọc chúng, nên xoá đi mọi test khác VẪN XANH — đó là',
            'lý do rào này tồn tại.',
            '',
            'Nếu thật sự đã hết thời hạn lưu trữ: xoá tên bảng khỏi PROTECTED_TABLES',
            'trong CÙNG commit với migration, để review nhìn thấy quyết định.',
        ],
    )));
});
