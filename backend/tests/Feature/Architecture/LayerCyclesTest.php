<?php

declare(strict_types=1);

/**
 * #1560 (epic #962) — chu trình giữa các layer, đo được và ghim được.
 *
 * ADR 0001 § 2 đòi đồ thị phụ thuộc phi chu trình. Cho tới bài test này, đó là
 * MỘT CÂU TRONG TÀI LIỆU, không phải một ràng buộc: Deptrac không phát hiện chu
 * trình, và bộ đo SCC tự viết đã bị bỏ cùng bánh cóc cũ ở #1532.
 *
 * Kết quả đầu tiên trả lời luôn câu hỏi tài liệu đang treo — và nó XẤU HƠN cái
 * tài liệu đang ghi. Ghi chú cũ nói "cả CHÍN MODULE nằm trong một SCC". Đo lại
 * bằng dụng cụ đã sửa: **MỘT SCC gồm 12 node**, vì `SharedKernel`,
 * `TenancyKernel` và `Composition` cũng nằm trong đó.
 *
 * Điều đó nặng hơn nợ module↔module. `SharedKernel: ~` trong ruleset nghĩa là hạ
 * tầng dùng chung KHÔNG được phụ thuộc vào gì cả; thực tế nó phát cạnh xuống
 * module. Mỗi cạnh như vậy khép một chu trình hai bước với cạnh ngược vốn được
 * phép (`Inventory → SharedKernel`). Nói cách khác: **đáy của đồ thị không sạch**,
 * nên mọi phép đo phía trên đều đứng trên cát.
 *
 * **#1561 đã trả xong món này.** Con số "73 cạnh" từng ghi ở đây là của lần đo
 * #1560 và đã lạc hậu từ lâu — đo lại ngay trước khi sửa thì SharedKernel chỉ
 * còn **8 vi phạm cấp class** (`--report-skipped`), gom thành **3 cạnh cấp
 * layer**, phát ra từ đúng **2 class**, cả hai đều là trait trong
 * `App\Models\Concerns\`:
 *
 *   | class phát cạnh                   | tới                     | layer đích          |
 *   |-----------------------------------|-------------------------|---------------------|
 *   | `Concerns\HasScopedRoles`         | `Organization`, `Role`  | TenancyKernel, PlatformIntegration |
 *   | `Concerns\ReceivesNotifications`  | `NotificationRecipient` | Notifications       |
 *
 * `HasScopedRoles` có ĐÚNG MỘT consumer (`User`) nên nó về thẳng chủ sở hữu;
 * `ReceivesNotifications` có ba (`User`/`Customer`/`Device` — ba layer khác
 * nhau) nên phần KHAI BÁO quan hệ về từng model, còn định nghĩa "chưa đọc" ở
 * lại trait. Cả hai đều là *bỏ* cạnh, không phải *giấu*: mọi cạnh còn lại đều
 * đã có sẵn trong `deptrac-baseline.yaml` dưới tên model, không sinh vi phạm
 * mới. SharedKernel giờ có **0 cạnh ra** ⇒ rơi khỏi SCC (13 → 12 node,
 * 41 → 38 cạnh vi phạm khép chu trình).
 *
 * Đây là BÁNH CÓC, không phải lệnh cấm: cấm chu trình ngay lập tức thì chỉ có
 * một cách để CI xanh là tắt cổng — đúng bài học #1532. Nên: kích thước SCC lớn
 * nhất chỉ được GIẢM, và số SCC không được TĂNG.
 *
 * ## Đọc `deptrac-baseline.yaml` cho đúng: KHÔNG phải mọi entry đều là NỢ
 *
 * Baseline hiện có **23 entry**, và **14 trong số đó sẽ không bao giờ về 0** —
 * chúng là toàn bộ nhóm `App\Models\* → App\Models\*`:
 *
 *   Allergen→Material · BranchReview→File · Brand→Category · Brand→Product
 *   Customer→NotificationRecipient · CustomerOrder→Table
 *   Device→NotificationRecipient · Product→File
 *   ProductSku→File · Table→TableStatusChange · TableSession→Table
 *   User→NotificationRecipient · User→Role
 *
 * Phán quyết chủ repo 2026-08-02 (**không tách DB**): khoá ngoại xuyên module
 * trong DB là HỢP LỆ, và quan hệ Eloquent khai theo đúng khoá ngoại đó **không
 * phải nợ**. Xoá một quan hệ như vậy không gỡ được coupling nào — nó chỉ giấu
 * coupling đi và làm hỏng eager loading ở mọi chỗ đang `with(...)`. Regen của
 * Omnify cũng dựng lại chúng.
 *
 * Chúng nằm trong baseline vì Deptrac vẫn NHÌN THẤY chúng; đó là **miễn trừ
 * vĩnh viễn**, không phải việc còn phải làm. Đếm chúng vào "còn N chỗ phải sửa"
 * là thổi phồng phần việc còn lại hơn 60%, và đã xảy ra thật hơn một lần trong
 * epic này.
 *
 * ⇒ Nợ THẬT còn lại là **7 cạnh service** (9 trước #1731), và mỗi cạnh có lý do
 * viết tại chính file phát ra nó. Ai muốn kéo con số xuống: đọc lý do trước, vì
 * bốn trong bảy cạnh là **cố ý giữ** và gỡ chúng là gian lận phép đo, không phải
 * tiến bộ.
 *
 * #1731 trả hai cạnh `StockDeductionService → CustomerOrderItem|Recipe` — cặp
 * cuối cùng trong danh sách còn trả được. Đáng ghi lại vì nó cho thấy cách đọc
 * ba ngưỡng dưới đây: hai cạnh class ấy làm SCC lớn nhất **9 → 6** và cạnh vi
 * phạm **12 → 6**, trong khi bốn đợt trước trả nhiều cạnh hơn mà hai số đó đứng
 * im. Cạnh CUỐI CÙNG của một hướng mới là cạnh có giá.
 */

use App\Architecture\LayerCycleDetector;
use Symfony\Component\Process\Process;

/**
 * SCC lớn nhất đo 2026-08-03: **12** node — 9 module + Composition +
 * `TenancyKernel` + `PublishedContracts`. `SharedKernel` KHÔNG còn trong đó
 * (#1561); xem bài C3 bên dưới.
 *
 * #1596 nâng con số này từ 12 lên 13, và đó là lần duy nhất trong epic một
 * ngưỡng đi LÊN. Lý do phải đọc kèm số ở dưới:
 *
 *   |                       | node | cạnh trong SCC | VI PHẠM khép chu trình |
 *   |-----------------------|-----:|---------------:|-----------------------:|
 *   | trước `PublishedContracts` |   12 |             80 |                 **50** |
 *   | sau                        |   13 |             89 |                 **47** |
 *   | sau #1561 (SharedKernel ra)|   12 |             — |                 **38** |
 *
 * Đồ thị thêm một NODE vì có thêm một layer, nhưng số cạnh VI PHẠM khép chu
 * trình GIẢM. Nợ co lại; chỉ có số đỉnh tăng.
 *
 * Đó cũng là lý do `SCC_VIOLATING_EDGE_BUDGET` ra đời: đếm node đo được "đồ thị
 * to cỡ nào", không đo được "nợ bao nhiêu", nên thêm một layer hợp lệ cũng làm
 * nó đỏ. Ngưỡng dưới mới là cái đo nợ thật, và nó CHỈ ĐƯỢC GIẢM.
 *
 * **13 → 12 (#962): `Composition` ĐÃ RA KHỎI chu trình.** Cả 12 node còn lại đều
 * là module/kernel; gốc lắp ráp không còn nằm trong vòng nào. Nó vào được SCC chỉ
 * nhờ ĐÚNG MỘT cạnh — `RealtimeChannel` (Notifications) inject thẳng
 * `App\Broadcasting\BrandAwareBroadcastManager` — và cạnh đó là loại nguy hiểm
 * nhất trong bản đồ: Composition được phép biết MỌI module, nên một cạnh đi
 * ngược lên nó nối mọi thứ với mọi thứ. Giờ `RealtimeChannel` hỏi qua
 * `App\Services\Notification\Contracts\BrandEventBroadcaster` (Notifications
 * khai, Composition hiện thực).
 *
 * Ngưỡng này KHÔNG được nâng lại lên 13 mà không nêu rõ cạnh nào vừa kéo
 * Composition vào lại — đó luôn là một hồi quy, không phải "đồ thị to ra".

 * ⬇️ **6 → 5 ở #2376.** Không phải trả nợ có chủ đích: omnify 6.0.1 phát đúng
 * tên bảng/cột pivot, nên hai relation `belongsToMany` khai tay ở
 * `App\Models\Allergen` và `App\Models\MenuSection` — vốn chỉ tồn tại để
 * vá lỗi generator — được gỡ, và import của chúng biến mất cùng. Đồ thị nhỏ
 * lại là HỆ QUẢ. Ratchet bắt hạ ngưỡng, đúng như thiết kế.
 */
const LARGEST_CYCLE_BUDGET = 5;

/**
 * Số cạnh VI PHẠM nằm trong SCC lớn nhất — tức những cạnh mà cắt đi thì chu
 * trình vỡ. Đo 2026-08-03: **40** (#1596 đặt 47 → #1591 hạ 46 → #1552 hạ 42 khi
 * đưa `App\Services\Workstation` + `App\Services\Payment\Observation` sang
 * Composition → 41). **CHỈ ĐƯỢC GIẢM.**
 *
 * #962 hạ xuống con số hiện tại qua bốn đợt, mỗi đợt vỡ được đúng một cạnh của
 * đồ thị LỚP dù trả nhiều cạnh class:
 *
 *  · `Catalog → Notifications` về **0** — nó chỉ có MỘT cạnh (`RecipeService`
 *    hỏi `NotificationRule::hasActiveCoverage()`), giờ đi qua
 *    `NotificationDispatcher::coversEmitter()`. Bốn cạnh cùng đợt
 *    (`Inventory → Ordering` ×3, `Inventory → Catalog` ×1) KHÔNG hạ được số này
 *    vì mỗi hướng vẫn còn cạnh khác: `StockDeductionService` vẫn cầm
 *    `CustomerOrderItem` và `Recipe`. (#1605 trả nốt `CustomerOrder` qua
 *    `OrderStockContext` và con số này KHÔNG đổi — đúng minh hoạ cho đoạn dưới:
 *    trả n cạnh class không có nghĩa là vỡ được một cạnh LỚP.)
 *
 *    **#1731 trả nốt hai cạnh đó** (`OrderStockLineReads` + `SkuSnapshot
 *    .inventoryMode`) và LẦN NÀY cả hai cạnh LỚP cùng vỡ: 12 → **6**. Đó cũng là
 *    minh hoạ ngược cho chính câu trên — bốn đợt trước trả nhiều cạnh class mà
 *    số này đứng im, vì mỗi hướng còn sót MỘT cạnh. Cạnh cuối cùng của một
 *    hướng mới là cạnh có giá.
 *  · Tám cạnh `App\Services\Customer\*` qua hợp đồng công bố
 *    (`BranchDefaultTaxType`, `BranchSplitBillPolicy`, `BranchOpeningWindow`,
 *    `OrderQueryPort`, `TableOccupancy`). Chỉ hai trong số đó nằm trong SCC.
 *  · `Ordering → Pricing` (`TaxResolver`) qua `OrderLineTaxPricing` — nút thắt
 *    thuế của đường ghi đơn.
 *
 * Đó chính là lý do ngưỡng này tách khỏi tổng số vi phạm: trả năm cạnh class có
 * thể chỉ vỡ được một cạnh lớp, và ngược lại.
 *  · `Brand` thôi khai bốn quan hệ NGHỊCH không ai gọi (`menus`, `materials`,
 *    `recipes`, `orderPolicy`) — TenancyKernel là mỏ neo MỘT CHIỀU: con khai
 *    `brand_id` trỏ LÊN, cha không được khai `hasMany` trỏ XUỐNG. Hai cạnh
 *    lớp rời SCC: TenancyKernel → Inventory và TenancyKernel → Organization.
 *
 * #962 hạ 38 → 36 (gói "module nhỏ còn sót"): sáu cạnh trả, hai trong số đó nằm
 * trong SCC lớn nhất — `Ordering → PlatformIntegration` (`KdsBusinessRules` thôi
 * cầm `App\Models\Device`, nhận `ActingDeviceTenancy`) và `Pricing → Ordering`
 * (`MenuPromotionService` đọc qua `PromotionRedemptionReads`). Bốn cạnh còn lại
 * — hai Observer đảo về provider, và `Notifications → Composition` — hạ baseline
 * hoặc rút hẳn một NODE khỏi SCC, nhưng không phải là cạnh vi phạm trong SCC 12
 * node nên không trừ vào con số này. Lại một lần nữa: trả n cạnh không có nghĩa
 * là con số này giảm n.
 *
 * Đây là ngưỡng chống được thứ mà đếm node không chống được: thêm một layer
 * hợp lệ làm node tăng nhưng không thêm nợ, còn một cạnh module→module mới thì
 * làm số này tăng dù đồ thị vẫn đúng 12 đỉnh.

 * ⬇️ **6 → 5 ở #2376**, cùng nguyên nhân với `LARGEST_CYCLE_BUDGET` ngay trên.
 */
const SCC_VIOLATING_EDGE_BUDGET = 5;

/**
 * Số thành phần có chu trình. KHÔNG ĐƯỢC TĂNG — **trừ khi kèm bằng chứng VỠ**.
 *
 * 1 → 2 ở #1731, và đây là ca duy nhất tới giờ mà con số này tăng lên mà KHÔNG
 * phải hồi quy. `StockDeductionService` thôi cầm `CustomerOrderItem` + `Recipe`
 * ⇒ mất cạnh `Inventory → Ordering` và `Inventory → Catalog`, và chính hai cạnh
 * đó là thứ khâu hai cụm vốn rời nhau vào làm một:
 *
 *   trước: [9] Catalog ↔ Inventory ↔ Notifications ↔ Ordering ↔ Organization
 *              ↔ PlatformIntegration ↔ PublishedContracts ↔ TenancyKernel …
 *   sau:   [6] Catalog ↔ Inventory ↔ Notifications ↔ PlatformIntegration
 *              ↔ PublishedContracts ↔ TenancyKernel
 *          [2] Ordering ↔ Organization
 *
 * Cụm `Ordering ↔ Organization` KHÔNG mới — nó vốn nằm chìm trong khối 9 node.
 * Cùng lúc đó SCC lớn nhất **9 → 6** và cạnh vi phạm **12 → 6**.
 *
 * Nói cách khác: đếm CỤM một mình đọc ngược sự thật khi một khối vỡ ra. Đó là lý
 * do ba ngưỡng này phải đọc CÙNG NHAU, và là lý do luật ở đây không phải "không
 * bao giờ tăng" mà là **tăng thì phải chỉ ra khối nào vừa vỡ và hai số kia giảm
 * bao nhiêu**. Tăng mà hai số kia đứng yên (hoặc tăng) thì luôn là hồi quy.
 */
const CYCLE_COUNT_BUDGET = 2;

function layerCycleDetector(): LayerCycleDetector
{
    static $detector = null;
    if ($detector instanceof LayerCycleDetector) {
        return $detector;
    }

    $out = tempnam(sys_get_temp_dir(), 'deptrac-cycles-').'.json';

    // `--report-skipped` là BẮT BUỘC. Thiếu nó, baseline che toàn bộ 805 vi phạm,
    // JSON ra rỗng, và bài test này sẽ báo "không có chu trình nào" — xanh, và sai.
    $process = new Process(
        ['vendor/bin/deptrac', 'analyse', '--no-progress', '--report-skipped', '--formatter=json', '--output='.$out],
        base_path(),
        null,
        null,
        300.0,
    );
    $process->run();

    expect(file_exists($out))->toBeTrue(
        "deptrac không sinh được báo cáo JSON.\n".$process->getErrorOutput()."\n".$process->getOutput(),
    );

    $json = (string) file_get_contents($out);
    @unlink($out);

    // Rào chống chính bài test này tự nói dối: JSON rỗng nghĩa là cờ sai hoặc
    // deptrac đổi định dạng, KHÔNG phải "đồ thị đã sạch".
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    expect($decoded['Report']['Skipped violations'] ?? 0)->toBeGreaterThan(0,
        'Báo cáo không có vi phạm bị bỏ qua nào — gần như chắc chắn thiếu '.
        '`--report-skipped`, chứ không phải nợ đã được trả hết. Kiểm trước khi mừng.',
    );

    return $detector = new LayerCycleDetector($json, base_path('deptrac.yaml'));
}

it('C1: số thành phần có chu trình không được TĂNG', function () {
    $cycles = layerCycleDetector()->cycles();

    expect(count($cycles))->toBeLessThanOrEqual(CYCLE_COUNT_BUDGET, sprintf(
        "Có %d cụm chu trình (ngân sách %d):\n%s",
        count($cycles),
        CYCLE_COUNT_BUDGET,
        implode("\n", array_map(
            static fn (array $c): string => '  ['.count($c).'] '.implode(' ↔ ', $c),
            $cycles,
        )),
    ));
});

it('C2: SCC lớn nhất chỉ được CO LẠI — và nói rõ cắt cạnh nào thì phá được', function () {
    $detector = layerCycleDetector();
    $cycles = $detector->cycles();

    $largest = $cycles === [] ? 0 : count($cycles[0]);

    if ($largest > LARGEST_CYCLE_BUDGET) {
        $edges = $detector->edgesWithin($cycles[0]);
        $violating = array_values(array_filter($edges, static fn (array $e): bool => $e['kind'] === 'vi phạm'));

        $this->fail(sprintf(
            "SCC lớn nhất %d node (ngân sách %d): %s\n\n".
            "Cạnh VI PHẠM khép chu trình (%d) — cắt ở đây thì SCC vỡ ra:\n%s",
            $largest,
            LARGEST_CYCLE_BUDGET,
            implode(', ', $cycles[0]),
            count($violating),
            implode("\n", array_map(
                static fn (array $e): string => "  {$e['from']} → {$e['to']}",
                array_slice($violating, 0, 30),
            )),
        ));
    }

    expect($largest)->toBeGreaterThanOrEqual(LARGEST_CYCLE_BUDGET, sprintf(
        "TIN TỐT — SCC lớn nhất còn %d node, ngân sách vẫn ghi %d.\n".
        'Hạ ngân sách xuống, nếu không lần phình sau đi lọt vào phần chênh.',
        $largest,
        LARGEST_CYCLE_BUDGET,
    ));
});

it('C2b: số cạnh VI PHẠM khép chu trình chỉ được CO LẠI', function () {
    /*
     * Tách khỏi C2 vì nó đo thứ khác. C2 đếm ĐỈNH — nên #1596 thêm một layer
     * hợp lệ là nó đỏ, dù nợ vừa giảm. Bài này đếm CẠNH VI PHẠM trong SCC, tức
     * đúng những cạnh phải cắt để phá chu trình.
     */
    $detector = layerCycleDetector();
    $cycles = $detector->cycles();
    $edges = $cycles === [] ? [] : $detector->edgesWithin($cycles[0]);
    $violating = array_values(array_filter($edges, static fn (array $e): bool => $e['kind'] === 'vi phạm'));

    expect(count($violating))->toBeLessThanOrEqual(SCC_VIOLATING_EDGE_BUDGET, sprintf(
        "%d cạnh vi phạm khép chu trình (ngân sách %d).\n".
        "Một cạnh module→module mới vừa được thêm vào, hoặc baseline vừa phình.\n%s",
        count($violating),
        SCC_VIOLATING_EDGE_BUDGET,
        implode("\n", array_map(
            static fn (array $e): string => "  {$e['from']} → {$e['to']}",
            array_slice($violating, 0, 20),
        )),
    ));

    expect(count($violating))->toBeGreaterThanOrEqual(SCC_VIOLATING_EDGE_BUDGET, sprintf(
        'TIN TỐT — còn %d cạnh vi phạm khép chu trình, ngân sách vẫn ghi %d. Hạ xuống.',
        count($violating),
        SCC_VIOLATING_EDGE_BUDGET,
    ));
});

it('C3: SharedKernel nằm NGOÀI mọi chu trình — đáy đồ thị đã sạch, giữ nó sạch', function () {
    /*
     * Bài này từng khẳng định điều NGƯỢC LẠI: nó ghim tình trạng xấu
     * ("SharedKernel nằm trong chu trình") để không ai quên món nợ nền móng, và
     * nói thẳng rằng khi #1561 xong thì nó phải được đảo. #1561 xong rồi, nên
     * đây là chiều đảo.
     *
     * Tại sao "không nằm trong chu trình" là cách phát biểu đủ mạnh, chứ không
     * phải một phép đo yếu hơn "SharedKernel không có cạnh ra": ruleset cho
     * PHÉP mọi layer → SharedKernel. Nên một cạnh ra DUY NHẤT từ SharedKernel
     * tới bất kỳ layer nào được deptrac phủ cũng khép ngay một chu trình hai
     * bước. Hai mệnh đề tương đương, và mệnh đề này đọc được từ chính dụng cụ
     * đang dùng cho C1/C2 — không dựng bộ đo thứ hai.
     *
     * Đỏ ở đây nghĩa là có class mới trong `App\Support\`, `App\Concerns\`,
     * `App\Traits\`, `App\Models\Concerns\`, `App\Rules\`, `App\Exceptions\`…
     * (xem collector SharedKernel trong `deptrac.yaml`) vừa gọi tên một model
     * hoặc service của module. Cách sửa KHÔNG phải là thêm nó vào baseline:
     * chuyển class về module sở hữu, hoặc để nó nhận id/kiểu nguyên thuỷ thay
     * vì model, hoặc đẩy phần khai báo quan hệ về từng model — ba nước đi
     * #1561 đã dùng.
     */
    $cycles = layerCycleDetector()->cycles();

    $offenders = array_values(array_filter(
        $cycles,
        static fn (array $c): bool => in_array('SharedKernel', $c, true),
    ));

    expect($offenders)->toBe([], sprintf(
        "SharedKernel quay lại trong chu trình: %s\n".
        'Hạ tầng dùng chung vừa gọi tên một module. Xem docblock ở trên.',
        $offenders === [] ? '' : implode(', ', $offenders[0]),
    ));
});
