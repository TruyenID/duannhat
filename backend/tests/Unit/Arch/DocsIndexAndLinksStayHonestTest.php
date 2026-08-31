<?php

/**
 * #1322 — the documentation index and cross-links must not rot silently.
 *
 * The umbrella `docs/contributing/documentation.md` has always required that
 * `docs/README.md` "list every document". Nothing enforced it, and by the time
 * anyone looked, 41 of 72 files had fallen out of the index — including the
 * newest and most-used ones (tax-types, cashier-shift-recovery,
 * offline-order-evidence, local-config). An index that omits 57% of the tree is
 * worse than no index: it reads as complete, so the reader stops there instead
 * of grepping.
 *
 * The same absence let seven relative links rot. All of them pointed at files
 * that exist only under `backend/docs/` — the signature of a doc copied out of
 * its home directory, keeping links that resolved where it used to live.
 *
 * Two invariants, both cheap enough to run on every suite:
 *
 *   1. every `docs/**\/*.md` is reachable from `docs/README.md`
 *   2. every relative `.md` link in `docs/` and `backend/docs/` resolves
 *
 * Deliberately NOT asserted here: frontmatter presence (#1323 owns that, and it
 * is mid-flight), and language (#1325 is an open product decision — asserting
 * either answer now would prejudge it).
 */

/**
 * Paths come from __DIR__, not base_path(): this file asserts about text on
 * disk in a sibling repo directory, and booting an application to read a
 * markdown file would be theatre. `backend/tests/Unit/Arch` → repo root is four
 * levels up.
 */
function docsRepoRoot(): string
{
    return dirname(__DIR__, 4);
}

/**
 * Tiền tố mà một link trỏ vào thì KHÔNG kiểm được — vì cây không chắc có nó.
 *
 * Danh sách này từng chứa tám đường dẫn app ("submodule chưa checkout ⇒ link
 * vào đó là không xác minh được, chứ không phải hỏng"). Tiền đề ấy **chết ở
 * #2306**: monorepo, không còn `.gitmodules`, cả tám đều nằm trong cây ở MỌI
 * checkout (đo 2026-08-18: `web/admin` 1191 file, `workstation` 996,
 * `app/kiosk` 622 … đều `git ls-files` ra).
 *
 * Nó không nằm im: bảy mục là xác chết, còn `web/admin` đang CHE HAI lỗi thật —
 * `docs/explanation/dine-in-stripe-payment-logic.md` thiếu một tầng `../`, và
 * `docs/contributing/translatable-forms.md` neo vào một mục đã dời khỏi
 * `web/admin/AGENTS.md`. Cả hai đã sửa cùng lượt này.
 *
 * Cơ chế được GIỮ LẠI dù danh sách rỗng, và đó là chủ đích: bánh cóc ở cuối
 * file chỉ có gì để cưỡng chế khi có chỗ để khai. Bỏ hẳn cơ chế thì lần sau ai
 * đó dựng lại một `if (str_contains(...)) continue;` và không gì đỏ.
 *
 * @var list<string>
 */
const DOCS_LINK_UNVERIFIABLE_PREFIXES = [];

/**
 * Link trỏ vào một cây KHÔNG kiểm được ⇒ bỏ qua thay vì tố là hỏng.
 */
function docsLinkPointsIntoUnverifiableTree(string $link): bool
{
    foreach (DOCS_LINK_UNVERIFIABLE_PREFIXES as $prefix) {
        if (str_contains($link, $prefix.'/')) {
            return true;
        }
    }

    return false;
}

/**
 * Split on line endings ONLY.
 *
 * The obvious `preg_split('/\R/', …)` is wrong here and was wrong before #1479
 * without anyone noticing. Outside unicode mode `\R` also matches the single
 * byte 0x85 (NEL) — and 0x85 occurs *inside* ordinary multi-byte UTF-8
 * sequences. A heading like `### 3.5 Monitoring: 状態取得` was therefore split
 * into three "lines", the first of them not valid UTF-8, which made every
 * subsequent `/u` pattern return null and silently stop matching.
 *
 * The damage was invisible because it degrades to *finding fewer links*: the
 * gate stayed green while going blind on exactly the Japanese- and
 * Vietnamese-heavy files it most needed to read.
 */
function docsSplitLines(string $text): array
{
    return preg_split('/\r\n|\n|\r/', $text);
}

/**
 * Strip fenced blocks and inline code spans, leaving prose.
 *
 * Both must go, because documentation.md teaches link syntax by EXAMPLE —
 * `[Stock Management](../explanation/stock-management.md)` is an illustration of
 * the rule, not a link to a file, and flagging it was a false positive in the
 * audit that opened #1322.
 *
 * The inline pattern deliberately refuses to cross a newline. That same file
 * documents fenced blocks by writing ` ```json ` inline, which leaves an ODD
 * number of backticks on its line; a newline-crossing pattern pairs that stray
 * backtick with one on the NEXT line and stops stripping the real code span
 * there. Confining each span to its own line contains the damage to the line
 * that is already malformed.
 */
function docsProseOnly(string $markdown): string
{
    $lines = docsSplitLines($markdown);
    $prose = [];
    $insideFence = false;

    foreach ($lines as $line) {
        if (preg_match('/^\s*```/', $line)) {
            $insideFence = ! $insideFence;

            continue;
        }

        if ($insideFence) {
            continue;
        }

        $prose[] = preg_replace('/`[^`\n]*`/', '', $line);
    }

    return implode("\n", $prose);
}

/**
 * @return list<string> every markdown file under $dir, repo-root-relative
 */
function docsMarkdownFilesUnder(string $dir): array
{
    $root = docsRepoRoot();

    if (! is_dir($root.'/'.$dir)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir));

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'md') {
            $files[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }

    sort($files);

    return $files;
}

it('lists every docs/ file in the index', function () {
    $index = file_get_contents(docsRepoRoot().'/docs/README.md');

    $missing = [];

    foreach (docsMarkdownFilesUnder('docs') as $file) {
        $relative = substr($file, strlen('docs/'));

        if ($relative === 'README.md') {
            continue;
        }

        // The index links are relative to docs/, so the path appears verbatim
        // inside the parentheses of a markdown link.
        if (! str_contains($index, '('.$relative.')')) {
            $missing[] = $relative;
        }
    }

    expect($missing)->toBe([], sprintf(
        "These docs are not reachable from docs/README.md:\n  %s\n".
        'Add a one-line entry under the right business-domain heading.',
        implode("\n  ", $missing)
    ));
});

it('resolves every relative markdown link in docs/ and backend/docs/', function () {
    $root = docsRepoRoot();
    $broken = [];

    foreach ([...docsMarkdownFilesUnder('docs'), ...docsMarkdownFilesUnder('backend/docs')] as $file) {
        $body = docsProseOnly(file_get_contents($root.'/'.$file));

        preg_match_all('/\]\(([^)#\s]+\.md)(?:#[^)]*)?\)/', $body, $matches);

        foreach ($matches[1] as $link) {
            if (str_starts_with($link, 'http')) {
                continue;
            }

            if (docsLinkPointsIntoUnverifiableTree($link)) {
                continue;
            }

            $target = dirname($root.'/'.$file).'/'.$link;

            if (! file_exists($target)) {
                $broken[] = $file.' -> '.$link;
            }
        }
    }

    expect(array_values(array_unique($broken)))->toBe([], sprintf(
        "Broken relative links:\n  %s",
        implode("\n  ", array_unique($broken))
    ));
});

/**
 * #1479 — the two ways a link still rotted silently after #1322.
 *
 * The test above only looks at links ending in `.md`, and only at the part
 * before the `#`. Two classes slipped through:
 *
 *   1. Links to source files. Three of them pointed at `../backend/app/...`
 *      from `docs/explanation/`, which resolves to `docs/backend/` — a
 *      directory that has never existed. The depth was wrong by one level, and
 *      nothing was looking.
 *
 *   2. Anchors. A dead anchor is worse than a dead link: the browser opens the
 *      right file and simply sits at the top, so the reader concludes they
 *      searched badly rather than that the document is wrong.
 */

/**
 * GitHub's heading-to-anchor rule: unwrap inline code and links, drop emphasis
 * markers, lowercase, remove everything that is not a letter, digit, space,
 * underscore or hyphen, then turn spaces into hyphens.
 *
 * Two details are easy to get wrong, and getting them wrong makes the test
 * disagree with GitHub rather than with the documentation:
 *
 *   - Removed punctuation leaves its spaces behind, so
 *     `## Appendix C — Known pitfalls` anchors as `appendix-c--known-pitfalls`
 *     with two hyphens.
 *   - There is no trailing trim. A heading ending in an emoji — several here end
 *     in `⭐` — loses the emoji but keeps the space before it, which then becomes
 *     a trailing hyphen. Trimming produces an anchor GitHub never emits, and the
 *     test then reports correct links as broken.
 */
function docsHeadingSlug(string $heading): string
{
    $text = preg_replace('/`([^`]*)`/u', '$1', $heading);
    $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $text);
    $text = preg_replace('/[*~]/u', '', $text);
    $text = mb_strtolower(ltrim($text));
    $text = preg_replace('/[^\p{L}\p{N}\p{M} _-]/u', '', $text);

    return str_replace(' ', '-', $text);
}

/**
 * @return list<string> every anchor a reader can reach in $file
 */
function docsAnchorsIn(string $file): array
{
    $anchors = [];

    foreach (docsSplitLines(file_get_contents($file)) as $line) {
        if (preg_match('/^(#{1,6})\s+(.*)$/u', $line, $m)) {
            $anchors[] = docsHeadingSlug($m[2]);
        }

        // Hand-written targets, e.g. <a id="foo"></a>, are anchors too.
        if (preg_match_all('/<a\s[^>]*(?:id|name)="([^"]+)"/i', $line, $ids)) {
            foreach ($ids[1] as $id) {
                $anchors[] = mb_strtolower($id);
            }
        }
    }

    return array_values(array_unique($anchors));
}

it('resolves every relative link in docs/ and backend/docs/, whatever its extension', function () {
    $root = docsRepoRoot();
    $broken = [];

    foreach ([...docsMarkdownFilesUnder('docs'), ...docsMarkdownFilesUnder('backend/docs')] as $file) {
        $body = docsProseOnly(file_get_contents($root.'/'.$file));

        preg_match_all('/\]\(([^)#\s]+)(?:#[^)\s]*)?\)/', $body, $matches);

        foreach ($matches[1] as $link) {
            if (preg_match('#^(https?:|mailto:|//)#', $link)) {
                continue;
            }

            if (docsLinkPointsIntoUnverifiableTree($link)) {
                continue;
            }

            // `plans/` is generated churn that the doc set links into loosely;
            // it is not part of the contract this gate defends.
            if (str_contains($link, 'plans/')) {
                continue;
            }

            if (! file_exists(dirname($root.'/'.$file).'/'.$link)) {
                $broken[] = $file.' -> '.$link;
            }
        }
    }

    expect(array_values(array_unique($broken)))->toBe([], sprintf(
        "Relative links pointing at nothing:\n  %s\n".
        'A wrong depth (`../` vs `../../`) is the usual cause.',
        implode("\n  ", array_unique($broken))
    ));
});

it('resolves every anchor pointing into a repo markdown file', function () {
    $root = docsRepoRoot();
    $anchorsByFile = [];
    $broken = [];

    foreach ([...docsMarkdownFilesUnder('docs'), ...docsMarkdownFilesUnder('backend/docs')] as $file) {
        $body = docsProseOnly(file_get_contents($root.'/'.$file));

        preg_match_all('/\]\(([^)#\s]*)#([^)\s]+)\)/', $body, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $link, $anchor]) {
            if (preg_match('#^(https?:|mailto:|//)#', $link)) {
                continue;
            }

            if (docsLinkPointsIntoUnverifiableTree($link) || str_contains($link, 'plans/')) {
                continue;
            }

            // A line anchor into source (`#L167`) is git's, not markdown's.
            if ($link !== '' && ! str_ends_with($link, '.md')) {
                continue;
            }

            $target = $link === ''
                ? $root.'/'.$file
                : dirname($root.'/'.$file).'/'.$link;

            if (! file_exists($target)) {
                continue; // the previous test already owns this failure
            }

            $target = realpath($target);
            $anchorsByFile[$target] ??= docsAnchorsIn($target);

            if (! in_array(mb_strtolower($anchor), $anchorsByFile[$target], true)) {
                $broken[] = $file.' -> '.($link === '' ? '' : $link).'#'.$anchor;
            }
        }
    }

    expect(array_values(array_unique($broken)))->toBe([], sprintf(
        "Anchors that match no heading:\n  %s\n".
        'The browser opens the file and stays at the top, so this never looks like an error to a reader.',
        implode("\n  ", array_unique($broken))
    ));
});

/**
 * BÁNH CÓC — `DOCS_LINK_UNVERIFIABLE_PREFIXES` CHỈ ĐƯỢC CO LẠI.
 *
 * Miễn trừ ở đây không im lặng theo kiểu vô hại. Nó là một `continue`: link vào
 * tiền tố đó không được kiểm, mãi mãi, và không có gì nói ra điều đó. Bảy trong
 * tám mục cũ đã là xác chết từ #2306; mục thứ tám (`web/admin`) trong lúc đó
 * nuốt trọn HAI link hỏng thật. Không ai thấy, vì rào vẫn xanh.
 *
 * Nên phép đo phải chạy theo chiều ngược: mỗi mục phải TỰ CHỨNG MINH rằng cây
 * không kiểm được nó. Trong monorepo thì không mục nào chứng minh được — đó là
 * ý nghĩa của danh sách rỗng, chứ không phải "tình cờ chưa ai thêm gì".
 */
it('#2306 — bánh cóc: miễn trừ link chỉ được co lại, và cây in-tree thì không miễn được', function () {
    $root = docsRepoRoot();

    // Tiền đề của cả danh sách rỗng. Submodule quay lại thì mục này phải ĐỎ để
    // người sửa quyết định lại, thay vì để tiền đề chết âm thầm lần thứ hai.
    expect(file_exists($root.'/.gitmodules'))->toBeFalse(implode("\n", [
        '`.gitmodules` xuất hiện trở lại — repo có submodule lần nữa.',
        'Miễn trừ "link vào cây chưa checkout" có thể lại có nghĩa; hãy quyết định',
        'lại một cách tường minh thay vì để bánh cóc này chặn.',
    ]));

    foreach (DOCS_LINK_UNVERIFIABLE_PREFIXES as $prefix) {
        expect(is_dir($root.'/'.$prefix))->toBeFalse(implode("\n", [
            "Miễn trừ `{$prefix}` HẾT ỨNG: thư mục đó NẰM TRONG CÂY, nên link vào nó",
            'kiểm được bình thường. Xoá mục đó khỏi DOCS_LINK_UNVERIFIABLE_PREFIXES.',
            '',
            'Một miễn trừ chết ở đây không phải chuyện dọn dẹp: nó tắt tiếng mọi link',
            'hỏng TƯƠNG LAI trỏ vào cây đó. Đúng chuyện đã xảy ra với `web/admin` —',
            'hai link hỏng nằm dưới nó suốt từ #2306.',
            '',
            'Danh sách này chỉ ĐI XUỐNG.',
        ]));
    }

    // Vế còn lại của bánh cóc: chứng minh bộ quét THẬT SỰ đi vào các cây app.
    // Danh sách rỗng mà bộ quét vẫn mù vì lý do khác thì bánh cóc trên chỉ là
    // trang trí — rào phải biết kêu, và phải chứng minh được là nó đang nhìn.
    $intoApps = 0;
    foreach ([...docsMarkdownFilesUnder('docs'), ...docsMarkdownFilesUnder('backend/docs')] as $file) {
        $body = docsProseOnly(file_get_contents($root.'/'.$file));
        preg_match_all('/\]\(([^)#\s]+)(?:#[^)\s]*)?\)/', $body, $matches);

        foreach ($matches[1] as $link) {
            if (preg_match('#^(https?:|mailto:|//)#', $link) || str_contains($link, 'plans/')) {
                continue;
            }
            if (! preg_match('#(^|/)(web|app|workstation)/#', $link)) {
                continue;
            }
            if (docsLinkPointsIntoUnverifiableTree($link)) {
                continue;
            }
            $intoApps++;
        }
    }

    expect($intoApps)->toBeGreaterThan(5, implode("\n", [
        'Bộ quét không còn thấy link nào trỏ vào web/ · app/ · workstation/.',
        'Danh sách miễn trừ rỗng mà vẫn mù nghĩa là chỗ mù đã dời sang cơ chế khác.',
    ]));
});
