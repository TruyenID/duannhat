<?php

declare(strict_types=1);

/**
 * #1706 — mọi khoá một endpoint ĐỌC từ request phải có mặt trong `requestBody`
 * của chính nó.
 *
 * Drift này im lặng theo cách xấu nhất: endpoint nhận và GHI một trường mà
 * Swagger không khai, nên người tích hợp hợp lệ không biết nó tồn tại, còn
 * người dò API thì biết. `ShopBranchSettingsController` đã chạy như vậy với ba
 * trường, một trong số đó là `invoice_registration_number` — số đăng ký thuế,
 * và nó lên hoá đơn.
 *
 * Phạm vi CỐ Ý hẹp: chỉ các controller cài đặt tự đọc `$request->has(...)` /
 * `input(...)` thay vì dùng FormRequest. Endpoint có FormRequest đã có `rules()`
 * làm nguồn chân lý và được các test khác canh; mở rộng bài này ra toàn bộ
 * controller sẽ biến nó thành một cái lưới bắt đủ thứ rồi bị ai đó tắt đi.
 *
 * Nếu một khoá được đọc nhưng CỐ Ý không cho ghi (ví dụ
 * `takeaway_payment_timeout_minutes` sau #1705), hãy để nó ngoài cả hai phía —
 * đừng khai vào `requestBody` một thứ endpoint sẽ bỏ qua.
 */

use Illuminate\Support\Str;

/**
 * Khoá ĐỌC được nhưng CỐ Ý không khai vào `requestBody`.
 *
 * Chỉ có đúng một loại được nằm ở đây: khoá endpoint đọc rồi BỎ QUA (khai vào
 * Swagger một trường sẽ không có tác dụng là nói dối người tích hợp).
 *
 * Danh sách CHỈ ĐƯỢC CO LẠI — bánh cóc ở cuối file cưỡng chế. Bản trước còn
 * `shop`, `shop_id`, `brand`, `brand_id` với lý do "đến từ URL/header"; đo lại
 * 2026-08-18 thì `requestKeysReadBy()` KHÔNG hề sinh ra tên nào trong bốn tên
 * đó — tham số route không đi qua `$request->has|input|...`, nên bốn mục ấy
 * chưa bao giờ trừ đi thứ gì. Cái chúng làm là ngồi sẵn ở bốn khoá rất dễ
 * trùng: ngày ai đó thêm `$request->input('brand_id')` thật vào một trong hai
 * endpoint này, nó sẽ ghi được mà Swagger vẫn im — đúng lỗ #1706 sinh ra để
 * bịt, mở lại bằng chính rào đó.
 *
 * @var list<string>
 */
const OPENAPI_NOT_BODY_INPUT = ['takeaway_payment_timeout_minutes'];

/**
 * Cặp (controller, service) được bài này soi. Tách khỏi `->with()` để bánh cóc
 * đọc được cùng một tập.
 *
 * @return array<string, array{0: string, 1: string|null}>
 */
function openApiRequestBodyTargets(): array
{
    return [
        'branch settings' => [
            'app/Http/Controllers/Api/V1/Shop/ShopBranchSettingsController.php',
            'app/Services/Shop/ShopBranchSettingsService.php',
        ],
        'takeaway payment settings' => [
            'app/Http/Controllers/Api/V1/Shop/ShopTakeawayPaymentSettingsController.php',
            null, // dùng chung service ở trên; phần đọc của nó nằm ngay trong controller
        ],
    ];
}

/** @return list<string> tên khoá controller đọc từ request */
function requestKeysReadBy(string $source): array
{
    preg_match_all(
        "/\\\$request->(?:has|exists|filled|input|boolean|string|integer)\(\s*'([a-z0-9_]+)'/i",
        $source,
        $m,
    );

    return array_values(array_unique($m[1]));
}

/** @return list<string> tên khoá khai trong OA\Property của requestBody */
function requestBodyKeysDeclaredIn(string $source): array
{
    $start = strpos($source, 'requestBody:');
    if ($start === false) {
        return [];
    }

    // Cắt tới `responses:` của cùng attribute — property của response không tính.
    $end = strpos($source, 'responses:', $start);
    $slice = substr($source, $start, ($end === false ? strlen($source) : $end) - $start);

    preg_match_all("/property:\s*'([a-z0-9_]+)'/i", $slice, $m);

    return array_values(array_unique($m[1]));
}

it('#1706: mọi khoá endpoint cài đặt chi nhánh ĐỌC đều được khai trong requestBody', function (string $path, ?string $servicePath) {
    $source = file_get_contents(base_path($path));

    // Chỗ ĐỌC và chỗ KHAI nằm ở hai file khác nhau, và đó chính là bẫy: sau
    // #1696 các lệnh `$request->has(...)` chuyển sang service, còn `OA\Property`
    // ở lại controller. Bản đầu của bài test này chỉ quét controller nên nó
    // XANH cả khi tôi cố tình gỡ một trường khỏi Swagger — không đo gì.
    $read = requestKeysReadBy($source);
    if ($servicePath !== null) {
        $read = array_values(array_unique(array_merge($read, requestKeysReadBy(file_get_contents(base_path($servicePath))))));
    }

    $declared = requestBodyKeysDeclaredIn($source);

    $undeclared = array_values(array_diff($read, $declared, OPENAPI_NOT_BODY_INPUT));

    expect($undeclared)->toBe([], implode("\n", [
        Str::afterLast($path, '/').' đọc những khoá này từ request nhưng KHÔNG khai trong requestBody:',
        '  '.implode(', ', $undeclared),
        'Swagger là hợp đồng mà frontend và người tích hợp đọc. Một trường ghi được',
        'mà không khai nghĩa là người dùng hợp lệ không biết nó tồn tại, còn người',
        'dò API thì biết.',
    ]));
})->with(openApiRequestBodyTargets());

/**
 * BÁNH CÓC — `OPENAPI_NOT_BODY_INPUT` chỉ được CO LẠI.
 *
 * Một mục ở đây là phép TRỪ. Trừ đi một khoá không ai đọc thì không trừ gì
 * hôm nay — nhưng nó là một cái bẫy đặt sẵn: nó có hiệu lực đúng vào ngày ai đó
 * thêm `$request->input('<khoá đó>')` vào endpoint, tức đúng lúc rào phải kêu.
 *
 * Nên mỗi mục phải chứng minh nó ĐANG trừ một thứ có thật: khoá phải nằm trong
 * tập `requestKeysReadBy()` của ít nhất một target.
 */
it('bánh cóc — mục "không phải body input" hết ứng phải bị xoá', function () {
    $read = [];
    foreach (openApiRequestBodyTargets() as [$path, $servicePath]) {
        $read = array_merge($read, requestKeysReadBy(file_get_contents(base_path($path))));
        if ($servicePath !== null) {
            $read = array_merge($read, requestKeysReadBy(file_get_contents(base_path($servicePath))));
        }
    }
    $read = array_values(array_unique($read));

    // Bộ trích hỏng ⇒ tập rỗng ⇒ bánh cóc tố oan mọi mục. Ghim mẫu số trước.
    expect(count($read))->toBeGreaterThan(3, 'requestKeysReadBy() gần như không trích được gì — bộ quét hỏng, không phải danh sách');

    foreach (OPENAPI_NOT_BODY_INPUT as $key) {
        // `toContain()` nhận NHIỀU GIÁ TRỊ chứ không nhận thông điệp — truyền
        // chuỗi giải thích vào đó biến nó thành một giá trị phải tìm thấy, và
        // rào đỏ vĩnh viễn. Dùng `toBeTrue()` để chỗ thông điệp là thông điệp.
        expect(in_array($key, $read, true))->toBeTrue(implode("\n", [
            "`{$key}` nằm trong OPENAPI_NOT_BODY_INPUT nhưng KHÔNG endpoint nào trong",
            'danh sách target đọc khoá đó. Mục ấy không trừ gì — xoá nó.',
            '',
            'Nó không vô hại: nó có hiệu lực đúng vào ngày ai đó thêm',
            "`\$request->input('{$key}')` thật, tức đúng lúc rào #1706 phải kêu.",
            '',
            'Danh sách này chỉ ĐI XUỐNG.',
        ]));
    }
});
