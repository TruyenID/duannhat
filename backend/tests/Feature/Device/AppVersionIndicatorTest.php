<?php

declare(strict_types=1);

use App\Http\Resources\DeviceResource;
use App\Models\Device;
use App\Services\Device\DeviceService;
use Illuminate\Http\Request;

/**
 * #2142 — chỉ báo phiên bản máy trạm phải phân biệt được BA trạng thái.
 *
 * `devices.device_info` đã nhận `app_version` từ lâu, nhưng chỉ ghi **một lần,
 * lúc pair**. Máy trạm nâng cấp app xong thì giá trị đó sai, và **không có gì
 * đánh dấu nó đã cũ**. Một chỉ báo đọc thẳng cột ấy sẽ trả lời tự tin và sai —
 * tệ hơn không có chỉ báo, vì nó trả lời "có" cho câu hỏi "chỗ này đã đo được
 * chưa".
 *
 * Vì sao ba chứ không phải hai: #2041 bước 3 xoá ba cột tiền khi số máy trạm
 * chưa biết đọc sổ về 0. Đọc một giá trị cũ thành "hiện tại" làm con số 0 đó
 * thành hư cấu, và ba cột đi theo. Đếm nhầm sang "cũ" thì chỉ là bi quan — nó
 * hoãn một lần dọn dẹp. Bất đối xứng đó là lý do trạng thái "không biết" được
 * ĐẶT TÊN chứ không để rơi vào mặc định.
 */
function versionDevice(array $deviceInfo = []): Device
{
    return Device::factory()->create(['device_info' => $deviceInfo]);
}

function versionStatus(Device $device): array
{
    $payload = (new DeviceResource($device->fresh()))->toArray(Request::create('/'));

    return [
        $payload['app_version'] ?? null,
        $payload['app_version_source'] ?? null,
        $payload['app_version_seen_at'] ?? null,
    ];
}

it('KHÔNG BIẾT khi chưa từng có phiên bản nào', function () {
    [$version, $source] = versionStatus(versionDevice([]));

    expect($version)->toBeNull()
        ->and($source)->toBe('unknown');
});

it('chỉ có từ lúc PAIR thì nói rõ là pairing, không giả vờ là hiện tại', function () {
    // Đúng trạng thái của mọi thiết bị đã pair trước #2142: có số, nhưng số đó
    // có thể đã cũ nhiều lần nâng cấp.
    [$version, $source, $seenAt] = versionStatus(versionDevice(['app_version' => '0.2.0']));

    expect($version)->toBe('0.2.0')
        ->and($source)->toBe('pairing')
        ->and($seenAt)->toBeNull();
});

it('heartbeat MANG header thì làm mới phiên bản và nâng nguồn lên heartbeat', function () {
    $device = versionDevice(['app_version' => '0.2.0']);

    app(DeviceService::class)->heartbeat($device, '0.3.1');

    [$version, $source, $seenAt] = versionStatus($device);

    expect($version)->toBe('0.3.1')
        ->and($source)->toBe('heartbeat')
        ->and($seenAt)->not->toBeNull();
});

it('heartbeat KHÔNG mang header thì không đụng gì tới phiên bản — client cũ vẫn hợp lệ', function () {
    // Mọi client ship trước #2142 đều không gửi header. Đó phải là câu trả lời
    // được hỗ trợ, không phải một lần hạ cấp dữ liệu.
    $device = versionDevice(['app_version' => '0.2.0']);

    app(DeviceService::class)->heartbeat($device, null);

    [$version, $source] = versionStatus($device);

    expect($version)->toBe('0.2.0')
        ->and($source)->toBe('pairing');
});

it('phiên bản TRÙNG cũng nâng nguồn lên heartbeat ở lần đầu', function () {
    // Bẫy: nếu chỉ ghi khi giá trị ĐỔI thì một máy pair rồi báo lại đúng số cũ
    // sẽ mãi mãi mang nhãn `pairing` dù nó vừa tự xác nhận là đang chạy số đó.
    $device = versionDevice(['app_version' => '0.3.1']);

    app(DeviceService::class)->heartbeat($device, '0.3.1');

    [, $source] = versionStatus($device);

    expect($source)->toBe('heartbeat');
});

it('không ghi lại JSON khi phiên bản không đổi và đã xác nhận sống', function () {
    // Mọi request có device token đều đi qua đây. Ghi JSON mỗi lần là đặt một
    // phép ghi lên đường nóng để đổi lấy không gì cả — `last_seen_at` đã mang
    // thông tin tươi/cũ rồi.
    $device = versionDevice(['app_version' => '0.3.1']);
    $service = app(DeviceService::class);

    $service->heartbeat($device, '0.3.1');
    $firstSeenAt = versionStatus($device)[2];

    $service->heartbeat($device->fresh(), '0.3.1');

    expect(versionStatus($device)[2])->toBe($firstSeenAt);
});

it('bỏ qua header rác — nó là dữ liệu client và rơi vào cột JSON', function () {
    $device = versionDevice(['app_version' => '0.2.0']);

    app(DeviceService::class)->heartbeat($device, str_repeat('x', 65));
    expect(versionStatus($device)[0])->toBe('0.2.0');

    app(DeviceService::class)->heartbeat($device->fresh(), '   ');
    expect(versionStatus($device)[0])->toBe('0.2.0');
});
