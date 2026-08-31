<?php

declare(strict_types=1);

/**
 * #2101 — đích upload của người dùng phải ĐỌC ĐƯỢC công khai.
 *
 * Trên prod, `FILESYSTEM_DISK=local` và `FileController@upload` truyền thẳng
 * `config('filesystems.default')`, nên mọi ảnh rơi vào disk `local` — root
 * `storage/app/private`, nằm ngoài docroot và `visibility` mặc định `private`.
 * `File::getUrl()` gọi `Storage::url()` sinh URL KHÔNG KÝ, còn
 * `Illuminate\Filesystem\ServeFile` thì đòi chữ ký ⇒ **mọi ảnh 404**.
 *
 * Cái làm nó đắt không phải bản thân lỗi, mà là nó CÂM: upload trả 201, file
 * ghi xuống đĩa thật, row `files` tạo đủ, và Laravel log không có lấy một dòng.
 * Chỗ đầu tiên ai cũng nhìn khi "ảnh hỏng" lại đúng là chỗ duy nhất không có
 * thông tin. 30 ảnh hỏng suốt 2 ngày trước khi có người truy ra.
 *
 * Nên rào phải nằm ở CI, không phải ở mắt người đọc diff: một dòng `.env` sai
 * thì test này đỏ, thay vì ảnh 404 im lặng trên prod.
 *
 * Test này KHÔNG khoá cứng vào disk `public`. Chuyển sang `s3` là hợp lệ —
 * điều kiện thật là "URL phát ra tuyệt đối và không cần chữ ký", nên bài kiểm
 * viết theo đúng tính chất đó chứ không theo tên disk.
 */

use Illuminate\Support\Facades\Storage;

it('U1: disk upload có khai báo và không phải disk private cục bộ', function () {
    $disk = config('filesystems.uploads');

    expect($disk)->toBeString()->not->toBeEmpty();

    $configuration = config("filesystems.disks.{$disk}");

    expect($configuration)->toBeArray(
        "Disk upload '{$disk}' không có trong filesystems.disks.",
    );

    if (($configuration['driver'] ?? null) === 'local') {
        expect($configuration['visibility'] ?? 'private')->toBe(
            'public',
            "Disk upload '{$disk}' là local nhưng visibility không phải public. ".
            'ServeFile sẽ đòi signed URL còn File::getUrl() phát URL không ký ⇒ 404.',
        );
    }
});

it('U2: disk upload phát ra URL tuyệt đối', function () {
    $disk = (string) config('filesystems.uploads');

    $url = Storage::disk($disk)->url('uploads/temp/probe.jpg');

    // Host-less `/storage/…` để trình duyệt tự ghép với origin đang mở —
    // customer-web, kiosk, admin-web, chứ không phải backend đang giữ file.
    expect($url)->toStartWith('http');
});

// `toContain` nhận needle BIẾN THIÊN, nên truyền chuỗi thông báo vào tham số
// thứ hai biến nó thành needle thứ hai và `not` khi đó luôn đúng — bản đầu của
// test này xanh ngay cả khi file đang ở đúng trạng thái lỗi. Dùng
// `str_contains` + `toBeFalse`, nơi tham số thứ hai thật sự là thông báo.
it('U3: không đường upload nào bám vào filesystems.default', function (string $relativePath) {
    $source = (string) file_get_contents(base_path($relativePath));

    expect(str_contains($source, "config('filesystems.default'"))->toBeFalse(
        "{$relativePath} lấy disk từ filesystems.default. Mặc định của framework ".
        'là `local` và nó private theo thiết kế — dùng config(\'filesystems.uploads\').',
    );
})->with([
    'app/Http/Controllers/Api/V1/FileController.php',
]);

/**
 * U4 (#2163) — đường upload phải LẤY disk từ config, không ghi cứng tên nó.
 *
 * U3 ở trên chỉ cấm `config('filesystems.default')`. Nó **không** bắt được một
 * tên disk viết thẳng vào mã, và đó chính là hình dạng của lỗi lần này:
 * `ShopController` giữ `private const BRANCH_IMAGE_DISK = 's3'` — không dính
 * `filesystems.default` một chữ nào, nên nó đi qua U3 mà không bị chạm.
 *
 * Vì thế chỉ THÊM file vào dataset của U3 là chưa đủ, và tệ hơn: nó tạo ra một
 * test xanh vĩnh viễn trông như đã canh. Rào phải hỏi câu khác — *"file này có
 * đọc `filesystems.uploads` không"* — mới đỏ được trước bản sửa.
 *
 * Vì sao đọc source thay vì gọi thật: một file gọi đúng endpoint chỉ chứng minh
 * MỘT đường; danh sách này là **một LỚP** (mọi chỗ nhận file của người dùng) và
 * cái phải chặn là chỗ thứ ba được thêm vào ngày mai mà quên đọc config. Bài
 * kiểm hành vi cho từng endpoint nằm ở file test riêng của endpoint đó.
 *
 * Thêm một dòng vào danh sách này khi có đường upload mới. Đường KHÔNG phải
 * upload của người dùng thì đừng thêm — `PrintImageStore` cố ý dùng disk private
 * riêng (`filesystems.print_images`, #2136) và kéo nó vào đây là ép nó sai.
 */
it('U4: đường upload lấy disk từ filesystems.uploads, không ghi cứng tên disk', function (string $relativePath) {
    $source = (string) file_get_contents(base_path($relativePath));

    expect(str_contains($source, "config('filesystems.uploads'"))->toBeTrue(
        "{$relativePath} không đọc `config('filesystems.uploads')`. Tên disk là ".
        'cấu hình theo môi trường — ghi cứng nó đúng cho một môi trường và sai '.
        'cho mọi môi trường còn lại (#2163: prod không có s3 ⇒ 500 mọi lần tải).',
    );
})->with([
    'app/Http/Controllers/Api/V1/FileController.php',
    'app/Http/Controllers/Api/V1/HQ/ShopController.php',
    'app/Http/Controllers/Api/V1/Customer/CustomerReviewController.php',
]);
