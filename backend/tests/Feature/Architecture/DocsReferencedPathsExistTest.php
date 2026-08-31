<?php

declare(strict_types=1);

/**
 * #2406 (#2188 docs) — một tài liệu trỏ tới file KHÔNG TỒN TẠI là tuyên bố sai
 * kiểm được bằng máy.
 *
 * Đợt rà claims-vs-code trên 83 file `docs/{guide,reference,explanation}` cho ra
 * đúng bốn drift thật, và **ba trong bốn là đường dẫn chết**:
 *
 *   - `docs/explanation/notification-rules.md` → `docs/reference/notification-rules-api.md`
 *     (tên thật: `notifications-api.md`)
 *   - `docs/guide/local-config.md` → `tests/Feature/Seeders/SeederFixtures…Test.php`
 *     (thật: `backend/tests/Feature/Architecture/…`)
 *   - `docs/guide/payment-topology-and-tender-model.md` → `workstation/local_pos_till.go`
 *     (thật: `workstation/internal/handler/local_pos_till.go`)
 *
 * Cả ba đều là loại hỏng ÂM THẦM: người đọc mở doc, không tìm thấy file, rồi tự
 * đoán — hoặc tệ hơn, kết luận tính năng không tồn tại. Rào này bắt lớp đó ngay
 * lúc PR thay vì lúc ai đó cần tới.
 *
 * ## Vì sao CHỈ nhận đường dẫn có tiền tố thư mục gốc
 *
 * Docs hợp lệ nhắc rất nhiều đường dẫn TƯƠNG ĐỐI có ngữ cảnh ngay trên nó —
 * `Renderer/Escpos.php` dưới tiêu đề `App\Services\Print\Renderer\`,
 * `hooks/api/use-till.ts` sau khi đã nêu `web/pos/src/...`, hay artifact build
 * `dist/pos-bundle-version.json` (gitignore). Bắt hết những thứ đó thì rào kêu
 * nhiều hơn nói, và một rào hay kêu oan là rào bị tắt. Nên chỉ những chuỗi bắt
 * đầu bằng một thư mục gốc CÓ THẬT mới bị xét — chúng tự nhận là đường dẫn
 * tuyệt đối trong repo và không có cách đọc nào khác.
 */

/** @return list<string> */
function docsMarkdownFiles(): array
{
    $root = dirname(base_path()).'/docs';
    if (! is_dir($root)) {
        return [];
    }

    $out = [];
    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'md') {
            $out[] = $file->getPathname();
        }
    }
    sort($out);

    return $out;
}

/** Thư mục gốc của monorepo — một chuỗi mở đầu bằng chúng LÀ đường dẫn repo. */
const DOCS_PATH_ROOTS = [
    'backend/', 'workstation/', 'web/', 'app/', 'schemas/', 'packages/',
    'docs/', 'plans/', '.github/', 'scripts/',
];

it('#2406 — mọi đường dẫn repo nhắc trong docs đều tồn tại', function () {
    $repo = dirname(base_path());
    $offenders = [];

    foreach (docsMarkdownFiles() as $path) {
        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $n => $line) {
            preg_match_all('/`([^`\n]+)`/', $line, $m);
            foreach ($m[1] as $token) {
                $token = trim($token);

                // Chỉ xét chuỗi tự nhận là đường dẫn repo tuyệt đối.
                $isRepoPath = false;
                foreach (DOCS_PATH_ROOTS as $root) {
                    if (str_starts_with($token, $root)) {
                        $isRepoPath = true;
                        break;
                    }
                }
                if (! $isRepoPath) {
                    continue;
                }
                // Phải trông như FILE (có đuôi) — `backend/routes/` là thư mục,
                // và `backend/app/**` là glob, cả hai không phải đối tượng ở đây.
                if (! preg_match('/\.(php|go|ts|tsx|js|jsx|mjs|json|ya?ml|sql|sh|md)$/', $token)) {
                    continue;
                }
                // Glob, placeholder và dấu lược — không phải một file cụ thể.
                if (preg_match('/[*{}<>]|\.\.\./', $token)) {
                    continue;
                }

                // `app/…` mang HAI nghĩa trong repo này: thư mục app của
                // monorepo (`app/kds`, `app/tms`) và quy ước Laravel, nơi
                // `app/Services/Foo.php` nghĩa là `backend/app/Services/Foo.php`.
                // Docs dùng cả hai và cả hai đều đọc được — nên chấp nhận nếu
                // MỘT trong hai phân giải được.
                $candidates = [$repo.'/'.$token];
                if (str_starts_with($token, 'app/')) {
                    $candidates[] = $repo.'/backend/'.$token;
                }

                $found = false;
                foreach ($candidates as $candidate) {
                    if (file_exists($candidate)) {
                        $found = true;
                        break;
                    }
                }

                if (! $found) {
                    $rel = ltrim(str_replace($repo, '', $path), '/');
                    $offenders[] = "{$rel}:".($n + 1)."  →  {$token}";
                }
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", array_merge(
        ['Tài liệu trỏ tới file không tồn tại (#2406):', ''],
        $offenders,
        ['', 'Sửa đường dẫn, hoặc xoá câu nếu thứ nó mô tả đã bị gỡ. Một đường dẫn'],
        ['chết làm người đọc kết luận sai về hệ thống, không chỉ làm phiền họ.'],
    )));
});

it('#2406 — mọi link markdown nội bộ giữa các doc đều tới đích có thật', function () {
    $repo = dirname(base_path());
    $offenders = [];

    foreach (docsMarkdownFiles() as $path) {
        $dir = dirname($path);
        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $n => $line) {
            preg_match_all('/\[[^\]]*\]\(([^)]+)\)/', $line, $m);
            foreach ($m[1] as $href) {
                if (preg_match('~^(https?://|\#|mailto:)~', $href)) {
                    continue;
                }
                $target = explode('#', $href)[0];
                if ($target === '') {
                    continue;
                }
                // `documentation.md` dạy CÚ PHÁP link bằng ví dụ — chúng cố ý
                // trỏ vào hư không. Loại theo file, không theo từng dòng, để
                // không phải sửa test mỗi lần ví dụ đổi chỗ.
                if (str_ends_with($path, 'docs/contributing/documentation.md')) {
                    continue;
                }

                $resolved = realpath($dir.'/'.$target) ?: realpath($repo.'/'.$target);
                if ($resolved === false) {
                    $rel = ltrim(str_replace($repo, '', $path), '/');
                    $offenders[] = "{$rel}:".($n + 1)."  →  {$href}";
                }
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", array_merge(
        ['Link nội bộ hỏng giữa các tài liệu (#2406):', ''],
        $offenders,
    )));
});
