<?php

declare(strict_types=1);

/*
 * #2777 — mọi seeder trong danh sách `isProduction()` của `DatabaseSeeder` phải
 * THẬT SỰ tới được đường deploy.
 *
 * Đường deploy **không bao giờ gọi `DatabaseSeeder`**. Nó gọi đích danh từng
 * `--class=`. Nên danh sách dưới `if (app()->isProduction())` đọc như "đây là
 * thứ production chạy" trong khi nó chỉ là thứ chạy khi ai đó gõ
 * `db:seed` tay — và không ai gõ.
 *
 * Cái lỗ đó đã nuốt trọn một bản vá: #2451 thêm `SystemNotificationRuleSeeder`
 * vào danh sách để chữa "0 luật thông báo trên production", kèm hẳn một comment
 * ghi phép đo. Nhiều lần deploy sau, production **vẫn 0 rule**. Bản vá trông
 * như đã ship, và tin rằng nó đã ship mới là phần nguy hiểm.
 *
 * Rào này phải biết KÊU **và** biết IM (#2188 · "rào phải chứng minh cả hai
 * chiều"): thêm một seeder vào `DatabaseSeeder` mà quên nối vào workflow ⇒ ĐỎ;
 * một seeder cố ý đứng ngoài ⇒ khai vào `DELIBERATELY_OFF_DEPLOY` kèm LÝ DO và
 * nó im. Rào kêu oan không bị tranh luận, nó bị tắt.
 *
 * Nằm ở `tests/Feature/Architecture/` vì đó là thư mục DUY NHẤT `arch-gate`
 * chạy trên MỌI PR vào `dev`.
 */

use Illuminate\Support\Facades\File;

/**
 * Seeder CỐ Ý không nằm trên đường deploy — mỗi mục phải mang lý do đo được.
 *
 * Đây không phải danh sách miễn trừ cho tiện. Ba mục dưới đây đứng ngoài vì ba
 * lý do KHÁC NHAU, và trộn chúng thành một là mất chính thông tin đó.
 */
const DELIBERATELY_OFF_DEPLOY = [
    // CHẶN CỨNG. `BaselineProvisioningSeeder` → `BranchBaselineProvisioner` ghi
    // `ShopOrderSetting` (6 chỗ). `shop_order_settings` là state QUÁN SỞ HỮU, và
    // CLAUDE.md §"deploy được seed DANH MỤC HỆ THỐNG, KHÔNG được quyết hộ quán"
    // cấm deploy tái áp nó — luật đó viết ra sau khi việc này tốn hai lần dịch
    // vụ thật ngày 2026-08-11. Muốn chạy thì chạy TAY, có người nhìn.
    'BaselineProvisioningSeeder',

    // CHẶN CỨNG, phát hiện ở review vòng 1 của #2777 — vòng 1 của tôi ĐÃ nối nó
    // vào deploy và khai nhầm "quán không sửa được".
    //
    // `till_tender_types` là từ vựng TỔ CHỨC SỬA ĐƯỢC: admin-web HQ
    // `settings/payments/tenders` PATCH được `is_active` (TenderTypeController
    // ::update, rule `'is_active' => ['sometimes','boolean']`), và chính đường
    // xoá của controller BẢO người ta dùng nó — "Không xoá được tender đã có
    // payment … Hãy tắt (is_active = false)".
    //
    // Còn seeder thì `$service->update($existing, $row)` với `$row` mang
    // `'is_active' => true`. Nên: org tắt `momo` → deploy bật lại → sửa tay →
    // deploy sau đè tiếp. Đúng vòng lặp Hongo ngày 2026-08-11.
    //
    // (Bản ghi review còn nêu SoftDeletes hồi sinh hàng đã xoá — đo lại thì
    // `TillTenderType` KHÔNG dùng SoftDeletes, nên vế đó không đúng. Vế ghi đè
    // `is_active` thì đúng, và một mình nó đủ để chặn.)
    //
    // Muốn nối vào sau thì đổi seeder sang ngữ nghĩa create-only trước, kèm bài
    // chứng minh nó không mutate hàng có sẵn.
    'TillTenderTypeSeeder',

    // CHƯA CÓ RULING, không phải "đã chốt là đứng ngoài". Cả hai idempotent và
    // đều là danh mục hệ thống thuần (IamSeeder: 5 vai global + 33 quyền,
    // docblock tự khai idempotent). Chúng KHÔNG bị thiếu ở production hôm nay —
    // vai vẫn phân giải được — nên #2777 không đo được nhu cầu, và thêm một thứ
    // vào đường ghi-production khi chưa đo được nhu cầu là đúng thói quen đã đẻ
    // ra chính issue này. Ứng viên thật; cần người chốt.
    'IamSeeder',
    'PlatformDirectorySeeder',
];

/** @return list<string> seeder khai trong nhánh `isProduction()` của DatabaseSeeder */
function productionSeederList(): array
{
    $src = File::get(database_path('seeders/DatabaseSeeder.php'));

    $start = strpos($src, 'if (app()->isProduction())');
    expect($start)->not->toBeFalse('DatabaseSeeder không còn nhánh isProduction() — đọc lại rào này');

    $block = substr($src, $start);
    $block = substr($block, 0, strpos($block, 'return;'));

    preg_match_all('/^\s*(\w+)::class,/m', $block, $m);

    return $m[1];
}

/** @return list<string> seeder mà workflow deploy gọi ĐÍCH DANH */
function deploySeededClasses(): array
{
    $wf = File::get(base_path('../.github/workflows/deploy-xserver.yml'));
    preg_match_all('/db:seed --class=(\w+)/', $wf, $m);

    return array_values(array_unique($m[1]));
}

/**
 * Đóng bao truyền ngôi: seeder nào tới được từ các entry point của workflow.
 *
 * Phải là đóng bao chứ không phải so danh sách phẳng — `PaymentMethodSeeder` và
 * `PaymentGatewayCatalogSeeder` KHÔNG được workflow gọi đích danh, nhưng
 * `BetoyaSeeder` gọi chúng, nên chúng vẫn chạy. Rào so phẳng sẽ đòi thêm hai
 * dòng seed thừa vào đường ghi production.
 *
 * @return list<string>
 */
function seedersReachableFromDeploy(): array
{
    $reach = deploySeededClasses();
    $stack = $reach;

    while ($stack !== []) {
        $name = array_pop($stack);
        $file = database_path("seeders/{$name}.php");
        if (! File::exists($file)) {
            continue;
        }

        preg_match_all('/\$this->call\(\s*(\w+)::class/', File::get($file), $direct);
        preg_match_all('/^\s*(\w+)::class,/m', File::get($file), $listed);

        foreach ([...$direct[1], ...$listed[1]] as $callee) {
            if (! in_array($callee, $reach, true)) {
                $reach[] = $callee;
                $stack[] = $callee;
            }
        }
    }

    return $reach;
}

it('mọi seeder trong isProduction() đều tới được đường deploy', function () {
    $missing = array_values(array_diff(
        productionSeederList(),
        seedersReachableFromDeploy(),
        DELIBERATELY_OFF_DEPLOY,
    ));

    expect($missing)->toBe([],
        'Seeder nằm trong danh sách `isProduction()` nhưng đường deploy KHÔNG BAO GIỜ chạy: '
        .implode(', ', $missing)."\n\n"
        .'Đường deploy không gọi `DatabaseSeeder` — nó gọi đích danh từng `--class=`. '
        .'Thêm dòng `db:seed --class=<Seeder>` vào `.github/workflows/deploy-xserver.yml`, '
        .'HOẶC khai vào `DELIBERATELY_OFF_DEPLOY` kèm lý do ĐO ĐƯỢC. '
        .'Bỏ qua thì bản vá của bạn nằm trong một danh sách không ai đọc — đúng #2451.');
});

it('danh sách miễn trừ không mục nào mục nát', function () {
    // Rào phải biết IM, và phần "biết im" chỉ đáng tin khi chính nó được canh:
    // một mục miễn trừ trỏ tới seeder đã bị xoá/đổi tên sẽ âm thầm nới rào ra.
    foreach (DELIBERATELY_OFF_DEPLOY as $name) {
        expect(File::exists(database_path("seeders/{$name}.php")))->toBeTrue(
            "`{$name}` nằm trong DELIBERATELY_OFF_DEPLOY nhưng file seeder không còn — gỡ entry, đừng để nó mục");

        // `toContain` là matcher BIẾN THIÊN — truyền message vào đó thì nó thành
        // needle thứ hai và bài đỏ với câu "mảng không chứa <chính message>".
        // Đây là bẫy `policyDocs.test` ghi sẵn, và nó vừa cắn thật.
        expect(in_array($name, productionSeederList(), true))->toBeTrue(
            "`{$name}` được miễn trừ khỏi đường deploy nhưng KHÔNG còn trong danh sách "
            .'`isProduction()` — miễn trừ một thứ không ai đòi là rào nói về quá khứ');
    }
});

it('seeder trong danh sách miễn trừ KHÔNG được lẻn vào workflow', function () {
    // Bài này sinh ra từ chính lượt kiểm đột biến vòng 2: nối lại
    // `TillTenderTypeSeeder` vào workflow mà cả 4 bài vẫn XANH. Tức danh sách
    // miễn trừ chỉ là một lời khuyên — nó nói "đừng nối cái này" rồi không làm
    // gì khi có người nối.
    //
    // Bài 4 không bắt được vì nó quét chuỗi `ShopOrderSetting|…`, mà mối nguy
    // của tender type mang HÌNH DẠNG KHÁC: `$service->update()` với
    // `'is_active' => true` trên một bảng org sửa được. Rào quét-chuỗi chỉ thấy
    // được mối nguy nó biết trước; danh sách miễn trừ là chỗ ghi những mối nguy
    // đã có người ĐO. Cưỡng chế chính nó là cách rẻ nhất để nó không mục.
    $leaked = array_values(array_intersect(DELIBERATELY_OFF_DEPLOY, deploySeededClasses()));

    expect($leaked)->toBe([],
        'Seeder nằm trong DELIBERATELY_OFF_DEPLOY nhưng workflow deploy VẪN gọi: '
        .implode(', ', $leaked)."\n\n"
        .'Mỗi mục trong danh sách đó đứng ngoài vì một lý do ĐO ĐƯỢC (ghi ngay cạnh nó). '
        .'Muốn nối vào thì xoá mục khỏi danh sách VÀ nói rõ lý do cũ đã hết hiệu lực '
        .'— đừng thêm một dòng seed rồi để lời giải thích cũ nằm lại nói ngược.');
});

it('audience chạy TRƯỚC rule trên đường deploy', function () {
    // Thứ tự không phải chuyện gọn gàng: `SystemNotificationRuleSeeder` trỏ
    // audience bằng `audience_name`, nên chạy trước audience thì rule dựng hụt
    // và KHÔNG kêu — đúng lớp lỗi "slug sai phân giải ra 0 người nhận".
    $order = deploySeededClasses();

    $audience = array_search('SystemNotificationAudienceSeeder', $order, true);
    $rule = array_search('SystemNotificationRuleSeeder', $order, true);

    expect($audience)->not->toBeFalse('audience seeder không có trên đường deploy');
    expect($rule)->not->toBeFalse('rule seeder không có trên đường deploy');
    expect($audience)->toBeLessThan($rule,
        'audience PHẢI đứng trước rule trong workflow — rule trỏ audience bằng `audience_name`');
});

it('không seeder nào trên đường deploy chạm state QUÁN SỞ HỮU', function () {
    // CLAUDE.md: menu nào bật · shop_order_settings · bàn/khu — từ lúc quán chạm
    // vào được, deploy tái áp một giá trị là âm thầm cướp mất lựa chọn của họ.
    // Ngày 2026-08-11 việc này tốn hai lần dịch vụ thật.
    //
    // Quét MỌI seeder workflow gọi đích danh, KHÔNG phải một danh sách tên chép
    // tay. Bản đầu chép 5 tên, và review chỉ ra nó vô nghĩa theo đúng kiểu tên
    // bài hứa nhiều hơn nó làm: thêm `HongoShopConfigSeeder` vào workflow thì
    // cả 324 arch test vẫn XANH — rào chỉ canh chiều "isProduction ⊆ deploy",
    // không canh chiều "deploy ⊆ được-phép". Mà chiều thứ hai mới là chiều một
    // dòng seed mới đi vào.
    //
    // Hai entry point Betoya đứng ngoài: chúng CỐ Ý mang ảnh chụp Betoya và đã
    // có rào riêng khoá cây gọi (`BetoyaSeederLeavesShopOwnedStateAloneTest`),
    // nên gộp vào đây là đỏ oan.
    $betoya = ['BetoyaSeeder', 'BetoyaProductionSeeder'];
    $named = array_values(array_diff(deploySeededClasses(), $betoya));

    expect($named)->not->toBe([], 'không đọc được seeder nào từ workflow — đọc lại rào này');

    $shopOwned = '/ShopOrderSetting|shop_order_settings|MenuSchedule|current_order_id/';

    foreach ($named as $name) {
        $file = database_path("seeders/{$name}.php");
        expect(File::exists($file))->toBeTrue(
            "workflow gọi `db:seed --class={$name}` nhưng seeder đó không tồn tại");

        expect(preg_match($shopOwned, File::get($file)))->toBe(0,
            "`{$name}` chạm state quán sở hữu — nó KHÔNG được nằm trên đường deploy "
            .'không người trông (CLAUDE.md §"deploy được seed DANH MỤC HỆ THỐNG, '
            .'KHÔNG được quyết hộ quán")');
    }
});
