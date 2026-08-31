<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * Mọi fixture DÙNG CHUNG giữa Cloud và máy trạm phải khớp từng byte.
 *
 * ## Vì sao bài này nằm ở `Architecture/`, không nằm cạnh cổng nó canh
 *
 * Vì đó là thư mục DUY NHẤT chạy trên mỗi PR. Job `arch-gate` của
 * `backend-tests.yml` chạy `tests/Unit/Arch` + `tests/Feature/Architecture`;
 * job `pest` (full suite) cố ý chỉ chạy ở cổng thăng cấp `dev → main` (#1516).
 *
 * Hệ quả trước bài này: mọi cổng parity — `TaxAllocationGoldenParityTest`,
 * `TaxResolutionGoldenParityTest`, `OfflineSigningGoldenTest` — nằm trong
 * `tests/Feature/{Tax,Order}/`, tức KHÔNG chạy trên PR nào vào `dev`. Một fixture
 * lệch chỉ lộ ra ở lượt thăng cấp, sau khi đã có vài PR chồng lên nó.
 *
 * ## Vì sao TỰ PHÁT HIỆN thay vì liệt kê tên file
 *
 * Liệt kê thì mỗi cổng parity mới phải nhớ sửa thêm một chỗ, và quên là im lặng.
 * Bài này quét cả hai thư mục và bắt cặp theo tên, nên cổng thứ tư được canh mà
 * không ai phải nhớ gì.
 *
 * ## Bỏ qua thì phải ĐẾM LÀ BỎ QUA
 *
 * Ba cổng nói trên khi vắng submodule đều chạy `expect(true)->toBeTrue()` rồi
 * `return`. Chúng XANH, cộng vào tổng assertion, và trông y như đã đo. #2082 là
 * hoá đơn: hai bản sửa làm tròn phía Cloud trôi khỏi Go trong khi các file ấy
 * vẫn báo pass.
 *
 * Repo đã có sẵn cách làm đúng — `test()->markTestSkipped()`, dùng ở
 * `CatalogParityTest` / `RendererPrimitivesParityTest` — vì một test BỎ QUA hiện
 * ra là "skipped" trong bảng tổng kết, còn một `expect(true)` thì hiện ra là
 * "passed". Khác biệt ấy đúng là thứ đã mất.
 *
 * Và ở **CI thì không có đường bỏ qua**: `arch-gate` clone submodule bằng deploy
 * key read-only, nên vắng nó là LỖI HẠ TẦNG, không phải hoàn cảnh bình thường —
 * mọi khẳng định parity của repo đều rỗng cho tới khi sửa. Worktree do `tal
 * claim` dựng thì ngược lại: submodule cố ý chưa init, và ở đó bỏ qua-có-báo là
 * câu trả lời đúng.
 */

/**
 * Số cặp fixture chung tối thiểu.
 *
 * Một phép quét không tìm thấy gì trông y hệt một phép quét sạch. Hằng số này
 * làm khác biệt ấy lộ ra: xoá một fixture chung — hoặc làm hỏng bộ quét — là đỏ,
 * không phải xanh im lặng.
 *
 * #2180 nâng 3 → 7 (số thực đo lúc review): PR đó gỡ hai cổng byte-identity cục
 * bộ và đặt toàn bộ niềm tin vào cổng này — sàn lỏng hơn thực tế 4 bậc thì xoá
 * tới 4 fixture chung vẫn xanh. Thêm fixture chung mới thì nâng số này theo.
 */
const SHARED_FIXTURE_PAIRS = 8;

/**
 * Fixture MỘT PHÍA đã biết — có ở backend, chưa có bản máy trạm.
 *
 * Danh sách này hiện **RỖNG**, và đó là trạng thái đúng: mọi fixture trong
 * `tests/Fixtures/` đều đã có bản đọc phía máy trạm.
 *
 * `split_by_items_cases.json` từng nằm đây (#2089) vì Go có cài đặt chia bill mà
 * không ai đối chiếu. #2112 thêm bản đọc Go, và nó đỏ ngay lượt đầu — hai lỗi
 * tiền thật, nay theo dõi ở #2130.
 *
 * Danh sách CHỈ ĐƯỢC CO LẠI.
 *
 * @var list<string>
 */
const KNOWN_ONE_SIDED_FIXTURES = [];

function sharedFixtureOursDir(): string
{
    return base_path('tests/Fixtures');
}

function sharedFixtureTheirsDir(): string
{
    return base_path('../workstation/internal/service/testdata');
}

/**
 * Vắng nguồn máy trạm = hỏng, ở MỌI môi trường (#2333).
 *
 * Trước monorepo (#2306) đây là submodule: vắng ở local là chuyện thường nên có
 * `markTestSkipped`, vắng trên CI nghĩa là deploy key hỏng nên ĐỎ. Nay nguồn nằm
 * in-tree, `.gitmodules` không còn, nên hoàn cảnh "cố ý chưa init" đã hết —
 * vắng chỉ có thể là cây hỏng hoặc đường dẫn trỏ sai (thư mục đổi tên hai lần ở
 * #2312 và #2327, và cổng docs anh em đã âm thầm hỏng đúng vì thế).
 *
 * Giữ lại nhánh skip là để dành đúng một chỗ cho lỗi kế tiếp trốn.
 */
function requireWorkstationFixtures(): void
{
    if (is_dir(sharedFixtureTheirsDir())) {
        return;
    }

    throw new RuntimeException(implode("\n", [
        'Nguồn máy trạm vắng mặt: '.sharedFixtureTheirsDir(),
        '',
        'Mọi cổng parity Cloud↔máy trạm đều rỗng khi không có nó, nên đây là',
        'lỗi cấu hình lượt chạy chứ không phải hoàn cảnh để bỏ qua.',
        '',
        'Nguồn nằm IN-TREE từ #2306 — không có submodule nào để init. Kiểm xem',
        'đường dẫn trong bài test có còn đúng với cây hiện tại không.',
    ]));
}

/** @return array<string, SplFileInfo> */
function ourJsonFixtures(): array
{
    $out = [];

    foreach (File::files(sharedFixtureOursDir()) as $file) {
        if ($file->getExtension() === 'json') {
            $out[$file->getFilename()] = $file;
        }
    }

    return $out;
}

it('mọi fixture dùng chung khớp từng byte ở cả hai repo', function () {
    requireWorkstationFixtures();

    $theirs = sharedFixtureTheirsDir();
    $mismatched = [];
    $paired = 0;

    foreach (ourJsonFixtures() as $name => $file) {
        $twin = $theirs.'/'.$name;
        if (! file_exists($twin)) {
            continue;
        }
        $paired++;

        if (hash_file('sha256', $twin) !== hash_file('sha256', $file->getPathname())) {
            $mismatched[] = $name;
        }
    }

    expect($mismatched)->toBe([], implode("\n", array_merge(
        ['Fixture dùng chung ĐÃ LỆCH giữa hai repo:', ''],
        array_map(static fn ($n) => "  {$n}", $mismatched),
        [
            '',
            'Đây là hợp đồng chung. Sửa nội dung thì sửa ở MỘT nơi, copy sang cả hai',
            'repo trong CÙNG một PR, rồi mới sửa code hai phía cho khớp.',
            '',
            'Sửa một bên rồi copy một chiều là cách #2082 xảy ra.',
        ],
    )));

    expect($paired)->toBeGreaterThanOrEqual(SHARED_FIXTURE_PAIRS, sprintf(
        "Chỉ tìm thấy %d cặp fixture chung, chờ ít nhất %d.\n\n".
        "Hoặc một cặp vừa bị xoá, hoặc bộ quét hỏng. Cả hai đều làm cổng parity\n".
        'thôi canh mà không kêu tiếng nào.',
        $paired,
        SHARED_FIXTURE_PAIRS,
    ));
});

it('fixture MỘT PHÍA chỉ được co lại, không được thêm', function () {
    requireWorkstationFixtures();

    $theirs = sharedFixtureTheirsDir();

    $oneSided = [];
    foreach (ourJsonFixtures() as $name => $file) {
        if (! file_exists($theirs.'/'.$name)) {
            $oneSided[] = $name;
        }
    }

    sort($oneSided);
    $known = KNOWN_ONE_SIDED_FIXTURES;
    sort($known);

    $new = array_values(array_diff($oneSided, $known));
    expect($new)->toBe([], implode("\n", array_merge(
        ['Fixture MỚI chỉ có một phía — cổng parity của nó là cổng GIẢ:', ''],
        array_map(static fn ($n) => "  {$n}", $new),
        [
            '',
            'Fixture chỉ một phía đọc thì không buộc được gì. Hoặc thêm bản đọc phía',
            'máy trạm, hoặc khai vào KNOWN_ONE_SIDED_FIXTURES kèm lý do.',
        ],
    )));

    $fixed = array_values(array_diff($known, $oneSided));
    expect($fixed)->toBe([], implode("\n", array_merge(
        ['Fixture này KHÔNG còn một phía — gỡ khỏi KNOWN_ONE_SIDED_FIXTURES:', ''],
        array_map(static fn ($n) => "  {$n}", $fixed),
        ['', 'Danh sách phải co lại, nếu không nó thôi nói lên điều gì.'],
    )));
});
