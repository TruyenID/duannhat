<?php

declare(strict_types=1);

/**
 * #1499 — tài liệu auth công bố không được đổi nội dung theo máy người chạy.
 *
 * Trước bản sửa: một tên file cho HAI chế độ, còn nội dung thì do
 * `OMNIFY_AUTH_MODE` chọn. Repo giữ bản `console`, mặc định env là `standalone`,
 * nên `php artisan l5-swagger:generate` trên máy bất kỳ ghi đè `info.title` từ
 * "Console SSO" thành "Standalone Auth" — hai dòng, exit 0, lẫn trong một PR về
 * việc khác. Đã xảy ra ở #1339 và phải revert tay.
 *
 * Hai tính chất dưới đây, mỗi cái đủ để chặn một nửa của lỗi đó.
 */
it('#1499 tên file tài liệu auth mang CHẾ ĐỘ, nên chế độ kia không ghi đè được', function () {
    $json = config('l5-swagger.documentations.auth.paths.docs_json');
    $yaml = config('l5-swagger.documentations.auth.paths.docs_yaml');

    // Một tên cố định là đúng hình dạng đã hỏng: hai chế độ tranh nhau một file.
    expect($json)->toContain('console');
    expect($yaml)->toContain('console');
    expect($json)->not->toBe('auth-api-docs.json');
});

it('#1499 file đang commit khớp với chế độ trong tên nó', function () {
    $path = storage_path('api-docs/'.config('l5-swagger.documentations.auth.paths.docs_json'));

    expect(file_exists($path))->toBeTrue("thiếu {$path}");

    $doc = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    // Tính chất thật: artifact nói đúng cái mà tên nó hứa. Một file tên
    // `auth-console-…` mang `info.title` "Standalone Auth" chính là trạng thái
    // mà lỗi này để lại, và nó không có gì khác kêu lên.
    expect($doc['info']['title'])->toContain('Console');
});

it('#1499 mặc định chế độ là console — đúng cái app này thật sự chạy', function () {
    // `bootstrap/app.php` đăng ký `AuthenticateSso` vô điều kiện và không chỗ
    // nào trong `config/` hay workflow deploy đặt `OMNIFY_AUTH_MODE`. Mặc định
    // `standalone` vì thế sai với MỌI môi trường repo này từng deploy.
    $config = (string) file_get_contents(config_path('l5-swagger.php'));

    expect(str_contains($config, "env('OMNIFY_AUTH_MODE', 'console')"))->toBeTrue(
        'mặc định OMNIFY_AUTH_MODE phải là console — xem docblock đầu config/l5-swagger.php (#1499)',
    );
});
