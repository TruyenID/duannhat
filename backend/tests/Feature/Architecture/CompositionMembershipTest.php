<?php

declare(strict_types=1);

/**
 * #1591 — `Composition` được phép phụ thuộc mọi module, nên nó là chỗ dễ biến
 * thành thùng rác nhất trong cả đồ thị: khai một service vào đây là mọi cạnh
 * của nó biến mất mà không ai phải trả gì.
 *
 * `App\Http` · `App\Console` · `App\Providers` … là Composition theo bản chất
 * (delivery surface, composition root). Nhưng #1591 thêm mục `App\Services\*`
 * ĐẦU TIÊN, và đó là loại mục cần rào.
 *
 * Hai tính chất, cưỡng chế bằng máy chứ không bằng lời hứa:
 *
 *   1. **0 cạnh VÀO từ module** — nếu một module phụ thuộc nó, nó không phải
 *      tầng nối dây, nó là một phụ thuộc chung và phải sống trong một module.
 *   2. **KHÔNG GHI** — Composition không sở hữu aggregate nào. Một service ghi
 *      dữ liệu thì có bất biến để giữ, và bất biến thuộc về một module.
 *
 * Chỉ soi `App\Services\*`: `App\Console` có command ghi dữ liệu là chuyện
 * bình thường và đúng.
 */

use App\Console\Commands\DeptracConfigCommand;
use Illuminate\Support\Facades\Artisan;

/**
 * #1596 — class LẺ khai Composition (`composition_classes` trong config/modules.php).
 *
 * Cơ chế thứ hai, cùng hai tiêu chí. Không gộp vào hàm namespace bên dưới vì một
 * bên trả về THƯ MỤC còn bên này trả về FILE — gộp lại thì phải đoán, và đoán
 * sai là bài test âm thầm bỏ qua một nửa.
 *
 * @return list<string>
 */
function compositionClassFiles(): array
{
    $manifest = require base_path('config/modules.php');

    return array_map(
        static fn (string $fqn): string => app_path(str_replace('\\', '/', substr($fqn, strlen('App\\'))).'.php'),
        $manifest['composition_classes'] ?? [],
    );
}

/** @return list<string> */
function compositionServiceNamespaces(): array
{
    $ref = new ReflectionClass(DeptracConfigCommand::class);
    /** @var list<string> $all */
    $all = $ref->getConstant('COMPOSITION');

    return array_values(array_filter(
        $all,
        static fn (string $ns): bool => str_starts_with($ns, 'App\\Services\\'),
    ));
}

it('C-M1: service khai là Composition thì KHÔNG được ghi gì', function () {
    $namespaces = compositionServiceNamespaces();

    // Cả hai danh sách rỗng ⇒ bài test không khẳng định gì. Nói ra, đừng xanh im lặng.
    expect(count($namespaces) + count(compositionClassFiles()))->toBeGreaterThan(
        0,
        'Không có mục App\\Services\\* nào trong COMPOSITION lẫn composition_classes — bài test này đang không ghim gì.'
    );

    $writes = '/->save\(|->update\(|->delete\(|->insert\(|->forceFill\(|::create\(|::updateOrCreate\(|DB::(insert|update|delete)\(/';
    $offenders = [];

    foreach (compositionClassFiles() as $file) {
        expect(is_file($file))->toBeTrue("Khai composition_classes nhưng không có file: {$file}");
        if (preg_match($writes, (string) file_get_contents($file)) === 1) {
            $offenders[] = str_replace(base_path().'/', '', $file);
        }
    }

    foreach ($namespaces as $ns) {
        $dir = app_path(str_replace('\\', '/', substr($ns, strlen('App\\'))));
        expect(is_dir($dir))->toBeTrue("Khai Composition nhưng không có thư mục: {$ns}");

        foreach (glob($dir.'/*.php') ?: [] as $file) {
            if (preg_match($writes, (string) file_get_contents($file)) === 1) {
                $offenders[] = str_replace(base_path().'/', '', $file);
            }
        }
    }

    expect($offenders)->toBe([], implode("\n  ", [
        'Service khai là Composition nhưng có GHI dữ liệu:',
        '',
        ...$offenders,
        '',
        'Composition không sở hữu aggregate nào. Có ghi nghĩa là có bất biến để',
        'giữ, và bất biến thuộc về một MODULE. Đưa nó về module đúng, đừng gỡ',
        'bài test.',
    ]));
});

it('C-M2: KHÔNG module nào được phụ thuộc một service khai là Composition', function () {
    $namespaces = compositionServiceNamespaces();

    // Quét nguồn thay vì đọc deptrac: deptrac KHÔNG báo cạnh này (module →
    // Composition bị cấm nên nó là "vi phạm", nhưng baseline có thể che), và
    // quan trọng hơn — quét nguồn chỉ thẳng FILE nào gọi.
    $offenders = [];
    $manifest = require base_path('config/modules.php');
    foreach ($manifest['composition_classes'] ?? [] as $fqn) {
        $needle = 'use '.$fqn.';';
        foreach (glob(app_path('Services').'/*/*.php') ?: [] as $file) {
            if (str_contains((string) file_get_contents($file), $needle)) {
                $offenders[] = str_replace(base_path().'/', '', $file).' → '.$fqn;
            }
        }
    }
    foreach ($namespaces as $ns) {
        $needle = 'use '.$ns.'\\';
        foreach (glob(app_path('Services').'/*/*.php') ?: [] as $file) {
            if (str_starts_with($file, app_path(str_replace('\\', '/', substr($ns, strlen('App\\')))))) {
                continue; // chính nó
            }
            if (str_contains((string) file_get_contents($file), $needle)) {
                $offenders[] = str_replace(base_path().'/', '', $file).' → '.$ns;
            }
        }
    }

    expect($offenders)->toBe([], implode("\n  ", [
        'Một service trong MODULE đang phụ thuộc một service khai là Composition:',
        '',
        ...$offenders,
        '',
        'Nếu module cần nó thì nó không phải tầng nối dây — nó là phụ thuộc chung',
        'và phải sống trong một module (hoặc sau một cổng công bố).',
    ]));
});

it('C-M3: deptrac.yaml sinh ra khớp danh sách đang khai', function () {
    // Nếu generator quên phát mục mới thì hai bài trên vẫn xanh trong khi phép
    // đo thật không đổi — đúng loại "sai mà không kêu tiếng nào" đã trả giá
    // nhiều lần ở epic này.
    expect(Artisan::call('architecture:deptrac-config', ['--check' => true]))->toBe(0, Artisan::output());

    $yaml = (string) file_get_contents(base_path('deptrac.yaml'));
    foreach (compositionServiceNamespaces() as $ns) {
        expect($yaml)->toContain(str_replace('\\', '\\\\', $ns));
    }
});
