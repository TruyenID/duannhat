<?php

declare(strict_types=1);

/**
 * #1957 mảnh D — canh chính ALLOW-LIST, không phải canh giá trị so với nó.
 *
 * ## Lỗ hổng mà bài này bịt
 *
 * TR-21 nói definition **không bao giờ** mang URL, vì một URL tuỳ ý trong
 * definition sẽ khiến mọi máy trạm trong đội đi lấy một địa chỉ do kẻ tấn công
 * chọn (SSRF) rồi bơm byte thẳng vào máy in.
 *
 * Hôm nay luật đó được ép ở **một** chỗ: `TemplateValidator` từ chối
 * `SOURCE_NOT_ALLOWED` khi giá trị không nằm trong `print_blocks.sources`. Đó là
 * phòng thủ đúng — nhưng nó chỉ so giá trị VỚI danh sách. **Không ai canh chính
 * danh sách.**
 *
 * Nên TR-21 hiện đứng bằng một quy ước: người sửa config nhớ chỉ viết định danh.
 * Thêm một dòng `'https://cdn.example/x.png'` vào `sources` thì mọi cổng hiện có
 * đều xanh, và cái ranh giới duy nhất giữa hệ thống và SSRF là trí nhớ của người
 * mở PR. Mảnh D của #1957 chính là chỗ này: nó ghi *"quảng cáo/coupon sau này
 * thêm định danh mới vào CÙNG allow-list — không mở cửa cho URL"*, mà không có
 * gì cưỡng chế vế sau.
 *
 * ## Vì sao là kiểm HÌNH DẠNG chứ không phải danh sách cứng
 *
 * Một test khoá cứng tập giá trị hiện tại sẽ đỏ mỗi lần thêm nguồn hợp lệ, và
 * một test nào cũng phải sửa khi mở rộng thì cuối cùng sẽ bị sửa mà không ai
 * đọc — đúng cái bẫy đã ghi ở `ui-scale.test.ts`. Kiểm hình dạng thì im lặng với
 * `promo_banner`, `coupon_image`, và kêu với mọi thứ trông như một địa chỉ.
 */
it('TR-21 — mọi `source` là ĐỊNH DANH trần, không phải địa chỉ', function () {
    // snake_case thuần: không scheme, không dấu `/`, không dấu chấm, không khoảng
    // trắng. Mọi thứ trông như nơi-để-đi-lấy đều trượt.
    $identifier = '/^[a-z][a-z0-9_]{0,63}$/';

    $lists = [
        'print_blocks.sources' => (array) config('print_blocks.sources'),
        'print_blocks.image.sources' => (array) config('print_blocks.image.sources'),
    ];

    $offenders = [];

    foreach ($lists as $key => $sources) {
        foreach ($sources as $source) {
            if (! is_string($source) || preg_match($identifier, $source) !== 1) {
                $offenders[] = $key.' → '.var_export($source, true);
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", array_merge(
        ['`source` phải là định danh trần (snake_case). Vi phạm:'],
        $offenders,
        ['', 'Một URL ở đây biến TR-21 thành lời hứa suông: mọi máy trạm sẽ đi lấy'],
        ['địa chỉ đó và bơm byte vào máy in. Nếu cần dữ liệu mới, thêm một ĐỊNH DANH'],
        ['và cho renderer tự phân giải nó — đừng để definition mang địa chỉ.'],
    )));
});

it('rào này KÊU với mọi hình dạng địa chỉ đã biết', function () {
    // Kiểm chính cái rào: một rào chỉ đúng với ví dụ dựng sẵn là một rào chưa
    // được chứng minh. Đây là những thứ thật sự có thể lọt vào một file config.
    $identifier = '/^[a-z][a-z0-9_]{0,63}$/';

    $shouldFail = [
        'https://cdn.example/logo.png',
        'http://10.0.0.1/x',
        '//cdn.example/logo.png',
        'file:///etc/passwd',
        'cdn.example.com',
        '../../../etc/passwd',
        'brand logo',
        'Brand_Logo',      // hoa — quy ước là snake_case, và khác hoa/thường sẽ
        'brand-logo',      // sinh hai định danh cho cùng một thứ
        '',
        'data:image/png;base64,AAAA',
    ];

    foreach ($shouldFail as $bad) {
        expect(preg_match($identifier, $bad))->toBe(0, "rào bỏ lọt: {$bad}");
    }

    // Và im lặng với những gì mảnh D sẽ thêm khi có nhu cầu thật.
    foreach (['brand_logo', 'branch_logo', 'promo_banner', 'coupon_image', 'order_url'] as $ok) {
        expect(preg_match($identifier, $ok))->toBe(1, "rào chặn nhầm: {$ok}");
    }
});

it('allow-list ảnh là TẬP CON của allow-list chung, và không rỗng', function () {
    $image = (array) config('print_blocks.image.sources');
    $all = (array) config('print_blocks.sources');

    // Hai danh sách tồn tại vì lý do khác nhau nên chúng có thể trôi khỏi nhau
    // trong im lặng: một định danh chỉ có ở danh sách ảnh sẽ qua được
    // `PrintImageStore` rồi bị `TemplateValidator` từ chối lúc publish — hỏng ở
    // xa chỗ gây ra, đúng kiểu khó lần nhất.
    expect($image)->not->toBeEmpty()
        ->and(array_diff($image, $all))->toBe([]);
});

it('không có định danh nào lặp — hai dòng giống nhau là một dòng chết', function () {
    foreach (['print_blocks.sources', 'print_blocks.image.sources'] as $key) {
        $list = (array) config($key);

        expect(array_values(array_unique($list)))->toBe(array_values($list), "{$key} có định danh lặp");
    }
});
