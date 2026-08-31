<?php

declare(strict_types=1);

/**
 * #2856 — hai cột `customer_orders.split_mode` / `split_people_count` là
 * TRÌNH BÀY, không phải quyết định.
 *
 * # Vì sao cần một rào riêng cho hai cột này
 *
 * Ruling chủ dự án 2026-08-15: **chia bill chỉ là chia HÌNH THỨC THANH TOÁN,
 * cấm tuyệt đối đổi order.** Thiết kế hiện tại tuân thủ — `setSplitModeFields()`
 * ghi đúng hai cột đó và không chạm dòng món, `total_amount`, thuế hay status;
 * tiền vẫn là N dòng `order_payments` trên MỘT đơn.
 *
 * Chỗ nó từng rò là chỗ ĐỌC. `POST /customer/orders/{id}/split-mode` **công
 * khai có chủ đích** (chú thích tại route: `auth:customer` sẽ làm hỏng luồng
 * guest counter-pay), nên hai cột này là thứ **khách đặt được**. Một vế
 * `$order->split_mode === null` từng đứng trong rào "walk-in phải trả đủ", tức
 * một thao tác của khách gỡ được một luật TIỀN — và không ai ở quầy thấy.
 *
 * # Rào này canh cái gì
 *
 * Danh sách chỗ đọc, kèm LÝ DO từng chỗ. Thêm một chỗ đọc mới thì phải khai vào
 * đây, và chính lúc khai là lúc phải trả lời câu hỏi duy nhất đáng hỏi:
 *
 *   *Chỗ này TRÌNH BÀY một thoả thuận giữa các khách, hay nó QUYẾT ĐỊNH một
 *   chuyện về tiền / trạng thái đơn?*
 *
 * Vế sau không được phép — giá trị do khách đặt không gác được luật tiền.
 *
 * Rào đi CẢ HAI CHIỀU có chủ ý: danh sách thừa cũng đỏ. Một rào chỉ biết kêu mà
 * không biết im sẽ kêu oan, và rào kêu oan thì không bị tranh luận — nó bị TẮT.
 */
it('mọi chỗ đọc split_mode cấp ĐƠN đều được khai kèm lý do', function () {
    /**
     * file => vì sao chỗ này được phép đọc.
     *
     * Luật: mọi mục ở đây phải là TRÌNH BÀY hoặc ĐIỀU PHỐI giữa các khách.
     * Không mục nào được quyết định về tiền.
     */
    $allowed = [
        // Trình bày: chia đều thì hiện "mỗi người bao nhiêu" trên màn kiosk.
        'app/Http/Resources/KioskOrderResource.php' => 'trình bày amount_per_person',

        // Trình bày: phát cột ra payload cho client hiển thị.
        'app/Http/Resources/CustomerOrderResource.php' => 'phát cột ra payload',

        // Điều phối: khách thứ hai mở máy phải thấy CÙNG con số khách thứ nhất
        // vừa chọn. Đây đúng là công dụng của hai cột — một tờ nháp chung.
        'app/Http/Controllers/Api/V1/Customer/CustomerOrderSplitStatusController.php' => 'điều phối giữa các khách',

        // Đường GHI + phản hồi lại chính giá trị vừa ghi.
        'app/Http/Controllers/Api/V1/Customer/CustomerOrderController.php' => 'đường ghi /split-mode',

        // Trình bày, muộn: đóng dấu con số đã thoả thuận lên metadata của khoản
        // thanh toán để /split-status của khách sau đọc ra khoá cứng thay vì
        // một con số tạm. Nó KHÔNG quyết định khoản này có được nhận hay không
        // — rào walk-in ở dòng ~389 chạy TRƯỚC chỗ gọi này (dòng ~435).
        'app/Services/Customer/OrderPaymentService.php' => 'đóng dấu metadata SAU rào',
    ];

    $root = base_path();

    $found = [];
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app'));

    foreach ($rii as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace($root.'/', '', $file->getPathname());

        // Code generated là ánh xạ cột thuần (fillable, casts, resource base) —
        // nó không "đọc để quyết định" gì cả, và nó bị regen ghi đè nên khai vào
        // danh sách chỉ tạo ra nhiễu mỗi lượt regen.
        if (str_starts_with($path, 'app/Omnify/')) {
            continue;
        }

        $src = (string) file_get_contents($file->getPathname());

        // Chỉ tính chỗ đọc THUỘC TÍNH trên một order, không tính chuỗi tên cột
        // trong mảng ghi/khai báo — thứ sau không quyết định gì.
        if (preg_match('/->split_mode|->split_people_count/', $src) === 1) {
            $found[] = $path;
        }
    }

    sort($found);
    $declared = array_keys($allowed);
    sort($declared);

    $undeclared = array_diff($found, $declared);
    $stale = array_diff($declared, $found);

    expect($undeclared)->toBe([], sprintf(
        "Chỗ đọc `split_mode`/`split_people_count` cấp ĐƠN chưa được khai:\n  %s\n\n".
        "Hai cột này KHÁCH ĐẶT ĐƯỢC (`POST /customer/orders/{id}/split-mode` không có `auth:customer`).\n".
        "Nếu chỗ đọc mới chỉ TRÌNH BÀY thì khai vào \$allowed kèm lý do.\n".
        "Nếu nó QUYẾT ĐỊNH chuyện tiền hay trạng thái đơn thì nó SAI — đọc tín hiệu\n".
        'theo từng giao dịch (`order_payments.metadata.split_mode`, do POS gửi) thay vì cột trên đơn.',
        implode("\n  ", $undeclared),
    ));

    expect($stale)->toBe([], sprintf(
        "Danh sách thừa — các file này không còn đọc hai cột đó:\n  %s\n\nXoá khỏi \$allowed.",
        implode("\n  ", $stale),
    ));
});

it('rào walk-in không đọc cột do khách đặt', function () {
    // Bài trên canh TẬP HỢP file. Bài này canh đúng CÂU đã gây ra sự cố — vì
    // `OrderPaymentService.php` nằm trong danh sách được phép (nó có một chỗ
    // đọc hợp lệ ở tầng đóng dấu metadata), nên bài trên KHÔNG bắt được việc vế
    // order-level bị dựng lại ngay bên trong rào tiền.
    $src = (string) file_get_contents(
        base_path('app/Services/Customer/OrderPaymentService.php')
    );

    $guard = strpos($src, 'walkin_partial_payment_blocked');
    expect($guard)->not->toBeFalse('không tìm thấy rào walk-in — đổi tên mã lỗi thì phải sửa bài này');

    // Cửa sổ tính NGƯỢC từ mã lỗi lên đầu khối `if`, nên nó bám vào chính điều
    // kiện chứ không bám vào số dòng.
    $ifStart = strrpos(substr($src, 0, $guard), 'enforce_walkin_full_payment');
    expect($ifStart)->not->toBeFalse();

    $condition = substr($src, (int) $ifStart, $guard - (int) $ifStart);

    // `expect(...)->not->toContain($a, $b)` KHÔNG nhận thông điệp ở tham số thứ
    // hai — `toContain()` nhận NHIỀU NEEDLE, nên `$b` bị đọc thành needle và phủ
    // định chỉ cần thiếu MỘT cái là qua. Bài này từng viết như vậy và **xanh oan
    // ngay cả khi lỗi #2856 được dựng lại nguyên vẹn**; chỉ có thử-ngược mới lộ.
    // Dùng `str_contains` + `toBeFalse($message)` để có đúng một needle và một
    // thông điệp.
    expect(str_contains($condition, '$order->split_mode'))->toBeFalse(
        "Rào walk-in đang đọc `\$order->split_mode` — cột KHÁCH ĐẶT ĐƯỢC.\n".
        "Đó chính là lỗ #2856: khách gọi `/customer/orders/{id}/split-mode` một lần là\n".
        "đơn thoát khỏi luật 'walk-in phải trả đủ', thứ tồn tại vì khoản thiếu trên đơn\n".
        "không có `customer_id` thì không có khoá nào tra lại và nó làm hỏng đối soát ca.\n".
        "Tín hiệu đúng là `\$data['metadata']['split_mode']` — theo từng giao dịch, do POS gửi."
    );
});
