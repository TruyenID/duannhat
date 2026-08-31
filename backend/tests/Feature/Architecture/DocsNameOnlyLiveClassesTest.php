<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * Tài liệu không được nêu tên một class không tồn tại — trừ khi nói rõ nó đã chết.
 *
 * ## Vì sao cần rào, khi đây "chỉ là tài liệu"
 *
 * Vì tài liệu là thứ agent đọc để viết code. #2049 đo được 41 cái tên như vậy,
 * và cái tệ nhất nằm trong `backend/docs/reference/api-product.md` — một tài
 * liệu REFERENCE, tức hợp đồng API — viết *"`MaterialGraphService` blocks
 * circular references"*. Class đó **chưa bao giờ tồn tại**: `git log --all -S`
 * không trả về commit nào. Hành vi 422 thì có thật, chủ sở hữu là
 * `App\Exceptions\CircularReferenceException`. Người đọc đi tìm một class ma,
 * không thấy, rồi kết luận sai rằng tính năng chưa được cài.
 *
 * Chiều ngược lại cũng có: `ConsumptionTaxCalculator` bị plan-043 gọi là
 * *"single source of truth"* cho hằng số thuế — trong khi nó bị xoá **chính vì
 * lý do ngược lại** (hardcode 10/8/0 cạnh code không được phép hỏi nó).
 *
 * ## Cửa thoát chính là mục đích của rào
 *
 * Rào KHÔNG bắt xoá mọi cái tên đã chết. Nhắc một class đã gỡ kèm chữ "ĐÃ GỠ ở
 * #NNNN" là tài liệu TỐT — xoá sạch không dấu vết thì người sau lại đi tìm.
 * `docs/guide/tax-types.md` là mẫu chuẩn của repo.
 *
 * Nên tác giả có đúng HAI cách làm rào xanh: sửa tên, hoặc ghi chú theo mẫu.
 * Không có cách thứ ba.
 *
 * ## Hai ngân sách, vì hai bề mặt có luật khác nhau
 *
 * `docs/` + `backend/docs/` mô tả HỆ THỐNG ĐANG CHẠY ⇒ phải về 0.
 * `plans/` là hồ sơ thiết kế, được phép nói về việc chưa làm ⇒ hạ dần.
 */
const DOC_PHANTOM_REFERENCE_BUDGET = 0;

const DOC_PHANTOM_PLANS_BUDGET = 0;

/**
 * Dấu hiệu "tôi biết cái này đã chết". Có một trong số này trên cùng dòng, hoặc
 * trong blockquote bao quanh, thì tên được miễn.
 *
 * @var list<string>
 */
const DOC_PHANTOM_WAIVERS = [
    'ĐÃ GỠ', 'ĐÃ XOÁ', 'ĐÃ BỊ GỠ', 'KHÔNG TỒN TẠI', 'CHƯA BAO GIỜ',
    'không tồn tại', 'chưa bao giờ', 'Rejected', 'REJECTED', 'zero hit',
    'BÁC BỎ', 'đã gỡ', 'đã xoá', 'bị gỡ', 'bị xoá', 'GỠ BỎ',
    'HỒ SƠ LỊCH SỬ', 'không còn', 'removed', 'REMOVED', 'deleted',
];

/**
 * Dương tính giả đã đo — tên khớp khuôn nhưng không phải class của repo.
 *
 * Giữ danh sách này NGẮN và mỗi mục phải nêu lý do. Nó là chỗ rào tự thú rằng
 * khuôn nhận dạng chưa hoàn hảo; dài ra là dấu hiệu khuôn cần sửa, không phải
 * danh sách cần nối thêm.
 *
 * Danh sách CHỈ ĐƯỢC CO LẠI — bài bánh cóc ở cuối file cưỡng chế. `TenderRepository`
 * (tên entity chuẩn ARTS ODM 7.3) đã rời khỏi đây 2026-08-18: chỗ nêu tên duy
 * nhất của nó chết cùng `plans/plan-030/` ở #2188, đo lại được 0 lần xuất hiện
 * trong `docs/` · `backend/docs/` · `plans/`. Miễn trừ còn lại ở đó không vô
 * hại — nó cho phép TRƯỚC bất cứ tài liệu nào sau này nêu đúng cái tên ấy như
 * thể nó đang chạy.
 *
 * @var array<string, string>
 */
const DOC_PHANTOM_FALSE_POSITIVES = [
    // Field JSON của giao thức VescaJS (máy P400), không phải class.
    'CurrentService' => 'VescaJS protocol field',
];

/**
 * Nguồn định danh BẮT BUỘC phải có mặt thì phán quyết mới có nghĩa.
 *
 * Đo được, không phỏng đoán (#2119): tài liệu hôm nay nêu tên Go của workstation
 * (`CloudVerifier`, `CashChangerService`) nhưng KHÔNG nêu tên nào chỉ tồn tại ở
 * `app/tms` hay `app/kiosk`. Cây có nguồn Go của workstation cho kết quả **3
 * passed** — y hệt cây có đủ cả ba. Nên chỉ root này là bắt buộc.
 *
 * #2333 — thư mục đổi chỗ HAI lần trong một ngày: tên cũ `workstation-app` →
 * (#2312) `app/workstation` → (#2327) `workstation`. Hằng số này không đi theo
 * lần nào, nên cổng ném lỗi trên MỌI PR và `dev` đỏ ba lượt liền. Đổi chỗ một
 * thư mục thì phải dọn chỗ trỏ tới nó (#2215).
 *
 * @var list<string>
 */
const DOCS_REQUIRED_SOURCE_ROOTS = ['workstation/internal'];

/**
 * Quét thêm nếu có, nhưng vắng thì không làm phán quyết mất giá trị.
 *
 * @var list<string>
 */
const DOCS_OPTIONAL_SOURCE_ROOTS = ['app/tms/src', 'app/kiosk/src'];

/**
 * Root bắt buộc đang vắng mặt.
 *
 * @return list<string>
 */
function docsMissingRequiredSources(): array
{
    $missing = [];

    foreach (DOCS_REQUIRED_SOURCE_ROOTS as $rel) {
        if (! is_dir(base_path('../'.$rel))) {
            $missing[] = $rel;
        }
    }

    return $missing;
}

/**
 * Vắng nguồn bắt buộc thì bài này KHÔNG QUYẾT ĐỊNH ĐƯỢC — và phải nói ra.
 *
 * ## Vì sao cần cửa này (#2119)
 *
 * Bộ quét từng `continue` qua mọi submodule chưa init, im lặng. Hệ quả không
 * phải "quét thiếu" mà là **buộc tội sai**: tập tên-đã-biết co lại, nên những
 * tên CÓ THẬT trong Go bị báo là tên ma, với hạn mức 0 nên test đỏ. Cùng một
 * commit, cùng một file tài liệu:
 *
 * | cây | kết quả |
 * |---|---|
 * | có nguồn Go của workstation | **3 passed** |
 * | không có (submodule chưa init) | **2 failed** — tố `CloudVerifier` ×4 + `CashChangerService`, cả hai đều tồn tại |
 *
 * Một phán quyết đổi theo trạng thái checkout thì không phải phán quyết. Và nó
 * đắt gấp đôi kiểu "âm thầm xanh" ở #2089: nó gửi người đi sửa tài liệu đúng.
 *
 * ## Vì sao chỉ còn MỘT lối ra (#2333)
 *
 * Bảng trên mô tả thời `workstation-app` là submodule: vắng ở local là chuyện
 * thường (worktree `tal claim` cố ý không init), vắng trên CI nghĩa là deploy
 * key hỏng. Hai hoàn cảnh khác nhau nên cần hai lối ra, và `markTestSkipped`
 * cho lối local.
 *
 * Monorepo (#2306) xoá hoàn cảnh thứ nhất: nguồn Go **nằm trong cây**, mọi
 * checkout đều có, `.gitmodules` không còn. Vắng nó giờ chỉ có thể là cây hỏng
 * hoặc — đúng cái vừa xảy ra — hằng số trỏ sai chỗ. Cả hai đều phải ĐỎ, ở mọi
 * môi trường: một nhánh `markTestSkipped` còn sót lại sẽ biến "trỏ sai đường
 * dẫn" thành "skipped" ở local, và người sửa sẽ không thấy gì cho tới khi CI
 * đỏ. Đó chính là cách bug này sống được.
 */
function requireDocsIdentifierSources(): void
{
    $missing = docsMissingRequiredSources();
    if ($missing === []) {
        return;
    }

    throw new RuntimeException(implode("\n", [
        'Nguồn định danh vắng mặt — cổng docs đã thôi canh.',
        '',
        'Thiếu nguồn định danh BẮT BUỘC: '.implode(', ', $missing),
        '',
        'Không có nó thì tên Go của workstation không phân giải được, và bài này',
        'sẽ tố những tên CÓ THẬT là tên ma. Nên nó không quyết định, thay vì',
        'quyết định sai.',
        '',
        'Nguồn Go nằm IN-TREE từ #2306 — không có submodule nào để init. Vắng nghĩa là',
        'cây hỏng, hoặc DOCS_REQUIRED_SOURCE_ROOTS đang trỏ sai chỗ (thư mục đã',
        'đổi tên hai lần ở #2312 và #2327).',
    ]));
}

/**
 * Mọi định danh mà repo THẬT SỰ có — rộng hơn `class X` trong `backend/app`.
 *
 * Phân giải hẹp là cách sinh ra nợ giả: lượt quét đầu của #2049 báo 46 tên, và
 * 5 trong đó tồn tại thật — hai class Go, một trait, một field giao thức, một
 * tên entity chuẩn ngoài. Báo nhầm 11% thì rào mất uy tín ngay lượt đầu.
 *
 * @return array<string, true>
 */
function docsKnownIdentifiers(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $known = [];
    $add = function (string $source, string $pattern) use (&$known): void {
        if (preg_match_all($pattern, $source, $m)) {
            foreach ($m[1] as $name) {
                $known[$name] = true;
            }
        }
    };

    // PHP: class · interface · trait · enum — trong app VÀ packages.
    foreach ([base_path('app'), base_path('packages'), base_path('routes')] as $dir) {
        if (! is_dir($dir)) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            $add($source, '/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/m');
            // Alias import: `use …\NotificationPreferenceController as MeNotificationPreferenceController;`
            $add($source, '/^use\s+[^;]+\s+as\s+([A-Za-z_][A-Za-z0-9_]*)\s*;/m');
        }
    }

    // Go (workstation) + TS/RN (kiosk, tms).
    foreach ([...DOCS_REQUIRED_SOURCE_ROOTS, ...DOCS_OPTIONAL_SOURCE_ROOTS] as $rel) {
        $dir = base_path('../'.$rel);
        if (! is_dir($dir)) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['go', 'ts', 'tsx'], true)) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            $add($source, '/^type\s+([A-Za-z_][A-Za-z0-9_]*)\s+struct/m');
            $add($source, '/^func\s+New([A-Za-z_][A-Za-z0-9_]*)\s*\(/m');
            $add($source, '/^export\s+(?:default\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/m');
        }
    }

    return $cache = $known;
}

/**
 * Khuôn nhận dạng "tên class được nêu trong tài liệu" — MỘT nguồn, dùng cho cả
 * bộ dò lẫn bánh cóc. Tách ra vì hai chỗ chép hai khuôn giao nhau đúng một giá
 * trị là cách #2860 sinh ra.
 */
const DOC_CLASS_NAME_PATTERN = '/`([A-Z][A-Za-z]{4,}(?:Service|Controller|Repository|Builder|Resolver|Verifier|Calculator|Aggregator|Writer|Engine))`/';

/**
 * Mọi tên ỨNG VIÊN mà khuôn bắt được trong một cây tài liệu — TRƯỚC mọi miễn
 * trừ. Đây là mẫu số của bánh cóc: một mục trong `DOC_PHANTOM_FALSE_POSITIVES`
 * chỉ có nghĩa khi tên đó thật sự bị khuôn bắt ở đâu đó.
 *
 * @return array<string, true>
 */
function docsCandidateClassNames(string $root): array
{
    $dir = base_path('../'.$root);
    if (! is_dir($dir)) {
        $dir = base_path($root);
    }
    if (! is_dir($dir)) {
        return [];
    }

    $names = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'md') {
            continue;
        }
        if (preg_match_all(DOC_CLASS_NAME_PATTERN, (string) file_get_contents($file->getPathname()), $m)) {
            foreach ($m[1] as $name) {
                $names[$name] = true;
            }
        }
    }

    return $names;
}

/**
 * @return list<array{file: string, line: int, name: string}>
 */
function docsPhantomNames(string $root): array
{
    $dir = base_path('../'.$root);
    if (! is_dir($dir)) {
        $dir = base_path($root);
    }
    if (! is_dir($dir)) {
        return [];
    }

    $known = docsKnownIdentifiers();
    $out = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'md') {
            continue;
        }
        $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            continue;
        }

        // Banner CẤP FILE: một tuyên bố ở đầu file ("HỒ SƠ LỊCH SỬ — hạ tầng
        // mô tả ở đây đã bị gỡ") phủ CẢ FILE, vì đó là cách người ta đọc nó.
        // Miễn trừ theo mục không đủ: banner nằm ở mục đầu, còn tên rải rác
        // khắp các mục sau — và bắt tác giả lặp lại cùng một câu ở mỗi mục là
        // biến rào thành thứ gây nhiễu chứ không phải thứ bảo vệ.
        $head = implode(' ', array_map(
            static fn (string $l): string => (string) preg_replace('/^\s*>+\s?/', '', $l),
            array_slice($lines, 0, 40),
        ));
        $head = (string) preg_replace('/\s+/u', ' ', $head);
        foreach (['HỒ SƠ LỊCH SỬ', 'SUPERSEDED', 'ĐÃ GỠ', 'ĐÃ XOÁ', 'ĐÃ BỊ GỠ'] as $fileWaiver) {
            if (str_contains($head, $fileWaiver)) {
                continue 2;
            }
        }

        foreach ($lines as $i => $line) {
            if (! preg_match_all(DOC_CLASS_NAME_PATTERN, $line, $m)) {
                continue;
            }

            // Ngữ cảnh miễn trừ = TỪ TIÊU ĐỀ MỤC gần nhất tới dòng hiện tại.
            //
            // Cửa sổ vài dòng thì quá hẹp và đã đo được: `tax-types.md` có mục
            // tên "適格返還請求書 — ĐÃ GỠ ở #1779" rồi liệt tên class chục dòng
            // sau. Một tiêu đề mục nói "cái này đã chết" phủ cả mục — đó là
            // cách tài liệu thật sự được đọc.
            $sectionStart = 0;
            for ($j = $i; $j >= 0; $j--) {
                if (preg_match('/^#{1,6}\s/', $lines[$j])) {
                    $sectionStart = $j;
                    break;
                }
            }
            // …tới HẾT mục, không dừng ở dòng hiện tại: ghi chú "cái này đã
            // chết" thường nằm NGAY SAU câu nêu tên ("Dòng trên từng quy hành
            // vi cho X — class đó chưa bao giờ tồn tại"). Cắt ngữ cảnh ở dòng
            // hiện tại thì đúng cách viết tự nhiên nhất lại bị coi là vi phạm.
            $sectionEnd = count($lines) - 1;
            for ($j = $i + 1; $j < count($lines); $j++) {
                if (preg_match('/^#{1,6}\s/', $lines[$j])) {
                    $sectionEnd = $j - 1;
                    break;
                }
            }
            // Bỏ tiền tố blockquote của TỪNG DÒNG rồi mới nối, và gộp khoảng
            // trắng. Markdown ngắt dòng ở đâu là chuyện trình bày, nên
            // "chưa bao\n> giờ tồn tại" phải khớp y như khi nằm một dòng.
            // Đã đo: bỏ qua bước này thì đúng một dấu `>` chen vào giữa cụm từ
            // và cả ghi chú hợp lệ bị coi là vi phạm.
            $context = implode(' ', array_map(
                static fn (string $l): string => (string) preg_replace('/^\s*>+\s?/', '', $l),
                array_slice($lines, $sectionStart, $sectionEnd - $sectionStart + 1),
            ));
            $context = (string) preg_replace('/\s+/u', ' ', $context);

            foreach ($m[1] as $name) {
                if (isset($known[$name]) || isset(DOC_PHANTOM_FALSE_POSITIVES[$name])) {
                    continue;
                }
                if (str_contains($line, '~~'.$name.'~~') || str_contains($line, '~~`'.$name.'`~~')) {
                    continue;
                }
                // Mục việc CHƯA TÍCH (`- [ ]`) là kế hoạch, không phải khẳng
                // định — tên trong đó hiển nhiên chưa tồn tại và đó chính là
                // điều nó đang nói. Mục ĐÃ TÍCH (`- [x]`) thì KHÔNG được miễn:
                // một task báo xong mà gọi tên class không có nghĩa là báo cáo
                // trạng thái sai (plan-045 T7.1 đúng khuôn này).
                if (preg_match('/^\s*[-*]\s*\[ \]/', $line)) {
                    continue;
                }
                foreach (DOC_PHANTOM_WAIVERS as $waiver) {
                    if (str_contains($context, $waiver)) {
                        continue 2;
                    }
                }
                $out[] = [
                    'file' => str_replace(base_path('../'), '', $file->getPathname()),
                    'line' => $i + 1,
                    'name' => $name,
                ];
            }
        }
    }

    return $out;
}

it('docs/ và backend/docs/ KHÔNG nêu tên class nào không tồn tại', function () {
    requireDocsIdentifierSources();

    $found = array_merge(docsPhantomNames('docs'), docsPhantomNames('backend/docs'));

    expect(count($found))->toBeLessThanOrEqual(DOC_PHANTOM_REFERENCE_BUDGET, implode("\n", array_merge(
        ['Tài liệu mô tả HỆ THỐNG ĐANG CHẠY nêu tên class không tồn tại.', ''],
        array_map(static fn ($f) => "  {$f['file']}:{$f['line']} — {$f['name']}", $found),
        [
            '',
            'Hai cách sửa, không có cách thứ ba:',
            '  1. SỬA TÊN — nếu hành vi có thật nhưng chủ sở hữu là class khác.',
            '  2. GHI CHÚ — nếu class từng tồn tại và bị gỡ có chủ đích, thêm',
            '     "ĐÃ GỠ ở #NNNN" trên cùng dòng hoặc trong blockquote ngay trên.',
            '     Mẫu chuẩn: docs/guide/tax-types.md',
            '',
            'ĐỪNG chỉ xoá tên: người sau sẽ đi tìm lại nó.',
        ],
    )));
});

it('plans/ — bánh cóc, danh sách CHỈ ĐƯỢC CO LẠI', function () {
    requireDocsIdentifierSources();

    $found = docsPhantomNames('plans');

    expect(count($found))->toBeLessThanOrEqual(DOC_PHANTOM_PLANS_BUDGET, sprintf(
        "plans/ nêu %d tên class không tồn tại (hạn mức %d).\n\n%s\n\n%s",
        count($found),
        DOC_PHANTOM_PLANS_BUDGET,
        implode("\n", array_map(static fn ($f) => "  {$f['file']}:{$f['line']} — {$f['name']}", $found)),
        implode("\n", [
            'Plan ĐƯỢC PHÉP nói về việc chưa làm — chỉ cần nói rõ là chưa:',
            '',
            '  • Mục việc chưa tích `- [ ] … `ServiceMới`` — miễn tự động.',
            '  • Bảng/đoạn mô tả trạng thái ĐÍCH — thêm một câu có',
            '    "KHÔNG TỒN TẠI" hoặc "CHƯA BAO GIỜ" trong cùng mục.',
            '  • Cả file là hồ sơ lịch sử — banner "HỒ SƠ LỊCH SỬ" / "ĐÃ GỠ"',
            '    trong 40 dòng đầu phủ toàn file.',
            '  • Class đã bị xoá — `~~Tên~~` kèm "ĐÃ XOÁ ở #NNNN".',
            '',
            'Điều KHÔNG được phép là nêu tên ở thì hiện tại như thể nó đang',
            'chạy. Hạn mức TUYỆT ĐỐI không nâng.',
        ]),
    ));
});

it('bộ quét CÒN CHẠY — phân giải được tên thật, miễn đúng ghi chú, bắt đúng tên ma', function () {
    requireDocsIdentifierSources();

    $known = docsKnownIdentifiers();

    // 1. Phân giải được PHP thật. Không có mốc này thì một regex hỏng cho 0
    //    phát hiện và đọc y hệt "đã sạch".
    expect($known)->toHaveKey('TaxResolver');
    expect($known)->toHaveKey('OrderPricingCalculator');

    // 2. Phân giải RỘNG hơn `class X` — trait và alias route, hai lớp nhiễu đã
    //    làm lượt quét đầu của #2049 báo nhầm 5/46 tên.
    expect($known)->toHaveKey('ShopBoundController');   // trait, không phải class

    // 2b. Phân giải được GO của workstation. Mốc này thiếu chính là #2119: bộ
    //     quét im lặng bỏ qua submodule chưa init, nên nhánh Go chết mà bài tự
    //     kiểm vẫn xanh — và cái đỏ rơi xuống `plans/`, tố nhầm những tên có
    //     thật. Ghim ở ĐÂY thì lỗi nổ tại bộ quét, đúng chỗ nó nằm.
    expect($known)->toHaveKey('CloudVerifier');          // type Go, workstation/
    expect($known)->toHaveKey('CashChangerService');     // type Go, Glory 釣銭機

    // 3. Miễn trừ hoạt động: `tax-types.md` nhắc PosInvoiceService kèm ĐÃ GỠ.
    $ref = array_merge(docsPhantomNames('docs'), docsPhantomNames('backend/docs'));
    expect(collect($ref)->pluck('name')->all())->not->toContain('PosInvoiceService');
});

/**
 * BÁNH CÓC — `DOC_PHANTOM_FALSE_POSITIVES` chỉ được CO LẠI.
 *
 * Danh sách này là chỗ rào tự thú "khuôn của tôi chưa hoàn hảo", nên mỗi mục
 * phải TRỎ VÀO một dương tính giả CÒN TỒN TẠI. Khi tài liệu nêu tên đó biến mất
 * (`plans/plan-030/` bị xoá ở #2188 mang theo chỗ duy nhất nói `TenderRepository`),
 * mục ở lại không còn là lời thú nhận nữa — nó thành giấy phép: tài liệu sau này
 * viết đúng cái tên ấy ở thì hiện tại sẽ đi thẳng qua ngân sách 0.
 *
 * Hai chiều, vì có hai cách một mục hết ứng:
 *   - tên không còn bị khuôn bắt ở đâu (tài liệu đã xoá)
 *   - tên nay là định danh CÓ THẬT (`$known` đã tự cho qua trước cả miễn trừ)
 */
it('bánh cóc — dương tính giả hết ứng phải bị xoá, danh sách chỉ co lại', function () {
    requireDocsIdentifierSources();

    $candidates = array_merge(
        docsCandidateClassNames('docs'),
        docsCandidateClassNames('backend/docs'),
        docsCandidateClassNames('plans'),
    );

    // Khuôn hỏng ⇒ 0 ứng viên ⇒ bánh cóc tố oan mọi mục. Ghim mẫu số trước.
    expect(count($candidates))->toBeGreaterThan(20, 'khuôn nhận dạng bắt được gần như không gì — bộ quét hỏng, không phải danh sách');

    $names = array_keys(DOC_PHANTOM_FALSE_POSITIVES);
    sort($names);

    $known = docsKnownIdentifiers();

    foreach ($names as $name) {
        expect(trim(DOC_PHANTOM_FALSE_POSITIVES[$name]))->not->toBe(
            '',
            "miễn trừ `{$name}` không nêu lý do — mỗi mục PHẢI nêu lý do",
        );

        expect(isset($candidates[$name]))->toBeTrue(implode("\n", [
            "Dương tính giả `{$name}` HẾT ỨNG: không tài liệu nào trong docs/ ·",
            'backend/docs/ · plans/ còn nêu tên đó trong backtick. Xoá mục khỏi',
            'DOC_PHANTOM_FALSE_POSITIVES.',
            '',
            'Mục chết ở đây KHÔNG vô hại: nó cho phép TRƯỚC, nên tài liệu tương lai',
            'nêu đúng cái tên ấy ở thì hiện tại sẽ đi lọt qua ngân sách 0.',
            '',
            'Danh sách này chỉ ĐI XUỐNG.',
        ]));

        expect(isset($known[$name]))->toBeFalse(implode("\n", [
            "Dương tính giả `{$name}` HẾT ỨNG theo chiều ngược lại: repo NAY CÓ định danh",
            'mang tên đó, nên bộ dò đã tự cho qua ở nhánh $known. Mục miễn trừ chỉ còn',
            'che mất ngày cái tên đó bị xoá đi lần nữa.',
        ]));
    }
});
