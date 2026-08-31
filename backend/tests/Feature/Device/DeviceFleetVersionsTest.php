<?php

/**
 * #2841 — "fleet đang chạy bản nào" phải trả lời được bằng một lệnh.
 *
 * Con số lệnh này in ra là đầu vào cho quyết định XOÁ đường tương thích cũ
 * (#2412, #2666). Một câu trả lời sai ở đây làm hỏng thiết bị đang phục vụ
 * khách, nên bộ test nặng về các chiều PHẢI TỪ CHỐI.
 */

use App\Models\Branch;
use App\Models\Device;
use App\Models\Organization;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

uses()->group('device');

beforeEach(function () {
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $this->orgId = $orgId;
    $this->branch = Branch::factory()->create(['console_organization_id' => $orgId]);
});

function fleetDevice(string $type, ?string $version, bool $live): Device
{
    $info = [];
    if ($version !== null) {
        $info['app_version'] = $version;
        if ($live) {
            $info['app_version_seen_at'] = now()->toIso8601String();
        }
    }

    return Device::factory()->create([
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'type' => $type,
        'device_info' => $info,
    ]);
}

function fleetJson(array $args = []): array
{
    // `$this->artisan()` trả PendingCommand — output chỉ tồn tại SAU khi nó
    // thật sự chạy. `Artisan::call` chạy ngay, nên `output()` có nội dung.
    Artisan::call('devices:fleet-versions', $args + ['--json' => true]);

    return json_decode(Artisan::output(), true);
}

it('gom theo phiên bản và TÁCH live khỏi chỉ-lúc-pair', function () {
    // Gộp hai nguồn là đếm một giá trị có thể đã cũ như đang chạy — đúng cái
    // docblock của `DeviceService::heartbeat()` gọi là "a fiction".
    fleetDevice('workstation', '0.8.1', live: true);
    fleetDevice('workstation', '0.8.1', live: true);
    fleetDevice('workstation', '0.8.1', live: false);   // chỉ báo lúc pair

    $out = fleetJson(['--type' => 'workstation']);

    expect($out['total'])->toBe(3);

    $bySource = collect($out['by_version'])->keyBy('source');
    expect($bySource['live']['count'])->toBe(2)
        ->and($bySource['chỉ-lúc-pair']['count'])->toBe(1);
});

it('máy CHƯA TỪNG báo phiên bản có nhóm riêng, không lẫn vào bản nào', function () {
    fleetDevice('workstation', null, live: false);
    fleetDevice('workstation', '0.8.1', live: true);

    $out = fleetJson(['--type' => 'workstation']);
    $sources = collect($out['by_version'])->pluck('source')->sort()->values()->all();

    expect($sources)->toBe(['chưa-báo', 'live']);
});

it('--min-version đếm máy còn DƯỚI ngưỡng, so theo SỐ không theo chuỗi', function () {
    // `0.10.0` phải đứng TRÊN `0.9.0`. So chuỗi sẽ nói ngược, và nói ngược ở đây
    // nghĩa là báo "đã nâng cấp xong" khi chưa.
    fleetDevice('workstation', '0.9.0', live: true);
    fleetDevice('workstation', '0.10.0', live: true);

    $out = fleetJson(['--type' => 'workstation', '--min-version' => '0.10.0']);

    expect($out['below_min'])->toBe(1)
        ->and($out['below'][0]['version'])->toBe('0.9.0');
});

it('CHIỀU FAIL-CLOSED: máy chưa từng báo được tính là DƯỚI ngưỡng', function () {
    // Một máy im lặng không phải bằng chứng nó đã nâng cấp. Coi nó là "đạt"
    // biến một câu hỏi chưa trả lời được thành một câu trả lời "rồi" — và ai đó
    // sẽ xoá đường tương thích dựa trên nó.
    fleetDevice('workstation', null, live: false);

    $out = fleetJson(['--type' => 'workstation', '--min-version' => '0.8.0']);

    expect($out['below_min'])->toBe(1)
        ->and($out['below'][0]['version'])->toBeNull();
});

it('--require-min thoát KHÁC 0 khi còn máy dưới ngưỡng', function () {
    fleetDevice('workstation', '0.7.0', live: true);

    $this->artisan('devices:fleet-versions', [
        '--type' => 'workstation',
        '--min-version' => '0.8.0',
        '--require-min' => true,
    ])->assertExitCode(1);
});

it('MẪU SỐ: mọi máy đã đạt ngưỡng ⇒ thoát 0 — cổng biết IM, không chỉ biết kêu', function () {
    // Không có bài này thì bài trên xanh kể cả khi lệnh LUÔN thất bại, và cổng
    // sẽ bị tắt vì kêu oan thay vì được tin.
    fleetDevice('workstation', '0.8.1', live: true);

    $this->artisan('devices:fleet-versions', [
        '--type' => 'workstation',
        '--min-version' => '0.8.0',
        '--require-min' => true,
    ])->assertExitCode(0);
});

it('--type lọc thật: kiosk không lọt vào phép đếm workstation', function () {
    fleetDevice('workstation', '0.8.1', live: true);
    fleetDevice('kiosk', '0.1.0', live: true);

    expect(fleetJson(['--type' => 'workstation'])['total'])->toBe(1);
    expect(fleetJson()['total'])->toBe(2);
});

/**
 * #3065 — `--active-within`: MẪU SỐ KHÁC cho một CÂU HỎI KHÁC.
 *
 * Không cờ: "chứng minh KHÔNG còn máy nào dưới ngưỡng" — máy chưa từng báo phải
 * tính là dưới (fail-closed, #2412/#2666 dựa vào con số 0 đó để xoá đường cũ).
 *
 * Có cờ: "có máy ĐANG BÁN nào chạy bản cũ không" — monitor hỏi bản đã phát hành
 * đã tới máy quán chưa.
 *
 * Gộp hai câu hỏi vào một mẫu số thì monitor ĐỎ VĨNH VIỄN. Đo trên production
 * 2026-08-17: 2 trong 4 máy workstation chưa từng báo phiên bản lần nào và
 * không có dấu hiệu sẽ báo. Báo động không bao giờ xanh được thì không ai đọc
 * nữa — lúc đó nó tệ hơn không có.
 */
function fleetDeviceSeen(string $type, ?string $version, bool $live, ?string $lastSeen): Device
{
    $d = fleetDevice($type, $version, $live);
    $d->forceFill(['last_seen_at' => $lastSeen])->save();

    return $d;
}

it('#3065 --active-within LOẠI máy im lâu khỏi phép đếm dưới-ngưỡng', function () {
    fleetDeviceSeen('workstation', 'v0.6.0', true, now()->subHours(2)->toDateTimeString());
    fleetDeviceSeen('workstation', 'v0.6.0', true, now()->subDays(30)->toDateTimeString());

    $out = fleetJson(['--type' => 'workstation', '--min-version' => '0.8.13', '--active-within' => 7]);

    expect($out['below_min'])->toBe(1)
        ->and($out['excluded_inactive'])->toHaveCount(1)
        ->and($out['active_within_days'])->toBe(7);
});

it('#3065 máy CHƯA TỪNG báo (last_seen NULL) bị loại khi có --active-within', function () {
    // Đây là ca đã làm monitor không thể xanh: `WS-jp` và `POS-10` trên
    // production chưa từng gửi một request có phiên bản nào.
    fleetDeviceSeen('workstation', null, false, null);

    $out = fleetJson(['--type' => 'workstation', '--min-version' => '0.8.13', '--active-within' => 7]);

    expect($out['below_min'])->toBe(0)
        ->and($out['excluded_inactive'])->toHaveCount(1);
});

it('#3065 KHÔNG có cờ thì ngữ nghĩa cũ giữ NGUYÊN — máy chưa báo vẫn tính là dưới', function () {
    // Vế quan trọng nhất của cả nhóm này: #2412/#2666 dùng chính con số đó để
    // quyết định xoá đường tương thích. Nới nó đi là biến một câu hỏi chưa trả
    // lời được thành một câu "rồi".
    fleetDeviceSeen('workstation', null, false, null);
    fleetDeviceSeen('workstation', 'v0.6.0', true, now()->subDays(30)->toDateTimeString());

    $out = fleetJson(['--type' => 'workstation', '--min-version' => '0.8.13']);

    expect($out['below_min'])->toBe(2)
        ->and($out['excluded_inactive'])->toBeNull()
        ->and($out['active_within_days'])->toBeNull();
});

it('#3065 loại ai thì NÓI RA — tên + last_seen của từng máy bị loại', function () {
    // Bộ lọc âm thầm đọc y hệt "đã phủ hết". Người đọc báo cáo phải thấy được
    // mình đang không nhìn vào cái gì.
    fleetDeviceSeen('workstation', 'v0.1.0', true, now()->subDays(30)->toDateTimeString());

    $out = fleetJson(['--type' => 'workstation', '--min-version' => '0.8.13', '--active-within' => 7]);

    expect($out['excluded_inactive'][0])->toHaveKeys(['name', 'version', 'last_seen_at'])
        ->and($out['excluded_inactive'][0]['version'])->toBe('v0.1.0')
        ->and($out['excluded_inactive'][0]['last_seen_at'])->not->toBeNull();
});

it('#3065 --require-min + --active-within: KÊU khi máy sống cũ, IM khi chỉ máy chết cũ', function () {
    // Hai chiều trong MỘT bài, vì giá trị của rào nằm đúng ở chỗ nó phân biệt
    // được hai ca này — mỗi chiều riêng lẻ đều thoả mãn được bằng một hằng số.
    $live = fleetDeviceSeen('workstation', 'v0.6.0', true, now()->subHours(1)->toDateTimeString());

    $args = ['--type' => 'workstation', '--min-version' => '0.8.13', '--active-within' => 7, '--require-min' => true];
    expect(Artisan::call('devices:fleet-versions', $args))->toBe(1);

    $live->forceFill(['last_seen_at' => now()->subDays(30)])->save();
    expect(Artisan::call('devices:fleet-versions', $args))->toBe(0);
});
