<?php

use App\Models\Branch;
use App\Models\Device;
use App\Models\Organization;
use App\Omnify\Enums\DeviceStatusEnum;
use App\Omnify\Enums\DeviceTypeEnum;
use Illuminate\Support\Str;

/**
 * #2142 — the WIRE, not the logic.
 *
 * `AppVersionIndicatorTest` covers `heartbeat()` and `DeviceResource` by calling
 * them directly, and it is thorough. It is also blind to the only two lines that
 * carry the header from the network into the service:
 *
 *     AuthenticateDevice.php        $this->service->heartbeat($device, $request->header('X-App-Version'));
 *     AuthenticateSsoOrDevice.php   (the same call, second auth door)
 *
 * Measured in review of PR #2143: dropping the second argument from BOTH
 * middlewares — i.e. reverting the feature completely in production — left all
 * 69 tests green. `'X-App-Version'` appeared 4 times in the diff and 0 times in
 * any test.
 *
 * Two failure modes that only an HTTP-level test can see:
 *
 *  1. **The header name is a CROSS-REPO constant.** Go emits it
 *     (`internal/cloudhttp/version_transport.go`); Laravel reads it. A typo on
 *     the PHP side is green in both repos and the indicator dies silently. (A
 *     case difference would NOT bite — Laravel's `header()` is
 *     case-insensitive — but a hyphen slip, `X-AppVersion`, does.) The Go side
 *     pins its own spelling; a contract guarded at one end is not a contract.
 *  2. **Auth middleware gets refactored.** These two files have been rewritten
 *     repeatedly. Dropping the argument reports `pairing` forever, and #2041
 *     step 3 — which deletes three money columns once the count of too-old
 *     workstations hits zero — cannot tell "no old devices left" from "the
 *     instrument broke".
 *
 * So these tests go through the router on purpose. They are the only place the
 * literal header string is asserted on the Cloud side.
 */
function wiringDevice(DeviceTypeEnum $type, string $token, ?string $branchId = null, ?string $orgId = null): Device
{
    return Device::factory()->create([
        'type' => $type,
        'status' => DeviceStatusEnum::Active,
        'device_token' => $token,
        'device_info' => ['user_agent' => 'test'],
    ] + array_filter([
        'branch_id' => $branchId,
        'organization_id' => $orgId,
    ]));
}

it('device.auth carries X-App-Version from the request into device_info', function () {
    $token = 'tok_wiring_'.Str::random(32);
    $device = wiringDevice(DeviceTypeEnum::Workstation, $token);

    // Precondition: the device has never reported live. Without this the test
    // could pass on a value that was already there.
    expect($device->device_info['app_version'] ?? null)->toBeNull();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-App-Version', '1.4.2')
        ->getJson('/api/v1/devices/me')
        ->assertOk();

    $info = $device->fresh()->device_info;

    expect($info['app_version'] ?? null)->toBe('1.4.2', implode("\n", [
        'Header đi qua device.auth mà KHÔNG tới được device_info.',
        'Nhiều khả năng lời gọi heartbeat() trong AuthenticateDevice đánh rơi tham số,',
        'hoặc tên header phía PHP lệch so với bản Go phát ra.',
    ]));
    expect($info['app_version_seen_at'] ?? null)->not->toBeNull(
        'thiếu dấu thời gian ⇒ resource sẽ báo `pairing`, tức đo được mà vẫn mang nhãn phỏng đoán',
    );
});

it('the read side then reports source=heartbeat for that same device', function () {
    // Vế thứ hai của cùng một sợi dây: ghi được là một chuyện, đọc ra đúng NHÃN
    // mới là thứ #2041 bước 3 dựa vào để xoá ba cột tiền.
    $token = 'tok_wiring_'.Str::random(32);
    wiringDevice(DeviceTypeEnum::Workstation, $token);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-App-Version', '2.0.0')
        ->getJson('/api/v1/devices/me')
        ->assertOk();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/devices/me')
        ->assertOk()
        ->assertJsonPath('data.app_version', '2.0.0')
        ->assertJsonPath('data.app_version_source', 'heartbeat');
});

it('a request with NO X-App-Version leaves device_info untouched', function () {
    // Vế cần thiết: thiếu nó thì một middleware luôn-ghi cũng qua được hai bài
    // trên. Và "client cũ không gửi header" là trạng thái của mọi bản đã phát
    // hành trước #2142 — nó phải là câu trả lời hợp lệ, không phải mất dịch vụ.
    $token = 'tok_wiring_'.Str::random(32);
    $device = wiringDevice(DeviceTypeEnum::Kiosk, $token);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/devices/me')
        ->assertOk()
        ->assertJsonPath('data.app_version_source', 'unknown');

    expect(array_key_exists('app_version', $device->fresh()->device_info ?? []))->toBeFalse(
        'không gửi header mà vẫn ghi vào cột JSON',
    );
});

it('auth.sso_or_device — the SECOND auth door — carries the header too', function () {
    // Máy trạm và pos-web đi qua hai middleware KHÁC NHAU. Ghim một cửa rồi
    // tưởng đã phủ cả hai chính là cách nửa lưu lượng mất chỉ báo trong im lặng.
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $shop = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'slug' => 'wiring-shop',
        'is_active' => true,
    ]);

    $token = 'tok_pos_'.Str::random(32);
    $device = wiringDevice(DeviceTypeEnum::Pos, $token, $shop->id, $orgId);

    $this->withHeader('X-Shop-Slug', $shop->slug)
        ->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-App-Version', '3.1.0')
        ->getJson('/api/v1/pos/me')
        ->assertOk();

    expect($device->fresh()->device_info['app_version'] ?? null)->toBe('3.1.0', implode("\n", [
        'Cửa auth thứ hai (auth.sso_or_device) không chuyển header xuống heartbeat().',
        'pos-web và mọi thứ dưới /api/v1/pos/* sẽ mãi mãi báo `pairing`.',
    ]));
});

it('pairing cannot forge the heartbeat label by posting app_version_seen_at', function () {
    // `device_info` được validate là mảng tự do, nên client có thể tự đặt dấu
    // thời gian và tự trao cho mình nhãn tin cậy cao nhất mà chưa từng heartbeat.
    // Giá trị phiên bản vốn đã tự khai — cái được bảo vệ ở đây là TIỀN ĐỀ: sự có
    // mặt của dấu thời gian phải chứng minh giá trị tới trên một request sống.
    $device = Device::factory()->create([
        'type' => DeviceTypeEnum::Workstation,
        'status' => DeviceStatusEnum::PendingActivation,
        'pairing_code' => 'ABC123',
        'pairing_expires_at' => now()->addMinutes(10),
        'device_info' => null,
    ]);

    $this->postJson('/api/v1/devices/pair', [
        'pairing_code' => 'ABC123',
        'device_info' => [
            'app_version' => '9.9.9',
            'app_version_seen_at' => '2099-01-01T00:00:00Z',
        ],
    ])->assertOk();

    $info = $device->fresh()->device_info;

    // KHÔNG dùng `->not->toHaveKey($key, $msg)`: chữ ký là `toHaveKey($key, $value)`,
    // nên chuỗi giải thích bị đọc thành GIÁ TRỊ mong đợi và phép khẳng định thành
    // "không có khoá này MANG giá trị là câu tiếng Việt kia" — luôn đúng. Bản đầu
    // của bài test này viết đúng như vậy và nó XANH cả khi gỡ `unset()` ra; chỉ có
    // nghi thức chiều-ngược mới lộ.
    expect(array_key_exists('app_version_seen_at', $info))->toBeFalse(
        'thiết bị tự cấp cho mình nhãn `heartbeat` mà chưa từng heartbeat lần nào',
    );
    expect($info['app_version'] ?? null)->toBe('9.9.9',
        'giá trị tự khai lúc pair VẪN được giữ — chỉ cái nhãn tin cậy là không',
    );
});

it('a malformed-UTF-8 header is ignored, NOT a 500 on the hot path', function () {
    // Đo được trước khi sửa: `heartbeat($device, "\x80\xFF bad")` ném
    // JsonEncodingException ("Malformed UTF-8 characters") vì `device_info` là
    // cột JSON được cast sang array. Ngoại lệ ném ra TRONG middleware, trước
    // `$next($request)` — cả request 500.
    //
    // Rào cũ bó ĐỘ DÀI nhưng không bó MÃ HOÁ, mà byte 0x80–0xFF là hợp lệ trong
    // header value đối với nginx nên nó tới được đây. Hệ quả nếu để nguyên: một
    // bản build đóng dấu `config.Version` hỏng làm MỌI request sync-UP của máy
    // trạm đó 500 — đơn hàng ngừng đồng bộ vì một cột telemetry.
    $token = 'tok_wiring_'.Str::random(32);
    $device = wiringDevice(DeviceTypeEnum::Workstation, $token);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-App-Version', "\x80\xFF bad")
        ->getJson('/api/v1/devices/me')
        ->assertOk();

    expect(array_key_exists('app_version', $device->fresh()->device_info ?? []))->toBeFalse(
        'chuỗi không hợp lệ vẫn được ghi vào cột JSON',
    );
});
