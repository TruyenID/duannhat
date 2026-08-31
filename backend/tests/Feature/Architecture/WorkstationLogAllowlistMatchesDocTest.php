<?php

declare(strict_types=1);

/**
 * #2901 — bản KHAI và bản CHẠY của allowlist log máy trạm phải bằng nhau.
 *
 * Hợp đồng nói: nguồn chân lý là MỘT file trong repo,
 * `docs/reference/workstation-log-allowlist.md`, và **hai đầu cùng đọc** —
 * máy trạm lọc theo nó trước khi gửi, Cloud kiểm lại theo nó lúc nhận.
 *
 * Cloud không đọc thẳng file markdown lúc chạy, và lý do là một phép đo chứ
 * không phải sở thích: `.github/workflows/deploy-xserver.yml` rsync **chỉ thư
 * mục `backend/`** lên máy chủ, nên `docs/` ở gốc repo KHÔNG tồn tại trên
 * production. Một allowlist đọc từ file không có sẽ rỗng — và vì cơ chế là
 * fail-closed, rỗng nghĩa là **từ chối mọi dòng, im lặng**, đúng lúc người ta
 * cần đọc log nhất. Đó là kiểu hỏng mà repo này đã gọi tên ở #2777: một bản vá
 * trông như đã ship.
 *
 * Nên bản chạy là `config/workstation_log_allowlist.php`, và file test này là
 * thứ giữ hai bản không trôi ra khỏi nhau. Nó phân tích chính bảng markdown —
 * không phải một bản chép — rồi so từng message và từng attr, cả hai chiều.
 *
 * Rào này phải KÊU ĐƯỢC và IM ĐƯỢC:
 *   - thêm một dòng vào markdown mà quên config ⇒ đỏ
 *   - thêm một khoá vào config mà không khai trong markdown ⇒ đỏ (chiều này
 *     mới là chiều nguy hiểm: nó là đường để một attr lặng lẽ được phép mà bản
 *     khai người ta đọc không hề nhắc tới)
 *   - sửa cả hai cùng lúc ⇒ xanh
 *
 * Nằm ở `tests/Feature/Architecture/` vì đó là thư mục DUY NHẤT mà `arch-gate`
 * chạy trên MỌI PR vào `dev`.
 */

use Illuminate\Support\Str;

/**
 * Đường tới bản khai. Nó nằm NGOÀI `backend/`, ở gốc repo — cố ý, vì máy trạm
 * (Go) cũng phải đọc được nó.
 */
function workstationLogAllowlistDocPath(): string
{
    return dirname(base_path()).'/docs/reference/workstation-log-allowlist.md';
}

/**
 * Phân tích bảng markdown thành `message => list<attr>`.
 *
 * Chỉ nhận dòng bảng có ô đầu là MỘT chuỗi trong backtick — dòng tiêu đề, dòng
 * gạch, và bảng "Luật đọc bảng" ở đầu tài liệu (ô đầu là văn bản thường) đều
 * tự rơi ra. `—` ở ô thứ hai nghĩa là "không attr nào".
 *
 * @return array<string, list<string>>
 */
function parseWorkstationLogAllowlistDoc(string $markdown): array
{
    $out = [];
    $inAllowlist = false;

    foreach (explode("\n", $markdown) as $line) {
        $line = trim($line);

        // Chỉ đọc các bảng NẰM DƯỚI mục "Đợt đầu". Phần đầu tài liệu cũng có
        // bảng (luật đọc, ba cách từ chối) và một ô của nó rất dễ vô tình
        // trông y hệt một dòng allowlist. Neo theo mục là cách rào này không
        // tự nuốt một message không ai khai.
        if (str_starts_with($line, '## Đợt đầu')) {
            $inAllowlist = true;

            continue;
        }

        // Mục kế tiếp cùng cấp đóng vùng lại.
        if ($inAllowlist && str_starts_with($line, '## ') && ! str_starts_with($line, '## Đợt đầu')) {
            $inAllowlist = false;
        }

        if (! $inAllowlist || ! str_starts_with($line, '|')) {
            continue;
        }

        $cells = array_map(trim(...), explode('|', trim($line, '|')));

        if (count($cells) !== 2) {
            continue;
        }

        [$messageCell, $attrCell] = $cells;

        if (! preg_match('/^`(.+)`$/s', $messageCell, $m)) {
            continue;
        }

        $attrs = [];

        if ($attrCell !== '—' && $attrCell !== '') {
            preg_match_all('/`([^`]+)`/', $attrCell, $found);
            $attrs = $found[1];
        }

        $out[$m[1]] = $attrs;
    }

    return $out;
}

it('#2901 bản khai markdown tồn tại và không rỗng — 0 là một khẳng định, không phải mặc định', function () {
    $path = workstationLogAllowlistDocPath();

    expect(file_exists($path))->toBeTrue(
        "Không thấy bản khai allowlist ở {$path}. Nó là hợp đồng hai đầu (#2901) — máy trạm Go cũng đọc nó."
    );

    $declared = parseWorkstationLogAllowlistDoc((string) file_get_contents($path));

    // Phép đo, không phải trang trí: một bộ phân tích hỏng (đổi định dạng bảng,
    // đổi dấu gạch dài) sẽ trả mảng RỖNG, và một allowlist rỗng so với một
    // config rỗng vẫn "bằng nhau". Neo con số lại là cách rào này không tự nói
    // dối được.
    expect(count($declared))->toBeGreaterThan(50);
});

it('#2901 mọi message trong bản khai đều có trong config Cloud, với ĐÚNG danh sách attr', function () {
    $declared = parseWorkstationLogAllowlistDoc((string) file_get_contents(workstationLogAllowlistDocPath()));

    /** @var array<string, list<string>> $runtime */
    $runtime = config('workstation_log_allowlist.messages');

    foreach ($declared as $message => $attrs) {
        expect(array_key_exists($message, $runtime))->toBeTrue(
            "Message `{$message}` khai trong docs/reference/workstation-log-allowlist.md nhưng KHÔNG có trong config/workstation_log_allowlist.php. ".
            'Cloud sẽ bỏ mọi dòng mang message này và đếm vào `rejected` — máy trạm gửi lên mà không ai nhận.'
        );

        expect($runtime[$message] === $attrs)->toBeTrue(
            "Danh sách attr của `{$message}` lệch giữa bản khai và config. Bản khai: [".implode(', ', $attrs).
            '] · config: ['.implode(', ', $runtime[$message]).']'
        );
    }
});

it('#2901 config Cloud KHÔNG cho phép gì mà bản khai không nhắc tới', function () {
    $declared = parseWorkstationLogAllowlistDoc((string) file_get_contents(workstationLogAllowlistDocPath()));

    /** @var array<string, list<string>> $runtime */
    $runtime = config('workstation_log_allowlist.messages');

    foreach ($runtime as $message => $attrs) {
        expect(array_key_exists($message, $declared))->toBeTrue(
            "Config Cloud cho phép message `{$message}` nhưng docs/reference/workstation-log-allowlist.md không khai nó. ".
            'Đây là chiều nguy hiểm: một message (và các attr của nó) lặng lẽ được phép rời khỏi quán trong khi bản khai mà người ta đọc để duyệt PII không hề nhắc tới.'
        );
    }
});

it('#2901 KHÔNG khai attr lỗi tự do — err/error là lỗ blocklist trong một cơ chế allowlist', function () {
    /** @var array<string, list<string>> $runtime */
    $runtime = config('workstation_log_allowlist.messages');

    // Chuỗi lỗi là văn bản tự do do bên thứ ba sinh: lỗi HTTP có thể mang
    // nguyên thân phản hồi (feed `customers` chở tên/điện thoại/email khách),
    // lỗi SQLite nhắc lại giá trị cột vi phạm ràng buộc. Không khai trước được
    // rằng một chuỗi lỗi sẽ không chứa PII.
    foreach ($runtime as $message => $attrs) {
        foreach ($attrs as $attr) {
            expect(Str::lower($attr))->not->toBeIn(
                ['err', 'error', 'detail', 'body', 'response', 'payload'],
                "Message `{$message}` khai attr `{$attr}`. Đó là văn bản tự do — khai nó là nhét một lỗ blocklist vào giữa một cơ chế allowlist (#2901, lý lẽ đầy đủ ở bản khai)."
            );
        }
    }
});

it('#2901 mọi message khai ra đều VỪA cột message (255) — dài quá thì dòng không bao giờ lưu được', function () {
    /** @var array<string, list<string>> $runtime */
    $runtime = config('workstation_log_allowlist.messages');

    foreach ($runtime as $message => $attrs) {
        // `validate()` chặn ở `max:255`, nên một message dài hơn sẽ làm CẢ LÔ
        // 422 — không phải bỏ một dòng. Bắt ở đây, lúc khai, thay vì lúc một
        // quán đang chờ log.
        expect(mb_strlen((string) $message))->toBeLessThanOrEqual(255);
    }
});
