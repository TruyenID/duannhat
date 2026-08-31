<?php

declare(strict_types=1);

/**
 * #2872 — sau khi ĐÃ RELEASE, một migration phá huỷ phải mang dấu vết XIN PHÉP.
 *
 * # Vì sao rào này tồn tại
 *
 * Ruling #2188 cho phép "xoá dữ liệu cũ, khoá cột sang NOT NULL, gỡ lệnh backfill
 * sau một lần chạy" — và lý lẽ nền của nó là **sản phẩm chưa release**, tức dữ
 * liệu cũ chỉ là seed tái tạo được.
 *
 * Chủ dự án xác nhận 2026-08-15: sản phẩm **đã release**. Cùng câu chữ ấy nay
 * cho phép thao tác phá huỷ trên **tiền thật của quán**, và nó cho phép một cách
 * im lặng: người viết migration đọc ruling, thấy được phép, và không có gì đỏ.
 *
 * Phạm vi chốt lại (#2872 phương án 2): giữ vế CẤM, bỏ vế CHO PHÉP. Rào này là
 * phần máy của vế thứ hai — chữ trong CLAUDE.md không tự chặn được ai.
 *
 * # Nó canh gì, và cố ý KHÔNG canh gì
 *
 * Chỉ canh migration **mới thêm** mang thao tác phá huỷ, và chỉ đòi **một dòng
 * khai** trong chính file đó. Nó KHÔNG phán xét thao tác đúng hay sai — không
 * rào tĩnh nào làm được điều đó. Nó chỉ bắt người viết dừng lại một nhịp và ghi
 * ra ai đã đồng ý, để lần sau còn tra được.
 *
 * Rào đòi giải trình thì phải RẺ, nếu không nó bị lách bằng cách viết DDL ở chỗ
 * khác. Một dòng là đủ rẻ.
 */
/**
 * Thân hàm `up()` của một migration, hoặc chuỗi rỗng nếu không tìm thấy.
 *
 * Cắt thô bằng đếm ngoặc thay vì parse: file migration có hình dạng cố định và
 * một bộ parse đầy đủ ở đây là chi phí không đổi lấy được gì. Không tìm thấy
 * `up()` thì trả rỗng — tức KHÔNG kết tội, vì rào này chỉ được kêu khi nó THẤY
 * thứ nó hiểu.
 */
function upBody(string $src): string
{
    $at = strpos($src, 'function up(');
    if ($at === false) {
        return '';
    }

    $open = strpos($src, '{', $at);
    if ($open === false) {
        return '';
    }

    $depth = 0;
    $len = strlen($src);

    for ($i = $open; $i < $len; $i++) {
        if ($src[$i] === '{') {
            $depth++;
        } elseif ($src[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $open, $i - $open + 1);
            }
        }
    }

    return substr($src, $open);
}

/**
 * Quét MỘT nguồn migration → danh sách nhãn thao tác phá huỷ chưa được khai.
 *
 * Tách thành hàm thuần vì bài đo chiều KÊU không thể thả một file thăm dò vào
 * `database/migrations/`: bộ test chạy migration trước mỗi bài, nên một file
 * thăm dò cố tình sai cú pháp sẽ giết cả lượt chạy chứ không làm rào đỏ — lần
 * thử ngược đầu tiên trả về "2 failed (0 assertions)", tức đo nhầm thứ.
 *
 * @return list<string>
 */
function destructiveUnapproved(string $src): array
{
    $destructive = [
        '/->delete\(\)/' => 'xoá hàng',
        '/DB::table\([^)]*\)->truncate\(\)/' => 'truncate bảng',
        '/->dropColumn\(/' => 'drop cột',
        '/Schema::drop(IfExists)?\(/' => 'drop bảng',
        '/->nullable\(false\)/' => 'khoá NOT NULL',
        '/->change\(\)/' => 'đổi kiểu/ràng buộc cột',
    ];

    // Một dòng khai là đủ. Ba dạng vì ba cách người ta tự nhiên viết ra.
    $approval = '/#\d+\s*—?\s*(được duyệt|chủ dự án (đồng ý|duyệt|chốt))|CHỦ DỰ ÁN ĐỒNG Ý|APPROVED-BY:/iu';

    if (preg_match($approval, $src) === 1) {
        return [];
    }

    // Chỉ quét thân `up()`. `down()` là đường LÙI TAY, không nằm trên đường
    // deploy — `migrate --force` không bao giờ gọi nó. Quét cả file thì mọi
    // migration `create_*` của Laravel đều đỏ vì `down()` của chúng
    // `dropIfExists` bảng vừa tạo: đo thật ra 4/5 ca đầu là kêu oan. Một rào kêu
    // oan không bị tranh luận — nó bị TẮT.
    $up = upBody($src);

    $hits = [];
    foreach ($destructive as $re => $label) {
        if (preg_match($re, $up) === 1) {
            $hits[] = $label;
        }
    }

    return $hits;
}

it('#2872 migration phá huỷ phải khai người đồng ý', function () {
    $offenders = [];

    foreach (glob(base_path('database/migrations').'/*.php') ?: [] as $file) {
        $hits = destructiveUnapproved((string) file_get_contents($file));
        if ($hits !== []) {
            $offenders[] = basename($file).'  →  '.implode(', ', $hits);
        }
    }

    sort($offenders);

    expect($offenders)->toBe([], sprintf(
        "Migration mang thao tác phá huỷ mà không khai người đồng ý:\n  %s\n\n".
        "Sản phẩm ĐÃ RELEASE (#2872). Ruling #2188 vẫn cấm viết MỚI nhánh tương\n".
        "thích ngược, nhưng vế \"được xoá dữ liệu / khoá NOT NULL\" của nó dựa trên\n".
        "tiền đề \"chưa release\" — tiền đề đó hết đúng, nên thao tác phá huỷ nay\n".
        "phải XIN PHÉP TỪNG LẦN.\n\n".
        "Cách qua rào: hỏi chủ dự án, rồi ghi MỘT dòng vào docblock của migration,\n".
        "ví dụ `#1234 — chủ dự án đồng ý 2026-08-15`. Rào không phán xét thao tác\n".
        "đúng hay sai; nó chỉ bắt phải có chỗ tra ngược.",
        implode("\n  ", $offenders),
    ));
});

it('#2872 rào biết KÊU và biết IM', function (string $src, array $want, string $why) {
    // Chiều IM quan trọng ngang chiều KÊU. Một cây sạch cũng xanh khi regex hỏng
    // hoàn toàn — lúc đó rào không canh gì nữa mà vẫn trả lời "có" cho câu
    // "chỗ này đã được canh chưa".
    expect(destructiveUnapproved($src))->toBe($want, $why);
})->with([
    [
        "<?php\nclass M { public function up(): void { \$t->dropColumn('x'); } }",
        ['drop cột'],
        'drop cột trong up(), không khai → phải KÊU',
    ],
    [
        "<?php\n/** #9999 — chủ dự án đồng ý 2026-08-15. */\nclass M { public function up(): void { \$t->dropColumn('x'); } }",
        [],
        'cùng thao tác nhưng CÓ khai → phải IM',
    ],
    [
        "<?php\nclass M { public function up(): void { \$t->string('x'); }\n public function down(): void { \$t->dropColumn('x'); } }",
        [],
        'phá huỷ chỉ ở down() → phải IM, đó là đường lùi tay không nằm trên deploy',
    ],
    [
        "<?php\nclass M { public function up(): void { DB::table('a')->update(['b' => 1]); } }",
        [],
        'update giá trị là sửa chữa thường ngày → phải IM, nếu không mọi backfill vô hại đều phải xin phép',
    ],
    [
        "<?php\nclass M { public function up(): void { \$t->string('x')->nullable(false)->change(); } }",
        ['khoá NOT NULL', 'đổi kiểu/ràng buộc cột'],
        'khoá NOT NULL + change() → KÊU cả hai nhãn',
    ],
]);
