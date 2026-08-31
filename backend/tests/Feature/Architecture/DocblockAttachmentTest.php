<?php

declare(strict_types=1);

/**
 * Bắt DOCBLOCK MỒ CÔI: một docblock kết thúc rồi một docblock khác bắt đầu ngay,
 * tức có người chèn thứ gì đó vào GIỮA một docblock và thành viên nó mô tả — hoặc
 * viết docblock thứ hai cho cùng một thành viên thay vì gộp vào cái đã có.
 *
 * Vì sao đáng một test riêng: lỗi này im lặng theo đúng kiểu tệ nhất. PHP không
 * quan tâm, `pint` không sửa (hai docblock liền nhau là style hợp lệ), không test
 * nào đỏ — nhưng lời giải thích *"vì sao đoạn này không phải việc thường"* rời
 * khỏi thành viên nó thuộc về và bám vào một thành viên khác. Người đọc sau tin
 * nhầm docblock, hoặc thấy hàm gốc trần trụi rồi tưởng nó không cần giải thích.
 * Trong repo này docblock load-bearing là chuyện thật: nhiều cái ghi ĐÚNG lý do
 * một quyết định không được đảo.
 *
 * Nó xảy ra BỐN lần trong một phiên làm việc, mỗi lần đều lọt qua pint và qua
 * toàn bộ suite — kể cả một lần rơi trúng `LegacyRemovalReadiness`, nơi docblock
 * giải thích "vì sao cổng này không phải việc thường" bị dời sang một cổng khác
 * có `preconditions` rỗng.
 */

/**
 * Ân xá: nợ CÓ SẴN, ghim theo FILE + SỐ LƯỢNG chứ không theo dòng.
 *
 * Theo dòng thì mọi lần sửa file làm test đỏ oan và người ta học cách bỏ qua nó.
 * Theo file + số lượng thì xê dịch không sao, mà thêm một ca mới vào file đã có
 * tên vẫn bị bắt.
 *
 * 18 file đã sửa cơ giới (dời docblock về đúng thành viên, đã chứng minh bằng
 * so token là KHÔNG đổi mã). 13 ca dưới đây có hình dạng KHÁC: hai docblock cùng
 * mô tả một thành viên, nên sửa đúng là GỘP, và gộp cần phán đoán từng ca —
 * không cơ giới hoá được, và các file này đang có người khác sửa song song.
 *
 * Danh sách CHỈ ĐƯỢC CO LẠI. Số nhỏ hơn thực tế cũng làm test đỏ, kèm hướng dẫn.
 */
const DOCBLOCK_ORPHAN_GRANDFATHER = [
    'app/Services/Customer/BranchReviewService.php' => 1,
    'app/Services/Customer/OrderPaymentService.php' => 1,
    'app/Services/FileUploadService.php' => 2,
    'app/Services/Notification/Audience.php' => 2,
    'app/Services/Order/Commands/CheckoutOrderCommand.php' => 1,
    'app/Services/Order/Internal/Concerns/WritesCustomerOrders.php' => 1,
    'app/Services/Payment/Terminal/StripeTerminalService.php' => 1,
    'app/Services/Pos/TillSessionService.php' => 1,
    'app/Services/Product/MenuService.php' => 3,
];

it('không có docblock mồ côi mới nào', function () {
    $counts = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $lines = explode("\n", (string) file_get_contents($file->getPathname()));
        $relative = str_replace(base_path().'/', '', $file->getPathname());

        foreach ($lines as $i => $line) {
            if (trim($line) !== '*/') {
                continue;
            }

            // Khối đang đóng phải là DOCBLOCK. Một comment thường `/* … */` đứng
            // trước docblock là hợp lệ — ghi chú thiết kế đặt cạnh nhóm thành
            // viên. Bỏ phân biệt này thì test báo oan, và tôi đã tin nó một lần
            // rồi suýt dời nhầm một hàm vì thế.
            $start = $i;
            while ($start > 0 && ! str_starts_with(ltrim($lines[$start]), '/*')) {
                $start--;
            }

            if (! str_starts_with(ltrim($lines[$start]), '/**')) {
                continue;
            }

            $j = $i + 1;
            while (isset($lines[$j]) && trim($lines[$j]) === '') {
                $j++;
            }

            if (! isset($lines[$j]) || ! str_starts_with(trim($lines[$j]), '/**')) {
                continue;
            }

            // Miễn trừ CÓ KHAI BÁO cho khối chú giải cố ý đứng độc lập.
            if (str_contains(implode("\n", array_slice($lines, $start, $i - $start + 1)), '@standalone-note')) {
                continue;
            }

            $counts[$relative] = ($counts[$relative] ?? 0) + 1;
        }
    }

    ksort($counts);
    $expected = DOCBLOCK_ORPHAN_GRANDFATHER;
    ksort($expected);

    expect($counts)->toBe($expected,
        "Docblock mồ côi thay đổi so với danh sách ân xá.\n"
        ."• File MỚI xuất hiện: dời docblock về trước đúng thành viên nó mô tả,\n"
        ."  hoặc GỘP nếu cả hai cùng mô tả một thành viên. Đừng thêm vào danh sách.\n"
        ."• Số GIẢM: tốt — cập nhật DOCBLOCK_ORPHAN_GRANDFATHER cho khớp.\n"
        .'• Khối cố ý đứng độc lập: khai `@standalone-note` kèm lý do.');
});
