<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

/**
 * #2215 — một chỉ dẫn "chạy lệnh X" phải trỏ tới một lệnh CÓ THẬT.
 *
 * PR #2213 xoá 17 lệnh `Backfill*` theo ruling #2188 nhưng để lại các chỗ vẫn
 * bảo người vận hành chạy chúng. Chỗ tệ nhất là thông điệp khắc phục của một
 * BÁO ĐỘNG TIỀN: `TaxResolver` cảnh báo "line taxed at 0%" rồi bảo chạy
 * `tax-types:backfill` — lệnh đã bị xoá. Người đọc log đúng lúc thuế đang rò
 * gõ lệnh và nhận `Command "tax-types:backfill" is not defined.`
 *
 * Rào này quét các BỀ MẶT SỐNG (code + doc + script vận hành) tìm hai neo cụ
 * thể — `artisan <lệnh>` và `run <lệnh>` — rồi đối chiếu tên với danh sách
 * lệnh ĐANG ĐĂNG KÝ THẬT (`Artisan::all()`), không phải một blocklist chép tay.
 * Xoá một lệnh mà quên dọn chỗ gọi nó ⇒ test đỏ ngay, kèm tên file.
 *
 * ## Ranh giới, cố ý
 *
 * - **`plans/` KHÔNG quét.** Đó là hồ sơ thiết kế của từng đợt, ghi lại việc đã
 *   làm bằng công cụ của thời điểm đó — giống git history, không phải chỉ dẫn
 *   vận hành. Bắt nó đồng bộ với danh sách lệnh hôm nay là bắt lịch sử phải nói
 *   dối. (13 chỗ trong `plans/` nhắc lệnh đã xoá; tất cả đều ở thì quá khứ.)
 * - **`docs/decisions/` (ADR) thì CÓ quét** — đừng suy từ `plans/` sang, hai
 *   thứ ngược thì. `plans/` ở thì QUÁ KHỨ; ADR ở thì TƯƠNG LAI, tức thứ sắp
 *   được đem đi làm chứ không phải thứ đã làm xong.
 *
 *   Lý do giữ ADR trong phạm vi quét là **khối copy-paste**, KHÔNG phải "ops
 *   đọc ADR lúc có sự cố" (#3098 hỏi thẳng câu đó; đo ra là KHÔNG — xem đoạn
 *   sau). ADR ở đây không dừng ở lý lẽ: `0002` chở nguyên một dòng crontab
 *   `flock … /home/famgia/apps/tempo/artisan <lệnh> --max-time=55` để người
 *   triển khai dán vào prod. Một tên lệnh sai nằm trong khối đó hỏng đúng như
 *   hỏng trong runbook, bất kể ai là người dán.
 *
 *   Phép đo cho vế phủ định, để đừng ai phải đo lại: `git grep docs/decisions`
 *   ngoài chính thư mục ấy trả về **0 chỗ** ở `docs/guide`, thông điệp khắc
 *   phục hay script — không đường dẫn nào dẫn ops tới ADR lúc sự cố. Các chỗ
 *   nhắc `ADR 0001 §…` đều nằm trong comment mã và docstring test, tức bề mặt
 *   của LẬP TRÌNH VIÊN lúc viết mã. `docs/decisions/README.md` tự khai đúng
 *   ranh giới đó: ADR trả lời *"vì sao lại thế này?"*, còn *"cái này chạy ra
 *   sao?"* thuộc về `docs/guide/`.
 *
 *   Ranh giới này từng suýt bị xoá: ADR 0002 (#3095) làm rào đỏ vì mô tả một
 *   lệnh consumer chưa ra đời, và phương án đầu tiên được cân nhắc là miễn trừ
 *   cả thư mục. Cách đúng là bỏ neo `artisan` trong ADR (mention ≠ chỉ dẫn),
 *   không phải mở vùng mù. `covers()` + bài test `#3100` bên dưới khẳng định
 *   ranh giới này thay vì để nó là tình cờ.
 * - **Nhắc TÊN lệnh trong dấu backtick KHÔNG bị chặn** — `removal record` kiểu
 *   "`tax-types:backfill` đã bị xoá 2026-08-08" là THIẾT KẾ (#2188 nói rõ đừng
 *   xoá nhầm), và nó không có neo `artisan`/`run` nên không khớp.
 * - **Bỏ qua `npm|pnpm|yarn|composer run`** — `npm run omnify:gen` là script
 *   của package.json, không phải lệnh artisan.
 *
 * ## Điểm mù đã biết (nói ra thay vì giả vờ không có)
 *
 * Neo là hai từ khoá, nên một câu viết vòng ("dùng lệnh X để…") lọt lưới. Đổi
 * lại là gần như không có dương tính giả: đo trên `dev` 2026-08-08, hai neo bắt
 * đúng 42 tên trên bề mặt sống, trong đó 7 tên không tồn tại — tất cả đều là
 * lỗi thật. Một rào bắt được 100% mà kêu oan mỗi tuần sẽ bị vô hiệu hoá bằng
 * cách nới, nên chỗ này chọn neo hẹp và nói rõ nó bỏ sót cái gì.
 *
 * Hai BỀ MẶT vận hành cũng nằm ngoài rào, cố ý và nói ra (#2215 review vòng 1):
 * - `.github/workflows/` — `deploy-xserver.yml` gọi `service:sync-authz-manifest`
 *   trong `cd "$PLATFORM_DIR"`, tức lệnh của ỨNG DỤNG KHÁC; quét ngây thơ là
 *   dương tính giả vĩnh viễn. Thêm được khi bộ quét biết phân biệt cwd.
 * - `CLAUDE.md`/`AGENTS.md` ở gốc umbrella — nạp vào mọi phiên agent; hiện chỉ
 *   nhắc lệnh còn sống, nhưng scan() nhận thư mục nên chưa với tới file lẻ ở
 *   gốc. Ai đưa được chúng vào roots thì nâng luôn.
 */
final class ArtisanCommandReferenceScanner
{
    /** Phần mở rộng đáng quét — nơi người ta viết chỉ dẫn cho người khác. */
    private const EXTENSIONS = ['php', 'md', 'py', 'yaml', 'yml', 'mjs', 'js', 'sh'];

    /**
     * Đoạn đường dẫn bị BỎ QUA. Danh sách CHỈ ĐƯỢC CO LẠI — bài bánh cóc `#3190`
     * ở cuối file cưỡng chế: mỗi mục phải loại được ÍT NHẤT MỘT đường dẫn có
     * thật nằm dưới `roots()`.
     *
     * Bốn mục đã rời khỏi đây 2026-08-18, đo bằng `find` trên `roots()`:
     * `/.git/`, `/storage/`, `/bootstrap/cache/` và `/node_modules/` — 0 đường
     * dẫn khớp. Ba cái đầu là thư mục ANH EM của các root (`backend/storage`,
     * `backend/bootstrap/cache`, `.git` ở gốc umbrella), nên bộ quét chưa bao
     * giờ đi vào chúng; `node_modules` thì không root nào chứa `package.json`
     * để mà sinh ra nó.
     *
     * Vì sao không để cho lành: một `SKIP_SEGMENTS` chết là một VÙNG MÙ cấp
     * sẵn. Ngày ai đó thêm `backend/tests/storage/` (fixture) hay một
     * `package.json` dưới `scripts/`, cả cây đó im lặng rơi khỏi tầm quét —
     * và không gì đỏ để nói ra.
     *
     * `/vendor/` Ở LẠI vì nó đang loại một thứ có thật: `docs/reference/vendor/`
     * (tài liệu nhà cung cấp, hiện chỉ có PDF) nằm dưới root `docs`.
     */
    private const SKIP_SEGMENTS = [
        '/vendor/',
        // Chính file này: nó PHẢI chứa `php artisan tax-types:backfill` làm
        // fixture cho bài test bộ quét bên dưới. Quét chính mình thì rào tự tố
        // cáo mình mãi mãi.
        '/ArtisanCommandReferencesExistTest.php',
    ];

    /** @return list<string> đọc được từ bài bánh cóc */
    public static function skipSegments(): array
    {
        return self::SKIP_SEGMENTS;
    }

    /**
     * Các BỀ MẶT SỐNG được quét. Tách khỏi closure của test để phạm vi trở
     * thành thứ assert được — xem bài test `#3100`.
     *
     * @return list<string>
     */
    public static function roots(): array
    {
        $umbrella = dirname(base_path());

        return [
            base_path('app'),
            base_path('routes'),
            base_path('tests'),
            base_path('database'),
            base_path('.claude'),
            $umbrella.'/docs',
            $umbrella.'/scripts',
            $umbrella.'/schemas',
        ];
    }

    /**
     * Một file có nằm trong phạm vi quét không — gộp cả ba điều kiện: thuộc một
     * root, không dính `SKIP_SEGMENTS`, và có phần mở rộng đáng quét.
     *
     * Thuần đường dẫn, KHÔNG chạm đĩa: nhờ vậy bài test khẳng định ranh giới có
     * thể dùng đường dẫn tổng hợp thay vì ghim tên một file ADR có thật — rào
     * ghim chuỗi nguyên văn là rào chết lặng ngay khi file được dời hoặc đổi tên.
     */
    public static function covers(string $path): bool
    {
        $path = str_replace('\\', '/', $path);

        if (! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
            return false;
        }

        foreach (self::SKIP_SEGMENTS as $segment) {
            if (str_contains($path, $segment)) {
                return false;
            }
        }

        foreach (self::roots() as $root) {
            if (str_starts_with($path, str_replace('\\', '/', $root).'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, list<string>> tên lệnh => danh sách "file:dòng"
     */
    public static function scan(string $root): array
    {
        $found = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), self::EXTENSIONS, true)) {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            foreach (self::SKIP_SEGMENTS as $segment) {
                if (str_contains($path, $segment)) {
                    continue 2;
                }
            }

            $contents = (string) file_get_contents($path);

            foreach (self::commandsIn($contents) as $command) {
                $found[$command][] = $path.':'.self::lineOf($contents, $command);
            }
        }

        return $found;
    }

    /**
     * Tên lệnh đứng sau `artisan` hoặc `run`.
     *
     * Nội dung được LÀM PHẲNG khoảng trắng trước khi khớp: một chỉ dẫn xuống
     * dòng giữa `artisan` và tên lệnh vẫn là một chỉ dẫn. Đây không phải giả
     * định — `schemas/Backend/Product/CustomerOrderItem.yaml` từng ngắt đúng
     * chỗ đó và lọt qua một lượt grep theo dòng.
     *
     * @return list<string>
     */
    public static function commandsIn(string $contents): array
    {
        $flat = (string) preg_replace('/\s+/u', ' ', $contents);

        $commands = [];

        // `(?:[#*]+\s*)?` — dấu mở comment của dòng kế (`#` trong YAML, `*`
        // trong docblock) khi chỉ dẫn bị ngắt dòng giữa neo và tên lệnh.
        $name = '([a-z][a-z0-9-]*:[a-z][a-z0-9-]*)';

        // Neo 1 — `artisan <lệnh>` (php artisan, docker compose exec app php artisan, …)
        preg_match_all('/artisan\s+(?:[#*]+\s*)?`?'.$name.'/', $flat, $m);
        foreach ($m[1] as $found) {
            $commands[$found] = true;
        }

        // Neo 2 — `run <lệnh>`, trừ script của trình quản lý gói.
        // `docker ` nằm cùng rổ loại trừ: `docker run redis:alpine` mang đúng
        // hình `run <tên>:<thẻ>` nhưng không phải lệnh artisan.
        preg_match_all(
            '/(?:^|[^\w-])(npm |pnpm |yarn |composer |docker )?[Rr]un\s+(?:[#*]+\s*)?`?'.$name.'/',
            $flat,
            $m
        );
        foreach ($m[2] as $i => $found) {
            if (trim($m[1][$i]) !== '') {
                continue;
            }
            $commands[$found] = true;
        }

        // `make:mcp-*` trong văn xuôi là một HỌ lệnh, không phải một lệnh. Tên
        // thật không bao giờ kết thúc bằng `-`.
        return array_values(array_filter(
            array_keys($commands),
            static fn (string $command): bool => ! str_ends_with($command, '-'),
        ));
    }

    private static function lineOf(string $contents, string $needle): int
    {
        $lines = preg_split('/\R/', $contents) ?: [];
        foreach ($lines as $i => $line) {
            if (str_contains($line, $needle)) {
                return $i + 1;
            }
        }

        // Chỉ dẫn bị ngắt dòng giữa neo và tên lệnh — tên nằm ở dòng nào không
        // quan trọng bằng việc chỉ đúng file.
        return 0;
    }
}

it('#2215 — mọi chỉ dẫn "chạy lệnh X" trên bề mặt sống trỏ tới một lệnh có thật', function () {
    $umbrella = dirname(base_path());

    $roots = ArtisanCommandReferenceScanner::roots();

    // Bố cục đổi mà rào im lặng bỏ qua thì rào chỉ còn là trang trí — nổ ở đây
    // thay vì trả về "0 vi phạm" trên một cây không quét được gì.
    foreach ($roots as $root) {
        expect(is_dir($root))->toBeTrue("Không quét được {$root} — bố cục repo đã đổi, sửa danh sách roots.");
    }

    $registered = array_keys(Artisan::all());

    $offenders = [];
    foreach ($roots as $root) {
        foreach (ArtisanCommandReferenceScanner::scan($root) as $command => $places) {
            if (in_array($command, $registered, true)) {
                continue;
            }

            foreach ($places as $place) {
                $offenders[] = str_replace($umbrella.'/', '', $place)." → `{$command}`";
            }
        }
    }

    sort($offenders);

    expect($offenders)->toBe([], implode("\n", [
        'Chỉ dẫn trỏ tới một lệnh artisan KHÔNG TỒN TẠI.',
        '',
        'Người vận hành đọc chỗ này lúc đang có sự cố sẽ gõ một lệnh rồi nhận',
        '`Command "..." is not defined.` — mất thời gian đúng lúc đắt nhất.',
        '',
        'Cách sửa: viết ra cách khắc phục THẬT (reseed, sửa qua CRUD, lệnh còn',
        'sống). KHÔNG dựng lại lệnh đã xoá — ruling #2188 cấm.',
        '',
        ...$offenders,
    ]));
});

it('#2215 — bộ quét bắt được cả hai neo, kể cả khi chỉ dẫn bị ngắt dòng', function () {
    // Ghim chính BỘ QUÉT, tách khỏi trạng thái cây: nếu neo hỏng thì bài test
    // trên sẽ xanh vì không tìm thấy gì — đúng kiểu "xanh vì không đo".
    expect(ArtisanCommandReferenceScanner::commandsIn('php artisan tax-types:backfill --dry-run'))
        ->toBe(['tax-types:backfill']);

    expect(ArtisanCommandReferenceScanner::commandsIn('Brand has no tax types; run tax-types:backfill or check the hook.'))
        ->toBe(['tax-types:backfill']);

    expect(ArtisanCommandReferenceScanner::commandsIn("# Hai ngoại lệ: artisan\n  # orders:backfill-tax-snapshots (Q7)"))
        ->toBe(['orders:backfill-tax-snapshots']);

    // Script của package manager KHÔNG phải lệnh artisan.
    expect(ArtisanCommandReferenceScanner::commandsIn('Run `npm run omnify:gen` and commit the regen'))
        ->toBe([]);

    // Nhắc tên trong removal record (không có neo) KHÔNG bị chặn.
    expect(ArtisanCommandReferenceScanner::commandsIn('`tax-types:backfill` and `orders:backfill-tax-snapshots` were deleted on 2026-08-08.'))
        ->toBe([]);

    // `host:port`, `min:0`, `shop:1` … không phải tên lệnh.
    expect(ArtisanCommandReferenceScanner::commandsIn('run localhost:8080 and run min:0'))
        ->toBe([]);
});

it('#3100 — ADR (docs/decisions/) nằm TRONG phạm vi quét, còn plans/ thì KHÔNG', function () {
    $umbrella = dirname(base_path());

    // Nếu ADR dời chỗ thì bài test này phải ĐỎ chứ không được im — một khẳng
    // định về thư mục không còn tồn tại là khẳng định không còn kiểm gì.
    expect(is_dir($umbrella.'/docs/decisions'))
        ->toBeTrue('docs/decisions/ biến mất — ADR đã dời, sửa rào này cùng lượt thay vì xoá nó.');

    // Chiều KHẲNG ĐỊNH — ADR chở khối copy-paste chạy được, nên một lệnh không
    // tồn tại trong đó là chỉ dẫn hỏng, không phải lịch sử.
    // Đường dẫn tổng hợp, không ghim tên file thật: rào ghim chuỗi nguyên văn
    // chết lặng ngay khi file được đổi tên.
    expect(ArtisanCommandReferenceScanner::covers($umbrella.'/docs/decisions/0000-bat-ky-adr.md'))
        ->toBeTrue(implode("\n", [
            'docs/decisions/ vừa bị đưa RA KHỎI phạm vi quét.',
            '',
            'Đừng suy từ `plans/` sang: plans/ được miễn vì nó ở thì QUÁ KHỨ (ghi lại',
            'việc đã làm bằng công cụ thời điểm đó). ADR ở thì TƯƠNG LAI — thứ sắp',
            'được đem đi làm.',
            '',
            'Lý do giữ ADR trong phạm vi là KHỐI COPY-PASTE, không phải "ops đọc ADR',
            'lúc sự cố" (#3098 đo: không đường dẫn nào dẫn ops tới đây). ADR 0002 chở',
            'nguyên một dòng crontab có `artisan <lệnh>` để người triển khai dán vào',
            'prod — tên sai ở đó hỏng đúng như hỏng trong runbook.',
            '',
            'ADR mô tả một lệnh chưa ra đời thì bỏ neo `artisan` đi (mention trong',
            'backtick KHÔNG bị chặn), đừng miễn trừ cả thư mục.',
        ]));

    // Chiều PHỦ ĐỊNH — một rào chỉ biết kêu mà không biết im thì không chứng
    // minh được gì: vế này cho thấy covers() thật sự đọc cấu hình phạm vi.
    expect(ArtisanCommandReferenceScanner::covers($umbrella.'/plans/plan-000/bat-ky.md'))
        ->toBeFalse('plans/ vừa bị kéo VÀO phạm vi quét — hồ sơ thiết kế ở thì quá khứ sẽ đỏ vĩnh viễn.');

    // Hai điều kiện còn lại của covers(), để việc nới chúng cũng phải cố ý.
    expect(ArtisanCommandReferenceScanner::covers($umbrella.'/docs/decisions/0000-bat-ky-adr.txt'))
        ->toBeFalse('phần mở rộng ngoài EXTENSIONS không được quét');
    expect(ArtisanCommandReferenceScanner::covers($umbrella.'/docs/reference/vendor/foo/README.md'))
        ->toBeFalse('SKIP_SEGMENTS phải loại vendor/ — và đường dẫn này NẰM DƯỚI một root, nếu không vế phủ định lại xanh vì lý do khác');
});

/**
 * BÁNH CÓC — `SKIP_SEGMENTS` chỉ được CO LẠI.
 *
 * Vùng mù không tự khai báo. Một mục ở đây tắt tiếng cả một cây đường dẫn, im
 * lặng, mãi mãi — và nếu cây đó không tồn tại thì mục ấy không dọn dẹp gì, nó
 * chỉ ký sẵn cho ngày cây đó ra đời. Đo 2026-08-18: bốn trong sáu mục cũ khớp
 * **0 đường dẫn** dưới `roots()`.
 *
 * Phép đo là hệ thống tệp thật, không phải suy luận: mỗi mục phải loại được ít
 * nhất một đường dẫn CÓ THẬT nằm dưới một root.
 */
it('#3190 — bánh cóc: mỗi SKIP_SEGMENTS phải loại một đường dẫn CÓ THẬT dưới roots()', function () {
    $roots = ArtisanCommandReferenceScanner::roots();

    foreach (ArtisanCommandReferenceScanner::skipSegments() as $segment) {
        $hits = 0;

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (str_contains(str_replace('\\', '/', $file->getPathname()), $segment)) {
                    $hits++;
                    break 2;
                }
            }
        }

        expect($hits)->toBeGreaterThan(0, implode("\n", [
            "SKIP_SEGMENTS `{$segment}` HẾT ỨNG: không đường dẫn nào dưới roots() chứa nó,",
            'nên nó không loại trừ gì. Xoá mục đó.',
            '',
            'Một vùng mù cấp sẵn KHÔNG vô hại: ngày một thư mục mang đoạn đường dẫn đó',
            'xuất hiện dưới một root (fixture `tests/storage/`, `package.json` mới dưới',
            '`scripts/`…), cả cây ấy rơi khỏi tầm quét mà không gì đỏ.',
            '',
            'Danh sách này chỉ ĐI XUỐNG.',
        ]));
    }
});
