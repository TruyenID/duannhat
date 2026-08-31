<?php

declare(strict_types=1);

/**
 * The workstation reads Cloud's pull-DOWN payloads by JSON key. When Cloud stops
 * emitting one, nothing fails: Go unmarshals the missing key to its zero value
 * and the device carries on with an empty string, a zero, a false.
 *
 * WHAT THIS CATCHES: a key that disappears from the backend entirely — a rename,
 * a dropped concept, a field the workstation still reads after Cloud forgot it.
 * That was the class `rate_takeaway` was in: Cloud stopped emitting it at #1099
 * and the workstation kept a compat shim reading it. The shim ĐÃ GỠ at #1128 —
 * `sync_pull.go:1953,1971` carry the removal record and no `json:"rate_takeaway"`
 * tag survives, so the exemption that covered it is gone too (see the ratchet
 * below, which is what forces that second half to happen).
 *
 * WHAT IT DOES NOT CATCH, stated so nobody reads more into a green run: a field
 * removed from ONE endpoint while surviving elsewhere in the codebase. #1207
 * (missing `timezone` showed a Tokyo shop as closed) and #1176 are that shape,
 * and this test would have stayed green through both — `timezone` appears 82
 * other times in app/.
 *
 * The strict version was measured and rejected rather than assumed: restricting
 * the haystack to the workstation- and TMS-facing controllers plus resources
 * leaves 54 of 270 keys unfound, because payloads are shaped in services and
 * shared builders too. An allowlist of 54 would rot faster than it guards.
 *
 * So this is deliberately weak, and weak-and-running is the point. It needs the
 * workstation Go source, which is IN-TREE since #2306 — the skip below only
 * fires on a broken checkout.
 */

/**
 * Khoá ĐƯỢC MIỄN — workstation đọc nó, backend không phát, và đó là CỐ Ý.
 *
 * Danh sách này CHỈ ĐƯỢC CO LẠI; bài `bánh cóc` ở cuối file cưỡng chế điều đó.
 * Một miễn trừ đã hết ứng không vô hại: nó ngồi sẵn ở đúng cái khoá đó và cho
 * phép TRƯỚC lần biến mất kế tiếp mang cùng tên — rào vẫn xanh, thiết bị vẫn
 * đọc số 0.
 *
 * `rate_takeaway` từng là mục duy nhất ở đây (#1128 shim cho Cloud pre-#1099).
 * Shim ĐÃ GỠ; đo trên `dev` 2026-08-18: `json:"rate_takeaway"` xuất hiện 0 lần
 * trong `workstation/internal/service/`, chỉ còn hai comment removal-record.
 *
 * @var array<string, string> khoá => lý do
 */
const WORKSTATION_PULL_KEY_EXEMPTIONS = [];

/**
 * @return list<string> mọi tên khoá JSON mà tầng pull của workstation ĐỌC
 */
function workstationPullJsonTags(): array
{
    $pullFiles = [
        base_path('../workstation/internal/service/sync_pull.go'),
        base_path('../workstation/internal/service/sync_pull_pos.go'),
    ];

    $tags = [];
    foreach (array_filter($pullFiles, 'file_exists') as $file) {
        preg_match_all('/`json:"([a-z_][a-z0-9_]*)[",]/', (string) file_get_contents($file), $matches);
        $tags = array_merge($tags, $matches[1]);
    }

    return array_values(array_unique($tags));
}

/** Toàn bộ mã PHP của `backend/app` nối lại, để tra sự có mặt của một chuỗi. */
function backendAppSourceBlob(): string
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $backend = '';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $backend .= file_get_contents($file->getPathname());
        }
    }

    return $cache = $backend;
}

/** Backend có nhắc khoá này ở đâu đó trong `app/` không? */
function backendMentionsPullKey(string $tag): bool
{
    $backend = backendAppSourceBlob();

    return str_contains($backend, "'{$tag}'")
        || str_contains($backend, "\"{$tag}\"")
        || str_contains($backend, "->{$tag}");
}

it('emits every field the workstation pull layer reads', function () {
    $tags = workstationPullJsonTags();

    if ($tags === []) {
        $this->markTestSkipped('nguồn workstation vắng mặt trong cây (in-tree từ #2306)');
    }

    // A parse that found almost nothing would pass while checking nothing — the
    // failure mode this whole file exists to prevent, one level up.
    expect(count($tags))->toBeGreaterThan(150, 'almost no json tags parsed — the scan is broken, not the payloads');

    $exempt = WORKSTATION_PULL_KEY_EXEMPTIONS;

    $missing = [];
    foreach ($tags as $tag) {
        if (isset($exempt[$tag])) {
            continue;
        }
        if (backendMentionsPullKey($tag)) {
            continue;
        }
        $missing[] = $tag;
    }

    expect($missing)->toBe([], implode("\n  ", [
        'The workstation reads these JSON keys and the backend mentions none of them.',
        'Go unmarshals a missing key to its zero value, so this fails silently on the device —',
        'see #1207 (missing timezone showed a Tokyo shop as closed) and #1176:',
        ...$missing,
    ]));
});

/**
 * BÁNH CÓC — danh sách miễn trừ CHỈ ĐƯỢC CO LẠI.
 *
 * Không có bài này thì nửa sau của mỗi lần gỡ shim không bao giờ xảy ra: người
 * ta gỡ shim ở Go, rào vẫn xanh (miễn trừ chỉ là một `continue` không ai chạm),
 * và mục chết ở lại — ngồi đúng chỗ để nuốt lần biến mất kế tiếp cùng tên. Đây
 * là hình dạng của `TestForwardCompatExceptionListOnlyShrinks` bên workstation.
 */
it('bánh cóc — miễn trừ hết ứng phải bị xoá, danh sách chỉ co lại', function () {
    $tags = workstationPullJsonTags();

    if ($tags === []) {
        $this->markTestSkipped('nguồn workstation vắng mặt trong cây (in-tree từ #2306)');
    }

    // Bánh cóc dựa trên `$tags`, nên một bộ quét hỏng sẽ làm nó tố oan mọi mục
    // — hoặc, khi danh sách rỗng, xanh mà không đo gì. Ghim lại ở đây.
    expect(count($tags))->toBeGreaterThan(150, 'almost no json tags parsed — the scan is broken, not the exemptions');

    $names = array_keys(WORKSTATION_PULL_KEY_EXEMPTIONS);
    sort($names);

    foreach ($names as $key) {
        expect(trim(WORKSTATION_PULL_KEY_EXEMPTIONS[$key]))->not->toBe(
            '',
            "miễn trừ `{$key}` không nêu lý do — một ngoại lệ không ai kiểm được thì không phải ngoại lệ",
        );

        // `toContain()` nhận NHIỀU GIÁ TRỊ chứ không nhận thông điệp — truyền
        // chuỗi giải thích vào đó biến nó thành một giá trị phải tìm thấy, và
        // rào đỏ vĩnh viễn. Dùng `toBeTrue()` để chỗ thông điệp là thông điệp.
        expect(in_array($key, $tags, true))->toBeTrue(implode("\n", [
            "Miễn trừ `{$key}` HẾT ỨNG: tầng pull của workstation không còn đọc khoá này",
            '(không `json:"'.$key.'"` nào trong workstation/internal/service/). Xoá mục đó.',
            '',
            'Một miễn trừ chết KHÔNG vô hại: nó cho phép TRƯỚC lần kế tiếp một khoá',
            'cùng tên biến mất khỏi backend — rào xanh, thiết bị đọc zero value.',
            '',
            'Danh sách này chỉ ĐI XUỐNG.',
        ]));

        expect(backendMentionsPullKey($key))->toBeFalse(implode("\n", [
            "Miễn trừ `{$key}` HẾT ỨNG theo chiều ngược lại: backend/app CÓ nhắc khoá này,",
            'nên bài chính đã tự cho nó qua. Mục miễn trừ giờ chỉ che mất việc khoá bị gỡ lần sau.',
        ]));
    }
});
