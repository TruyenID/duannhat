<?php

declare(strict_types=1);

use App\Services\Order\Enums\OrderSplitMode;

/**
 * #2860 — MỘT từ vựng chia bill, và nó phải ở lại một.
 *
 * # Vì sao cần rào chứ không chỉ cần một lượt đổi tên
 *
 * Bảy cách viết cho ba khái niệm không sinh ra trong một ngày. Chúng sinh ra vì
 * mỗi lần có người viết một đầu mới — kiosk, đường Stripe, renderer in — họ gõ
 * tay một tập giá trị vào `validate()` hoặc vào một `match`, và **không gì đỏ**.
 * Hai tập giao nhau đúng một giá trị và sống chung nhiều tháng.
 *
 * Nên thứ phải canh không phải "tên hiện tại có đúng không" (một lượt sửa là
 * xong) mà **"có ai đang định nghĩa từ vựng ở nơi không phải enum không"**.
 *
 * # Hai chiều
 *
 * Rào kêu khi có tên cũ mọc lại, và **im** khi mọi thứ đúng. Chiều im được kiểm
 * bằng chính danh sách miễn trừ: khai thừa cũng đỏ. Rào chỉ biết kêu sẽ kêu oan,
 * và rào kêu oan thì không bị tranh luận — nó bị TẮT.
 */
it('không nơi nào ngoài normalizer nhắc tên cũ', function () {
    /**
     * Bốn tên cũ. `even` không có ở đây vì nó là canonical; `by_items` và
     * `by_amount` chưa bao giờ trôi.
     */
    $legacy = ['equal', 'by_people', 'split_even', 'custom'];

    /**
     * file => vì sao được phép nhắc tên cũ.
     *
     * Luật: chỉ hai loại được vào đây — chỗ DỊCH tên cũ sang canonical, và chỗ
     * ghi lại HỒ SƠ của lượt chuyển đổi. Không chỗ nào được QUYẾT ĐỊNH gì dựa
     * trên một tên cũ.
     */
    $allowed = [
        'app/Services/Order/Enums/OrderSplitMode.php' => 'chính normalizer',
    ];

    $root = base_path();
    $found = [];

    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app'));
    foreach ($rii as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace($root.'/', '', $file->getPathname());

        // Code generated ánh xạ cột thuần và bị regen ghi đè; khai vào danh sách
        // chỉ tạo nhiễu mỗi lượt regen.
        if (str_starts_with($path, 'app/Omnify/')) {
            continue;
        }

        $src = (string) file_get_contents($file->getPathname());

        // Chỉ tính chuỗi ĐÚNG NGUYÊN — `'custom'` là một từ tiếng Anh thường
        // gặp, nên quét lỏng sẽ bắt nhầm `custom_fields`, `isCustom`… và một rào
        // bắt nhầm là một rào sắp bị tắt.
        foreach ($legacy as $name) {
            if (str_contains($src, "'{$name}'") || str_contains($src, "\"{$name}\"")) {
                $found[$path] = true;
                break;
            }
        }
    }

    $found = array_keys($found);
    sort($found);
    $declared = array_keys($allowed);
    sort($declared);

    expect(array_diff($found, $declared))->toBe([], sprintf(
        "Tên cũ của chia bill xuất hiện ngoài normalizer:\n  %s\n\n".
        "Từ vựng là `even` · `by_items` · `by_amount` — một bộ, mọi tầng, mọi app.\n".
        "Tên cũ (`equal`, `by_people`, `split_even`, `custom`) CHỈ được sống ở\n".
        "`OrderSplitMode::WIRE_ALIASES`, và chỉ theo chiều VÀO: thiết bị ngoài\n".
        "hiện trường không tự cập nhật nên chúng còn gửi tên cũ một thời gian.\n\n".
        'Cần so sánh một chế độ? Dùng `OrderSplitMode::Even` chứ đừng gõ chuỗi.',
        implode("\n  ", array_diff($found, $declared)),
    ));

    expect(array_diff($declared, $found))->toBe([], sprintf(
        "Danh sách miễn trừ thừa — các file này không còn nhắc tên cũ:\n  %s\n\nXoá khỏi \$allowed.",
        implode("\n  ", array_diff($declared, $found)),
    ));
});

it('mọi validate() của split_mode sinh luật TỪ enum, không gõ tay', function () {
    // Đây là cơ chế đã đẻ ra cả vấn đề: hai `validate()` gõ tay hai tập khác
    // nhau. Chừng nào luật còn được gõ tay thì một cái thứ ba sẽ xuất hiện.
    $root = base_path();
    $offenders = [];

    foreach ([$root.'/app/Http/Requests', $root.'/app/Http/Controllers'] as $dir) {
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($rii as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }

            $src = (string) file_get_contents($file->getPathname());

            // Một luật gõ tay đứng cạnh một khoá `split_mode`/`split_type`.
            //
            // Tiền tố `metadata.` là BẮT BUỘC phải khớp: khoá thật ở
            // `OrderPaymentStoreRequest` và `KioskController` là
            // `'metadata.split_mode'`, không phải `'split_mode'`. Bản đầu của
            // regex này đòi dấu nháy ngay trước `split_` nên **bỏ qua đúng hai
            // validator đã gây ra cả vấn đề** — bài xanh oan, và chỉ thử ngược
            // mới lộ.
            //
            // #2865 — và phải bắt CẢ `Rule::in([...])`, không riêng chuỗi
            // `'in:...'`. Đo trên cây: `Rule::in(` xuất hiện **91** lần trong
            // `app/Http` so với 62 lần `'in:'`, tức nó là idiom CHIẾM ƯU THẾ —
            // một validator tương lai viết kiểu đó là hình dạng khả dĩ NHẤT của
            // lần tái phát, chứ không phải hình dạng hiếm. Tiêm
            // `Rule::in([..., 'bogus'])` vào bản trước: rào XANH.
            $key = "'(?:[\w.]*\.)?split_(?:mode|type)'";
            $handTyped = "(?:'in:[^']*'|Rule::in\()";

            if (preg_match("/{$key}\s*=>\s*\[[^\]]*{$handTyped}/s", $src) === 1) {
                $offenders[] = str_replace($root.'/', '', $file->getPathname());
            }
        }
    }

    sort($offenders);

    expect($offenders)->toBe([], sprintf(
        "Luật gõ tay (`in:` hoặc `Rule::in(`) cho split_mode/split_type:\n  %s\n\n".
        "Dùng `OrderSplitMode::validationRule()`. Hai validator gõ tay hai tập\n".
        "khác nhau — giao nhau đúng MỘT giá trị — là cách bảy cách viết cho ba\n".
        'khái niệm sống được nhiều tháng mà không gì đỏ.',
        implode("\n  ", $offenders),
    ));
});

it('enum đúng ba chế độ, khớp chuẩn ngành', function () {
    // Square · Toast · Lightspeed · Clover đều dừng ở đúng ba: split evenly ·
    // split by item · split by amount. Thêm cái thứ tư là một quyết định sản
    // phẩm, không phải một lượt refactor — nên nó phải đi qua chỗ này.
    expect(array_column(OrderSplitMode::cases(), 'value'))
        ->toBe(['even', 'by_items', 'by_amount']);
});
