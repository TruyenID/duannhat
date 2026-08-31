<?php

declare(strict_types=1);
use App\Console\Commands\DeptracConfigCommand;

/**
 * #1583 — "API công bố" là tư cách thành viên `PublishedContracts`, và tư cách
 * đó có ĐIỀU KIỆN: không rò model.
 *
 * Deptrac đã cưỡng chế điều đó ở tầng đồ thị (`PublishedContracts` chỉ được phụ
 * thuộc hai kernel). Bài test này ghim cùng luật ở tầng NGUỒN, vì hai lý do:
 *
 *  - nó chỉ thẳng FILE nào sai, còn deptrac chỉ nói "layer không được phụ thuộc";
 *  - nó chạy trong `arch-gate` mà không cần regen `deptrac.yaml` trước.
 *
 * Và nó ghim nửa còn lại của quyết định: `App\Services\Order\Internal` KHÔNG
 * được công bố. Công bố nó là mở lại đúng cái #1583 đi hỏi.
 */

/**
 * Model của TenancyKernel là ngoại lệ CÓ CHỦ ĐÍCH, không phải kẽ hở.
 *
 * `Organization` / `Brand` / `Branch` / `BranchTranslation` / `User` được khai là
 * kernel vì mọi module đều phải phân giải "ở đâu" và "ai" — deptrac cho phép
 * `PublishedContracts → TenancyKernel`, nên bài test này phải cho phép y hệt.
 *
 * Danh sách chép từ `DeptracConfigCommand::TENANCY_KERNEL`. Lệch nhau thì bài
 * test sẽ nghiêm hơn hoặc lỏng hơn đồ thị thật — cả hai đều là nói dối, nên
 * `TenancyKernelListMatchesTest` ở dưới ghim hai bên khớp nhau.
 */
const TENANCY_KERNEL_MODELS = ['Organization', 'Brand', 'Branch', 'BranchTranslation', 'User'];

it('không hợp đồng công bố nào import App\\Models (ngoài TenancyKernel)', function () {
    $manifest = require base_path('config/modules.php');

    $files = [];
    foreach ($manifest['published_contract_namespaces'] ?? [] as $ns) {
        $dir = app_path(str_replace('\\', '/', substr($ns, strlen('App\\'))));
        expect(is_dir($dir))->toBeTrue("Namespace đã khai nhưng không có thư mục: {$ns}");
        foreach (glob($dir.'/*.php') ?: [] as $f) {
            $files[] = $f;
        }
    }
    foreach ($manifest['published_contracts'] ?? [] as $fqn) {
        $files[] = app_path(str_replace('\\', '/', substr($fqn, strlen('App\\'))).'.php');
    }

    // Danh sách rỗng nghĩa là bài test mù, không phải repo sạch.
    expect(count($files))->toBeGreaterThan(50, 'Quét ra quá ít file — phép quét hỏng, không phải nợ đã hết.');

    $leaky = [];
    foreach ($files as $file) {
        if (! is_file($file)) {
            $leaky[] = basename($file).' — KHAI NHƯNG KHÔNG TỒN TẠI';

            continue;
        }
        preg_match_all('/^use App\\\\Models\\\\(\\w+)/m', (string) file_get_contents($file), $hits);
        $offenders = array_values(array_diff($hits[1] ?? [], TENANCY_KERNEL_MODELS));
        if ($offenders !== []) {
            $leaky[] = str_replace(base_path().'/', '', $file).' → '.implode(', ', $offenders);
        }
    }

    expect($leaky)->toBe([], implode("\n  ", [
        'Hợp đồng công bố mang model Eloquent trong chữ ký:',
        '',
        ...$leaky,
        '',
        'Cổng rò model chỉ ĐẢO CHIỀU vi phạm chứ không gỡ — module ngoài vẫn phải',
        'biết model của chủ sở hữu. Sửa CỔNG (nhận primitive), đừng gỡ nó khỏi',
        'danh sách để test xanh.',
    ]));
});

it('App\\Services\\Order\\Internal KHÔNG được công bố', function () {
    $manifest = require base_path('config/modules.php');
    $declared = [
        ...($manifest['published_contracts'] ?? []),
        ...($manifest['published_contract_namespaces'] ?? []),
    ];

    foreach ($declared as $entry) {
        expect(str_starts_with($entry, 'App\\Services\\Order\\Internal'))->toBeFalse(
            "`{$entry}` công bố phần Internal của Ordering. Đó là persistence, ".
            'verifier và context factory — module ngoài không có việc gì ở đó, '.
            'và công bố nó là mở lại đúng câu hỏi #1583 đã đóng.'
        );
    }
});

it('danh sách TenancyKernel trong bài test khớp generator', function () {
    // Bản sao lệch nhau là cách bài test trên âm thầm sai: nghiêm hơn đồ thị thì
    // nó chặn thứ hợp lệ, lỏng hơn thì nó bỏ lọt thứ deptrac sẽ bắt sau.
    $ref = new ReflectionClass(DeptracConfigCommand::class);
    $fromGenerator = $ref->getConstant('TENANCY_KERNEL');

    expect($fromGenerator)->toBe(TENANCY_KERNEL_MODELS);
});
