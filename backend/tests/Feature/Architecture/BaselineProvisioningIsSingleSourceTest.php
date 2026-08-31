<?php

use Symfony\Component\Finder\Finder;

/**
 * #2320 — rào chống bản cài đặt baseline thứ hai mọc lại.
 *
 * Repo này đã có BA bản cùng cấp bộ loại thuế chuẩn (`TaxTypeService`,
 * `TaxTypeSeeder`, `JapaneseTaxSeeder`), và chúng đánh nhau: bản thứ ba XOÁ hàng
 * của bản thứ nhất để chiếm chỗ cho id tất định của nó, đồng thời ghi thẳng SQL
 * nên bỏ qua `tax_type_rates`. Cái giá thật là **13 hàng product mang 軽減税率 8%
 * bị san phẳng về 10% ở mọi lượt seed** — thu vượt của khách.
 *
 * Ba luật dưới đây không chứng minh baseline đúng; chúng chỉ giữ cho nó ở lại
 * MỘT chỗ, thứ mà lần trước không ai giữ.
 */
function provisioningSourceFiles(string $dir): Finder
{
    return Finder::create()->files()->in(base_path($dir))->name('*.php');
}

/**
 * Mã nguồn KHÔNG kèm comment.
 *
 * Bắt buộc, không phải cho gọn: `AppServiceProvider` nhắc tên
 * `ensureStandardTypesForBrand` trong một docblock giải thích vì sao hook
 * KHÔNG gọi nó. Quét thô sẽ đếm đúng lời giải thích đó là vi phạm — cùng cái
 * bẫy "khớp trong comment" đã ba lần cho ra chỉ số sai ở repo này.
 */
function provisioningCodeOnly(string $path): string
{
    return php_strip_whitespace($path);
}

it('chỉ BrandBaselineProvisioner được cấp bộ loại thuế chuẩn', function () {
    $allowed = [
        // Chủ sở hữu miền thuế — bản cài đặt.
        'app/Services/Tax/TaxTypeService.php',
        // Người gọi duy nhất.
        'app/Services/Provisioning/BrandBaselineProvisioner.php',
    ];

    $offenders = [];
    foreach (['app', 'database'] as $dir) {
        foreach (provisioningSourceFiles($dir) as $file) {
            $relative = str_replace(base_path().'/', '', $file->getPathname());
            if (in_array($relative, $allowed, true)) {
                continue;
            }
            if (str_contains(provisioningCodeOnly($file->getPathname()), 'ensureStandardTypesForBrand')) {
                $offenders[] = $relative;
            }
        }
    }

    expect($offenders)->toBe([], 'Gọi ensureStandardTypesForBrand ngoài provisioner: '.implode(', ', $offenders));
});

it('không seeder nào được xoá trắng liên kết loại thuế', function () {
    $offenders = [];
    foreach (provisioningSourceFiles('database/seeders') as $file) {
        $source = provisioningCodeOnly($file->getPathname());
        foreach (["'tax_type_id' => null", "'default_tax_type_id' => null"] as $needle) {
            if (str_contains($source, $needle)) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).": {$needle}";
            }
        }
    }

    expect($offenders)->toBe(
        [],
        "Ánh xạ theo mã, đừng ghi null — null im lặng đánh mất thuế suất:\n".implode("\n", $offenders),
    );
});

it('không seeder nào được đóng dấu loại thuế lên product ĐÃ có', function () {
    // Một `update(['tax_type_id' => ...])` không kèm `whereNull` là bản cũ của
    // JapaneseTaxSeeder: nó dán đè lựa chọn 軽減税率 của người vận hành.
    $offenders = [];
    foreach (provisioningSourceFiles('database/seeders') as $file) {
        $source = provisioningCodeOnly($file->getPathname());
        if (! preg_match("/update\(\[\s*'tax_type_id'/", $source)) {
            continue;
        }
        if (! str_contains($source, "whereNull('tax_type_id')")) {
            $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }

    expect($offenders)->toBe([], 'Đóng dấu thuế không có whereNull: '.implode(', ', $offenders));
});
