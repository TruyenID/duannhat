<?php

namespace App\Console\Commands;

use App\Http\Middleware\AuthenticateDevice;
use App\Models\Device;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * #2841 — "fleet đang chạy bản nào?", hỏi được bằng MỘT lệnh.
 *
 * Dữ liệu đã có từ lâu: máy trạm gắn `X-App-Version` lên mọi request đã auth
 * ({@see AuthenticateDevice}), Cloud đóng dấu vào
 * `devices.device_info.app_version` + `app_version_seen_at`. Cái thiếu là cách
 * HỎI: muốn biết thì phải mở admin-web soi từng thiết bị hoặc viết SQL tay.
 *
 * Vì thế câu hỏi không được hỏi — và #2412 lẫn #2666 ngồi `blocked` với tiền đề
 * "Cloud không biết fleet chạy build nào", vốn đã sai từ lâu.
 *
 * # LIVE và PAIRING-ONLY không được gộp
 *
 * `app_version` ghi lúc pair thì đứng yên mãi sau mọi lần nâng cấp. Chỉ
 * `app_version_seen_at` có mặt mới chứng minh con số đến từ một request sống.
 * Gộp hai thứ lại là đếm một giá trị đã cũ như đang chạy — và docblock của
 * `DeviceService::heartbeat()` nói thẳng hậu quả: *"a stale value counted as
 * current would make that zero a fiction."*
 *
 * Số 0 của `--min-version` chính là con số mà #2412/#2666 dựa vào để quyết xoá
 * đường cũ, nên nó phải là một khẳng định, không phải một mặc định.
 */
#[Signature('devices:fleet-versions
    {--type= : Chỉ đếm một loại thiết bị (workstation, kiosk, …)}
    {--min-version= : Đếm số máy còn DƯỚI phiên bản này (semver)}
    {--active-within= : Chỉ tính máy có last_seen_at trong N ngày qua}
    {--require-min : Thoát khác 0 khi còn máy dưới --min-version}
    {--json : Xuất JSON thay vì bảng}')]
#[Description('#2841 — fleet đang chạy phiên bản nào, và bao nhiêu máy còn dưới một ngưỡng')]
final class DeviceFleetVersions extends Command
{
    public function handle(): int
    {
        $type = $this->option('type');
        $min = $this->option('min-version');
        $activeWithin = $this->option('active-within');
        $activeSince = $activeWithin !== null && $activeWithin !== ''
            ? now()->subDays((int) $activeWithin)
            : null;

        $devices = Device::query()
            ->when($type, fn ($q) => $q->where('type', $type))
            ->get(['id', 'name', 'type', 'device_info', 'last_seen_at']);

        $rows = [];
        $below = [];
        $excludedInactive = [];

        foreach ($devices as $device) {
            $info = (array) ($device->device_info ?? []);
            $version = $info['app_version'] ?? null;
            $seenAt = $info['app_version_seen_at'] ?? null;

            // Ba trạng thái, không phải hai. "chưa từng báo" khác hẳn "báo lúc
            // pair rồi thôi": cái đầu là chưa đo được, cái sau là đo được MỘT
            // LẦN và có thể đã sai từ lần nâng cấp đầu tiên.
            $source = match (true) {
                $version === null => 'chưa-báo',
                $seenAt !== null => 'live',
                default => 'chỉ-lúc-pair',
            };

            $key = ($version ?? '(không rõ)').'|'.$source;
            $rows[$key] ??= ['version' => $version ?? '(không rõ)', 'source' => $source, 'count' => 0];
            $rows[$key]['count']++;

            if ($min !== null && $this->isBelow($version, (string) $min)) {
                // #3065 — `--active-within` là MẪU SỐ KHÁC, cho một câu hỏi khác.
                //
                //   không cờ  "chứng minh KHÔNG còn máy nào dưới ngưỡng" (#2412/
                //             #2666, quyết định xoá đường cũ) — máy chưa từng báo
                //             PHẢI tính là dưới, xem `isBelow()`.
                //   có cờ     "có máy ĐANG BÁN nào chạy bản cũ không" (#3065,
                //             monitor bản đã phát hành có tới máy quán chưa).
                //
                // Gộp hai câu hỏi vào một mẫu số thì monitor ĐỎ VĨNH VIỄN: đo
                // 2026-08-17 trên production, 2 trong 4 máy workstation chưa
                // từng báo phiên bản lần nào (`WS-jp`, `POS-10`) và không có
                // dấu hiệu sẽ báo. Một báo động không bao giờ xanh được thì
                // không ai đọc nó nữa — và lúc đó nó tệ hơn không có.
                //
                // Máy im lâu tự rụng khỏi danh sách, nên nó KHÔNG cần ai dọn tay.
                $lastSeen = $device->last_seen_at;
                if ($activeSince !== null && ($lastSeen === null || $lastSeen->lt($activeSince))) {
                    $excludedInactive[] = [
                        'name' => (string) $device->name,
                        'version' => $version,
                        'last_seen_at' => $lastSeen?->toIso8601String(),
                    ];

                    continue;
                }

                $below[] = [
                    'id' => (string) $device->id,
                    'name' => (string) $device->name,
                    // `type` là backed enum sau cast của omnify — ép chuỗi thẳng
                    // sẽ ném. Lấy `->value` khi có.
                    'type' => $device->type instanceof \BackedEnum ? $device->type->value : (string) $device->type,
                    'version' => $version,
                    'source' => $source,
                ];
            }
        }

        $rows = array_values($rows);
        usort($rows, fn ($a, $b) => [$b['count'], $a['version']] <=> [$a['count'], $b['version']]);

        $payload = [
            'total' => $devices->count(),
            'by_version' => $rows,
            'min_version' => $min,
            'below_min' => $min !== null ? count($below) : null,
            'below' => $min !== null ? $below : null,
            // Loại ai ra thì phải NÓI RA. Một bộ lọc âm thầm đọc y hệt "đã phủ
            // hết" — đúng thứ làm người ta tin một con số 0 không có nghĩa.
            'active_within_days' => $activeWithin !== null && $activeWithin !== '' ? (int) $activeWithin : null,
            'excluded_inactive' => $activeSince !== null ? $excludedInactive : null,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info("Thiết bị: {$payload['total']}".($type ? " (type={$type})" : ''));
            $this->table(['Phiên bản', 'Nguồn', 'Số máy'], array_map(
                fn ($r) => [$r['version'], $r['source'], $r['count']],
                $rows,
            ));

            if ($min !== null) {
                $this->line('');
                $this->info("Dưới {$min}: ".count($below).' máy'
                    .($activeSince !== null ? " (chỉ máy hoạt động trong {$activeWithin} ngày qua)" : ''));
                foreach ($below as $d) {
                    $this->line("  · {$d['name']} ({$d['type']}) — ".($d['version'] ?? '(không rõ)')." [{$d['source']}]");
                }

                if ($excludedInactive !== []) {
                    $this->line('');
                    $this->warn('Đã LOẠI '.count($excludedInactive).' máy im lâu khỏi phép đếm trên:');
                    foreach ($excludedInactive as $d) {
                        $this->line("  · {$d['name']} — ".($d['version'] ?? '(không rõ)')
                            .', last_seen '.($d['last_seen_at'] ?? 'chưa bao giờ'));
                    }
                }
            }
        }

        // Cổng dùng được trong script: #2412/#2666 chỉ được xoá đường cũ khi con
        // số này bằng 0. Thoát khác 0 để một lượt chạy tự động không thể "quên".
        if ($this->option('require-min') && $min !== null && $below !== []) {
            $this->error('Còn '.count($below).' máy dưới '.$min.' — chưa được xoá đường tương thích cũ.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * So semver thô. `null` (chưa từng báo) ĐƯỢC TÍNH LÀ DƯỚI ngưỡng.
     *
     * Đó là chiều fail-closed đúng: một máy chưa bao giờ báo phiên bản không
     * phải bằng chứng nó đã nâng cấp; coi nó là "đạt" biến một câu hỏi chưa trả
     * lời được thành một câu trả lời "rồi".
     */
    private function isBelow(?string $version, string $min): bool
    {
        if ($version === null || trim($version) === '') {
            return true;
        }

        $norm = fn (string $v) => array_map('intval', array_pad(
            explode('.', ltrim(trim($v), 'vV')), 3, '0'
        ));

        return $norm($version) < $norm($min);
    }
}
