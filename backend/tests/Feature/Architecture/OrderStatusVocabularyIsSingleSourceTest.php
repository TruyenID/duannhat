<?php

declare(strict_types=1);

/**
 * #1664 — tập trạng thái "đang mở" của đơn được khai ĐÚNG MỘT LẦN.
 *
 * ## Vì sao gỡ bản sao là chưa đủ
 *
 * #1596 lập `OrderStatusVocabulary::OPEN` làm nguồn duy nhất và ghi hẳn vào
 * docblock rằng *"`CustomerOrder::OPEN_STATUSES` giờ trỏ về đây, nên vẫn còn
 * đúng một nguồn"*. Rồi #1647 vẫn chép ra bản thứ hai — **cùng thư mục**, bảy
 * phần tử y hệt, cùng thứ tự — vì người viết cần tập đó nằm trên một hợp đồng
 * công bố và không biết nó đã nằm sẵn ở đó.
 *
 * Nghĩa là: một docblock nói "đây là nguồn duy nhất" **không giữ được** tính
 * duy nhất. #1664 gỡ bản sao ấy, nhưng nếu chỉ gỡ thì lần sau lại thế. File này
 * là cái kêu.
 *
 * ## Hỏng thế nào khi trôi
 *
 * Ordering thêm một trạng thái đang-mở (ví dụ `held`). Người sửa cập nhật bản
 * mình đang nhìn; bản kia đứng im. Từ đó ca thu ngân bỏ sót mọi đơn `held` khỏi
 * danh sách **đơn chưa trả** — lệch tiền, và lệch trong im lặng.
 *
 * ## ALIAS thì được, CHÉP thì không
 *
 * `CustomerOrder::OPEN_STATUSES = OrderStatusVocabulary::OPEN;` là một **tham
 * chiếu**: sửa nguồn thì nó theo, nên nó không trôi được. Test này chỉ cấm
 * **mảng chữ** — thứ duy nhất trôi được.
 */

use Illuminate\Support\Facades\File;

/**
 * Bốn trạng thái đủ để nhận ra tập ĐANG MỞ.
 *
 * Không dùng cả bảy: một bản chép thiếu một phần tử (đúng ca trôi mà test này
 * sinh ra để bắt) vẫn phải bị bắt. Bốn cái này không cùng xuất hiện ở tập nào
 * khác trong repo.
 */
const OPEN_STATUS_MARKERS = ['awaiting_confirmation', 'dining', 'checkout', 'paying'];

/** File DUY NHẤT được phép khai mảng chữ đó. */
const OPEN_STATUS_SOURCE = 'app/Services/Order/Contracts/OrderStatusVocabulary.php';

it('#1664 chỉ OrderStatusVocabulary khai mảng chữ của tập trạng thái đang-mở', function () {
    $offenders = [];

    foreach (File::allFiles(base_path('app')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace(base_path().'/', '', $file->getPathname());
        if ($relative === OPEN_STATUS_SOURCE) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        // Chỉ mảng KHÔNG LỒNG (`[^\[\]]*`). Đó đúng là hình dạng của một danh
        // sách trạng thái, và nó tự loại `CustomerOrderStatusEnum::LABELS` —
        // mảng lồng, và có chứa `closed`/`voided` nên không phải tập đang-mở.
        preg_match_all('/\[[^\[\]]*\]/s', $source, $matches);

        foreach ($matches[0] as $literal) {
            $hasAll = true;
            foreach (OPEN_STATUS_MARKERS as $marker) {
                if (! str_contains($literal, "'".$marker."'")) {
                    $hasAll = false;
                    break;
                }
            }

            // Có `closed`/`voided` ⇒ đây là danh sách TOÀN BỘ trạng thái, không
            // phải tập con "đang mở". Bảng nhãn của enum rơi vào đây.
            if ($hasAll && ! str_contains($literal, "'closed'") && ! str_contains($literal, "'voided'")) {
                $offenders[] = $relative;
                break;
            }
        }
    }

    $message = "Tập trạng thái ĐANG MỞ bị khai lại bằng mảng chữ ở:\n  "
        .implode("\n  ", array_unique($offenders))
        ."\n\nDùng `OrderStatusVocabulary::OPEN` (hoặc alias `= OrderStatusVocabulary::OPEN`).\n"
        ."Bản chép không sai gì cho tới lần Ordering thêm một trạng thái — rồi ca thu ngân\n"
        .'bỏ sót đơn khỏi danh sách chưa trả, âm thầm (#1664 · #1596 · #1647).';

    expect(array_values(array_unique($offenders)))->toBe([], $message);
});

it('#1664 rào trên thật sự BẮT được một bản chép — tự kiểm phép đo', function () {
    // Một test quét-nguồn có thể xanh vì regex không khớp gì cả, chứ không phải
    // vì cây mã sạch. Cho nó một bản chép giả để chứng minh nó nhìn thấy.
    $fake = <<<'PHP'
    <?php
    class Whatever {
        public const OPEN = ['pending', 'awaiting_confirmation', 'confirmed', 'open', 'dining', 'checkout', 'paying'];
    }
    PHP;

    preg_match_all('/\[[^\[\]]*\]/s', $fake, $matches);

    $matched = false;
    foreach ($matches[0] as $literal) {
        $hasAll = true;
        foreach (OPEN_STATUS_MARKERS as $marker) {
            if (! str_contains($literal, "'".$marker."'")) {
                $hasAll = false;
                break;
            }
        }
        if ($hasAll && ! str_contains($literal, "'closed'")) {
            $matched = true;
        }
    }

    expect($matched)->toBeTrue('rào #1664 không nhận ra một bản chép nguyên vẹn — phép đo hỏng');
});
