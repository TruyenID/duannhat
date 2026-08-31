<?php

declare(strict_types=1);

/**
 * #1597 — `architecture:publishable-candidates` trả `0 ứng viên`. Bài test này
 * tồn tại để phân biệt **"repo sạch"** với **"phép quét hỏng"**.
 *
 * Đó không phải lo xa: trong epic này, một phép quét trả 0 vì thiếu
 * `--report-skipped` đã từng làm `LayerCyclesTest` báo "không có chu trình nào"
 * — xanh, và sai. Cùng phiên còn hai lần khác: một `grep` sai cú pháp trả
 * "không có lời gọi ghi nào" qua nhánh `||`, và một `perl -0pi` không khớp mà
 * không kêu, làm một mutation-check tưởng là đã cắn.
 */

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

it('P1: lệnh chạy được và trả đúng hình dạng JSON', function () {
    $exit = Artisan::call('architecture:publishable-candidates', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)
        ->and($decoded)->toBeArray()
        ->and($decoded)->toHaveKey('candidates')
        ->and($decoded['candidates'])->toBeArray();
});

it('P2: phép quét KHÔNG mù — báo cáo deptrac nó đọc phải có vi phạm', function () {
    $out = tempnam(sys_get_temp_dir(), 'deptrac-blind-').'.json';
    $process = new Process(
        ['vendor/bin/deptrac', 'analyse', '--no-progress', '--report-skipped', '--formatter=json', '--output='.$out],
        base_path(), null, null, 300.0,
    );
    $process->run();

    expect(is_file($out))->toBeTrue('deptrac không sinh báo cáo — lệnh sẽ báo 0 ứng viên vì mù, không phải vì sạch.');

    $report = json_decode((string) file_get_contents($out), true);
    @unlink($out);

    expect($report['Report']['Skipped violations'] ?? 0)->toBeGreaterThan(
        0,
        'Báo cáo không có vi phạm bị bỏ qua nào — gần như chắc chắn thiếu `--report-skipped`, '.
        'chứ không phải nợ đã trả hết. Đúng cái bẫy đã bắt được ở LayerCyclesTest.'
    );
});

it('P3: tiêu chí NHẬN DIỆN đúng — ba value object của #1609 vẫn thoả và vẫn được khai', function () {
    // Không mutate `config/modules.php` để thử: bài test đó sẽ sửa file dùng chung
    // giữa các session. Thay vào đó ghim cả hai vế trên ba ca ĐÃ BIẾT đúng —
    // nếu tiêu chí "0 import ngoài" hỏng, hoặc ai đó gỡ chúng khỏi danh sách
    // công bố, bài này đỏ.
    $manifest = require base_path('config/modules.php');
    $declared = $manifest['published_contracts'] ?? [];

    foreach (['PricingResult', 'TaxGroup', 'TaxResolution'] as $short) {
        $fqn = 'App\\Services\\Customer\\'.$short;
        // `toContain` nhận thêm đối số như GIÁ TRỊ phải chứa, không phải thông điệp —
        // dùng nó ở đây làm bài test đỏ vì lý do sai. Kiểm bằng in_array cho rõ.
        expect(in_array($fqn, $declared, true))->toBeTrue("{$short} phải nằm trong published_contracts (#1609).");

        $src = (string) file_get_contents(app_path('Services/Customer/'.$short.'.php'));
        preg_match_all('/^use ([^;]+);/m', $src, $uses);
        $foreign = array_values(array_filter(
            $uses[1] ?? [],
            static fn (string $i): bool => ! str_starts_with($i, 'App\\Services\\DomainMutation')
                && ! str_starts_with($i, 'App\\Omnify\\Enums'),
        ));

        expect($foreign)->toBe([], "{$short} vừa mọc thêm import ngoài — nó thôi đủ điều kiện công bố, và deptrac sẽ đỏ.");
    }
});
