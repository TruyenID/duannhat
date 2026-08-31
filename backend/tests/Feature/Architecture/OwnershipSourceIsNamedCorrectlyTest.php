<?php

declare(strict_types=1);

/**
 * Rào chống ĐẶT SAI TÊN NGUỒN QUYỀN SỞ HỮU — lỗi đã xảy ra hai lần theo hai
 * hướng ngược nhau trong cùng một ngày.
 *
 * 1. Repo khẳng định nguồn là một hệ identity nay đã ngưng phát triển. Khẳng định
 *    ấy cũ đi mà không ai thấy; một phiên sau đi theo nó, mở issue ở repo chết,
 *    và mất một buổi mới đo lại.
 * 2. Bản sửa đầu tiên quá tay theo hướng ngược: ghi "chưa hệ nào sở hữu" trong
 *    khi Platform đã có sẵn hai phần ba lời giải (`brand → organization` = sở
 *    hữu, `branch → organization` = vận hành).
 *
 * Cả hai đều là cùng một hình dạng: một cái TÊN trong văn bản, không có gì buộc
 * nó đúng. Test này là cái buộc đó.
 */

/** Tên hệ đã ngưng — không được xuất hiện ở bất kỳ đâu nữa. */
const RETIRED_OWNERSHIP_SOURCE_NAMES = [
    'godx-identity',
    'Godx Identity',
    'godx_identity',
];

/**
 * Cách nói MƠ HỒ về nguồn — cấm, vì đó là hướng sai thứ hai.
 *
 * Chỉ đòi "phải có chữ Platform" thì gần như vô nghĩa trong một file lớn: chữ đó
 * xuất hiện ở chỗ khác là đủ qua. Đã đo — đổi đúng câu khẳng định thành "CHƯA
 * ĐƯỢC QUYẾT ĐỊNH" mà test vẫn xanh. Nên cấm thẳng cách nói.
 */
const AMBIGUOUS_OWNERSHIP_PHRASES = [
    'CHƯA ĐƯỢC QUYẾT ĐỊNH',
    'chưa được quyết định',
    'undetermined',
    'chưa xác định',
];

/** Nơi có quyền phát biểu về nguồn quyền sở hữu. */
const OWNERSHIP_NARRATIVE_PATHS = [
    'app/Services/Payment/Policy/UnavailableBranchManagementProjectionSource.php',
    'app/Services/Payment/Policy/Contracts/BranchManagementProjectionSource.php',
    'app/Providers/AppServiceProvider.php',
];

it('không nhắc lại hệ identity đã ngưng ở bất kỳ đâu', function () {
    $hits = [];

    foreach ([base_path('app'), base_path('tests'), base_path('config')] as $root) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! in_array($file->getExtension(), ['php', 'json'], true)) {
                continue;
            }

            // Chính file này phải chứa các tên đó để cấm chúng.
            if ($file->getPathname() === __FILE__) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            foreach (RETIRED_OWNERSHIP_SOURCE_NAMES as $name) {
                if (str_contains($contents, $name)) {
                    $hits[] = str_replace(base_path().'/', '', $file->getPathname()).' → '.$name;
                }
            }
        }
    }

    expect($hits)->toBe([], "Hệ identity đã ngưng phát triển (push cuối 2026-07-13) và KHÔNG\n"
        ."phải nguồn quyền sở hữu. Nguồn là Platform — xem docblock của\n"
        ."UnavailableBranchManagementProjectionSource, mục 'Cách tự kiểm'.\n"
        .'Chỗ vi phạm: '.implode(', ', $hits));
});

it('nêu đích danh Platform ở mọi chỗ phát biểu về nguồn quyền sở hữu', function () {
    foreach (OWNERSHIP_NARRATIVE_PATHS as $relative) {
        $contents = (string) file_get_contents(base_path($relative));

        // `toContain($a, $b)` coi CẢ HAI là giá trị cần chứa, không phải thông
        // điệp — bản đầu của test này viết sai đúng như thế và đỏ oan.
        foreach (AMBIGUOUS_OWNERSHIP_PHRASES as $phrase) {
            expect(str_contains($contents, $phrase))->toBeFalse(
                "{$relative} nói nguồn quyền sở hữu là mơ hồ ({$phrase}). Không phải: "
                .'Platform sở hữu mô hình này và đã có brand→org (sở hữu) + branch→org '
                .'(vận hành); thứ còn thiếu là vòng đời grant và endpoint đọc.');
        }

        expect(str_contains($contents, 'Platform'))->toBeTrue(
            "{$relative} nói về nguồn quyền sở hữu nhưng KHÔNG nêu tên Platform. "
            ."Đừng để nó mơ hồ: 'chưa xác định' cũng sai như đặt sai tên — Platform "
            .'đã có brand→org (sở hữu) và branch→org (vận hành).');
    }
});

it('ghim fixture hợp đồng vào đúng nguồn', function () {
    $fixture = json_decode(
        (string) file_get_contents(base_path('tests/Fixtures/Payment/branch-management-contract.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($fixture['source'])->toBe('platform',
        "Đổi giá trị này nghĩa là đổi hệ sở hữu mô hình quản lý chi nhánh — một\n"
        .'quyết định kiến trúc. Kèm bằng chứng đo được, đừng sửa cho test xanh.');
});
