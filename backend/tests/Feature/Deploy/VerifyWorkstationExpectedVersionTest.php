<?php

declare(strict_types=1);

/**
 * #3173 — rào cho một cơ chế mà kiểu hỏng của nó là SỰ IM LẶNG.
 *
 * `WORKSTATION_EXPECTED_VERSION` chưa bao giờ được đặt trên production. Feed
 * `expected-build` gate mọi trường theo `$version === null`, nên nó trả lời
 * "không có gì" và ba máy quán đứng ở `v0.6.0` trong khi `v0.8.26` đã phát
 * hành — dù mã tự cập nhật đã ship từ 2026-08-10. Không gì đỏ, vì không có gì
 * sai; chỉ là không có gì được lên đạn.
 *
 * Rào phải biết KÊU và biết IM, và chiều IM ở đây quan trọng bất thường: nó
 * chạy trên **đường deploy production**, nên một lần kêu oan là chặn đứng một
 * lượt phát hành — và phản ứng sẽ là gỡ rào, không phải sửa cấu hình.
 */

/**
 * Manifest tối thiểu, đúng hình dạng FILE THẬT — không phải hình dạng
 * `read()` TRẢ VỀ. Hai thứ đó khác nhau và đây là chỗ dễ viết sai: file chỉ có
 * MỘT danh sách `versions`; `read()` mới tách nó thành `versions` /
 * `archive_versions` dựa theo cờ `archived` của từng mục. Một khoá
 * `archive_versions` viết ở cấp cao nhất bị **bỏ qua hoàn toàn** — bản nháp
 * đầu của bài test này viết đúng như thế và đỏ.
 */
function wsManifest(array $versions, array $archive = []): string
{
    $entry = fn (string $v, bool $archived) => [
        'version' => $v,
        'archived' => $archived,
        'files' => [
            ['platform' => 'windows-amd64.exe', 'filename' => "workstation-{$v}.exe", 'sha256' => str_repeat('a', 64), 'size' => 1],
        ],
    ];

    $path = tempnam(sys_get_temp_dir(), 'wsmanifest').'.json';
    file_put_contents($path, json_encode([
        'latest' => $versions[0] ?? null,
        'updated_at' => '2026-08-17T21:58:00Z',
        'versions' => array_merge(
            array_map(fn (string $v) => $entry($v, false), $versions),
            array_map(fn (string $v) => $entry($v, true), $archive),
        ),
    ], JSON_THROW_ON_ERROR));

    config(['workstation.downloads.manifest_path' => $path]);

    return $path;
}

it('#3173 KÊU: biến rỗng trong khi manifest đã phát hành bản', function () {
    // Đây chính xác là trạng thái production đã sống suốt nhiều tuần.
    wsManifest(['v0.8.26', 'v0.8.25']);
    config(['workstation.expected_build.version' => '']);

    $this->artisan('deploy:verify-workstation-expected-version');
})->throws(RuntimeException::class, 'EMPTY');

it('#3173 KÊU: biến trỏ vào bản KHÔNG có trong manifest — feed khai một bản không ai tải được', function () {
    // Dễ tạo bằng tay, và nhìn từ ngoài thì không thấy: endpoint vẫn trả 200
    // kèm một version, chỉ có `package` là null.
    wsManifest(['v0.8.26']);
    config(['workstation.expected_build.version' => 'v0.9.99']);

    $this->artisan('deploy:verify-workstation-expected-version');
})->throws(RuntimeException::class, 'not in the workstation manifest');

it('#3173 KÊU: sai TIỀN TỐ `v` cũng bị bắt — so sánh là chuỗi tuyệt đối', function () {
    // Máy trạm báo lên `v0.6.0`, manifest ghi `v0.8.26`, và `packageForVersion`
    // so `===`. Đặt `0.8.26` thì máy sẽ vĩnh viễn thấy mình lệch bản kể cả sau
    // khi đã cài đúng — một lỗi không bao giờ tự lộ ra.
    wsManifest(['v0.8.26']);
    config(['workstation.expected_build.version' => '0.8.26']);

    $this->artisan('deploy:verify-workstation-expected-version');
})->throws(RuntimeException::class, 'EXACT string match');

it('#3173 IM: biến trỏ đúng bản đã phát hành', function () {
    wsManifest(['v0.8.26', 'v0.8.25']);
    config(['workstation.expected_build.version' => 'v0.8.26']);

    $this->artisan('deploy:verify-workstation-expected-version')->assertSuccessful();
});

it('#3173 IM: bản CŨ HƠN vẫn hợp lệ — hãm một bản lỗi là quyền của HQ', function () {
    // Rào này cố ý KHÔNG chọn phiên bản hộ. #2635 giao quyết định "quán nên ở
    // bản nào" cho HQ; một rào đòi phải luôn là bản mới nhất sẽ lấy mất khả
    // năng hãm một bản hỏng.
    wsManifest(['v0.8.26', 'v0.8.25', 'v0.8.24']);
    config(['workstation.expected_build.version' => 'v0.8.24']);

    $this->artisan('deploy:verify-workstation-expected-version')->assertSuccessful();
});

it('#3173 IM: bản nằm trong archive_versions cũng hợp lệ', function () {
    // `packageForVersion()` đọc cả hai danh sách, nên rào phải đọc cả hai —
    // nếu không nó sẽ gọi một cấu hình đang chạy tốt là lỗi.
    wsManifest(['v0.8.26'], ['v0.7.0']);
    config(['workstation.expected_build.version' => 'v0.7.0']);

    $this->artisan('deploy:verify-workstation-expected-version')->assertSuccessful();
});

it('#3173 IM: manifest RỖNG thì bỏ qua — cài mới chưa phát hành gì', function () {
    // Kêu ở đây sẽ chặn đúng lượt deploy đầu tiên của một hệ thống mới, tức
    // rào tự làm mình bị gỡ.
    wsManifest([]);
    config(['workstation.expected_build.version' => '']);

    $this->artisan('deploy:verify-workstation-expected-version')->assertSuccessful();
});

it('#3173 IM: manifest thiếu file cũng không làm câm cả deploy', function () {
    // `read()` trả cấu trúc rỗng khi không đọc được. Cùng lý do như trên: một
    // manifest hỏng là chuyện của đường phát hành, không phải cớ để chặn deploy.
    config(['workstation.downloads.manifest_path' => '/tmp/khong-ton-tai-'.uniqid().'.json']);
    config(['workstation.expected_build.version' => '']);

    $this->artisan('deploy:verify-workstation-expected-version')->assertSuccessful();
});
