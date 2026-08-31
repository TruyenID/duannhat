<?php

declare(strict_types=1);

namespace App\Console\Commands\Deploy;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Rào catalog của đường deploy (#2463), thu hẹp lại ở #2574.
 *
 * ## Vì sao bốn sàn ĐẾM không còn chạy mặc định
 *
 * Bản đầu đặt nhiệm vụ là "bắt một lượt seed thành công nhưng để catalog nửa
 * vời". Seeder gây ra hình dạng đó là `CatalogSnapshotSeeder` — mà `BetoyaSeeder`
 * BỎ QUA nó trên mọi DB đã có catalog:
 *
 *     BetoyaSeeder: catalog already present — skipping CatalogSnapshotSeeder
 *
 * Production luôn có catalog ⇒ mỗi lượt deploy **không restore gì**. Bốn sàn
 * đếm vì thế đang đo những bảng mà chính lượt deploy đó không đụng tới, để bắt
 * một tình huống không thể xảy ra trên đường deploy thường.
 *
 * Thành tích đo được ngày 2026-08-12: **0 lỗi thật bắt được, 3 lượt deploy
 * hỏng, 3 lần phải sửa lại hằng số** (#2542, #2561, #2568). Và mỗi lần hỏng là
 * hỏng NỬA CHỪNG — bước này nằm sau `migrate` + `seed`, nên nửa trước đã ghi
 * còn `export-authz-manifest` → `service:sync-authz-manifest` →
 * `ServiceUserAccess` thì không chạy.
 *
 * Nên chúng chuyển sang `--after-restore`, dùng ngay sau
 * `db:seed --class=CatalogSnapshotSeeder`, nơi chúng thật sự có nghĩa. Deploy
 * gọi lệnh TRẦN.
 *
 * ## Cái còn chạy mặc định, và vì sao
 *
 * - `brands === 1` — danh tính bản snapshot. Không brand ⇒ restore rỗng; hai
 *   brand ⇒ restore nhầm tenant. Không có nhánh "công ty lớn lên".
 * - dòng menu còn sống trỏ vào product đã xoá = 0 — hỏng catalog từ **bất kỳ
 *   nguồn nào**, không riêng restore: món hiện trên menu, khách bấm, đặt hàng
 *   thì lỗi. Không phụ thuộc con số nghiệp vụ nào nên không trôi.
 *
 * READ-ONLY. Every query here is a count. This is a data GATE, not a repair:
 * it must never write, because the one thing worse than a bad snapshot is a
 * deploy that quietly edits shop-owned data trying to fix one (see the
 * `CLAUDE.md` section this issue produced).
 *
 * #2542 đọc câu trên rộng thêm một bậc: **cũng không được KHẲNG ĐỊNH trên dữ
 * liệu quán sở hữu.** Không ghi thì không cướp lựa chọn của quán, nhưng ra điều
 * kiện thì biến quyền sửa dữ liệu bình thường của họ thành quyền làm hỏng
 * deploy — và vì bước này chạy SAU migrate/seed, "hỏng" nghĩa là nửa trước đã
 * ghi còn phần đồng bộ authz sang Platform thì không chạy. Phép thử trước khi
 * thêm một khẳng định mới: **biên** của nó là bao nhiêu hàng? Một restore hỏng
 * để lại catalog gần 0, nên sàn 412 sản phẩm có biên hàng trăm hàng. Sàn 4 zone
 * có biên MỘT hàng, và một hàng thì không phân biệt nổi thảm hoạ với việc quán
 * dọn lại sơ đồ bàn.
 *
 * ## Các con số dưới `--after-restore` là SÀN, không phải đẳng thức
 *
 * Sàn vẫn xanh khi quán thêm sản phẩm hợp lệ; đẳng thức biến việc kinh doanh
 * lớn lên thành deploy đỏ. `branches` TỪNG là đẳng thức với lý lẽ "Platform sở
 * hữu, Tempo soi gương" — #2561 gỡ nó: mở quán thứ 18 cũng đi qua Platform, nên
 * đẳng thức không phân biệt được gương hỏng với gương phản chiếu một thực tế mới.
 *
 * `brands === 1` là đẳng thức DUY NHẤT còn lại, vì nó không đo lượng mà đo danh
 * tính (xem trên).
 */
final class VerifyProductionSeedCommand extends Command
{
    protected $signature = 'deploy:verify-production-seed
        {--after-restore : Kiểm thêm bốn SÀN ĐẾM — chỉ có nghĩa ngay sau một lần restore thật}';

    protected $description = 'Assert the Betoya catalog is not corrupt; with --after-restore, also that a restore filled it';

    /** Ningyocho's full menu — the one every branch serves from. */
    private const NINGYOCHO_MENU_ID = '019f6efa-2f83-71a8-b061-2c8f9435718a';

    /**
     * Sàn, KHÔNG phải đẳng thức (#2561).
     *
     * Bản trước ghim `=== 17` với lý lẽ "Platform sở hữu, Tempo soi gương, lệch
     * nghĩa là gương hỏng". Đúng một nửa: mở quán thứ 18 cũng đi qua Platform,
     * và Tempo soi gương ĐÚNG khi nó thành 18. Đẳng thức không phân biệt được
     * "gương hỏng" với "gương phản chiếu một thực tế mới" — nó chỉ biết "khác 17".
     *
     * Con số 8 chọn theo BIÊN, cùng phép thử đã dùng ở #2542: restore hỏng để
     * lại bảng gần RỖNG, còn thay đổi kinh doanh dịch ±1, hoạ hoằn ±2. Tám nằm
     * giữa hai lớp đó với khoảng cách chín hàng mỗi phía — đủ xa để đóng cửa
     * vài quán không thành deploy đỏ, và vẫn bắt được một restore chỉ dựng lên
     * dăm ba chi nhánh.
     */
    private const MIN_BRANCHES = 8;

    /**
     * Ba sàn dưới đây hiệu chỉnh lại từ SỐ ĐO PRODUCTION (#2568), không phải
     * từ fixture. Đo `2026-08-12 16:2x JST` qua SSH vào tempo-prod:
     *
     * | | production | fixture | sàn mới |
     * |---|---|---|---|
     * | `products` | 421 | 419 | 200 |
     * | `files` | 333 | 296 | 150 |
     * | `menu_products` (menu 人形町) | 99 | 99 | 40 |
     *
     * Bản trước lấy thẳng số hàng fixture: 412/419, 297/296, và 99/99 — cái
     * cuối **biên bằng KHÔNG**. HQ gỡ một món khỏi menu là 98, là deploy đỏ
     * nửa chừng ở mọi lượt sau đó, chặn đúng bước sync authz sang Platform.
     *
     * Sàn ≈ **một nửa** số đo, vì đó là chỗ hai lớp cách nhau xa nhất:
     * restore hỏng để lại catalog gần 0, còn nghiệp vụ không bao giờ giảm một
     * nửa trong một lần. Muốn xoá 221 sản phẩm hay 59 dòng menu thì đó không
     * còn là chỉnh menu, đó là sự cố — và lúc ấy deploy đỏ mới là đúng.
     *
     * Fixture KHÔNG phải production và đừng dùng nó để hiệu chỉnh: nó có 42
     * zone trong khi production có 36, và mang cả zone `TRUYEN` do người tạo
     * tay hôm 2026-07-18 mà production chưa bao giờ có. Chính chỗ lệch đó đẻ
     * ra sự cố #2542. `FloorsKeepMarginOverFixtureTest` cưỡng chế điều này.
     */
    private const MIN_PRODUCTS = 200;

    private const MIN_FILES = 150;

    private const MIN_MENU_PRODUCTS = 40;

    public function handle(): int
    {
        $this->assert(
            DB::table('brands')->where('slug', 'betoya')->count() === 1,
            'Betoya brand is missing.',
        );

        // Bốn sàn ĐẾM chỉ chạy khi có `--after-restore` (#2574). Lý do ở
        // docblock của lớp: trên một lượt deploy thường, `CatalogSnapshotSeeder`
        // KHÔNG chạy, nên chúng đo những bảng lượt đó không hề đụng tới.
        if ($this->option('after-restore')) {
            $this->assert(
                ($n = DB::table('branches')->count()) >= self::MIN_BRANCHES,
                sprintf('Expected at least %d Platform branches (floor), got %d.', self::MIN_BRANCHES, $n),
            );

            $this->assert(
                ($n = DB::table('products')->count()) >= self::MIN_PRODUCTS,
                sprintf('Expected at least %d products (floor), got %d.', self::MIN_PRODUCTS, $n),
            );

            $this->assert(
                ($n = DB::table('files')->count()) >= self::MIN_FILES,
                sprintf('Expected at least %d real file rows (floor), got %d.', self::MIN_FILES, $n),
            );

            // Soft deletes are the trap here: `DB::table()` returns deleted rows
            // too, so this count filters `deleted_at` explicitly. Counting
            // without it once turned "18 missing tables" into "0".
            $this->assert(
                ($n = DB::table('menu_products')
                    ->where('menu_id', self::NINGYOCHO_MENU_ID)
                    ->whereNull('deleted_at')
                    ->count()) >= self::MIN_MENU_PRODUCTS,
                sprintf('Ningyocho menu must contain at least %d live product rows (floor), got %d.', self::MIN_MENU_PRODUCTS, $n),
            );
        }

        // A live menu row pointing at a deleted product is the specific
        // corruption that reaches a customer: the item renders on the menu and
        // fails at order time. KHÔNG gắn với restore: nó bắt hỏng catalog từ
        // bất kỳ nguồn nào, nên chạy ở MỌI lượt.
        $this->assert(
            DB::table('menu_products as mp')
                ->join('products as p', 'p.id', '=', 'mp.product_id')
                ->where('mp.menu_id', self::NINGYOCHO_MENU_ID)
                ->whereNull('mp.deleted_at')
                ->whereNotNull('p.deleted_at')
                ->count() === 0,
            'A live menu row references a deleted product.',
        );

        // ĐÃ GỠ #2542 — sàn `zones >= 4` và `tables >= 18` của Ningyocho.
        //
        // Đừng dựng lại. Zone và bàn là dữ liệu QUÁN SỞ HỮU: quán thêm/xoá được
        // trong admin-web, và ngày 2026-08-12 quán xoá đúng MỘT zone (4 → 3) là
        // deploy production đỏ — không phải một lần, mà mọi lần từ đó trở đi.
        //
        // Cái giá không dừng ở "deploy đỏ": bước này chạy SAU migrate và seed,
        // nên nửa trước đã ghi vào DB thật còn ba bước sau (`export-authz-manifest`
        // → `service:sync-authz-manifest` → `ServiceUserAccess`) thì không chạy.
        // Một zone bị xoá làm quyền user trên Platform ngừng được đồng bộ.
        //
        // Vì sao gỡ hẳn chứ không hạ số: hạ xuống 3 chỉ dời cái bẫy sang lần xoá
        // sau. Cái sai là RA ĐIỀU KIỆN trên dữ liệu quán sở hữu, không phải con
        // số. Các sàn còn lại đứng được vì biên rộng — một restore hỏng để lại
        // catalog gần 0 chứ không phải 411 sản phẩm — còn sàn zone có biên đúng
        // MỘT HÀNG.
        //
        // Docblock của lớp này đã nói "không bao giờ được GHI vào dữ liệu quán
        // sở hữu"; #2542 chỉ đọc nó rộng thêm một bậc: cũng không được KHẲNG
        // ĐỊNH trên dữ liệu đó.

        $this->info('Production seed invariants hold.');

        return self::SUCCESS;
    }

    private function assert(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }
}
