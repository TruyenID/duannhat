<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Emit `deptrac.yaml` from `config/modules.php` (#1532, epic #962).
 *
 * Why generate instead of hand-writing the Deptrac config: the module map has
 * 9 modules, 181 models and 27 namespaces. Hand-copying that into a second file
 * makes two sources of truth, and the copy drifts silently — a model added to
 * `config/modules.php` would keep passing Deptrac while belonging to nothing.
 * `config/modules.php` stays the single source; this command projects it.
 *
 * `DeptracConfigInSyncTest` re-runs this and fails if the committed YAML differs,
 * so the generated file cannot rot.
 *
 * The layering follows the ownership rules the previous hand-rolled graph used
 * (`App\Architecture\ModuleGraph::owner`), so the numbers before and after the
 * migration are comparable rather than two unrelated measurements:
 *
 *   1. technical shared code    → SharedKernel   (everyone may depend on it)
 *   2. tenancy anchors          → TenancyKernel  (everyone may depend on it)
 *   3. a module's namespaces    → that module
 *   4. a model name, and every generated/derived class carrying that name as
 *      its prefix (Omnify module, policy, observer, resource, request…)
 *                               → the module owning that model
 */
final class DeptracConfigCommand extends Command
{
    protected $signature = 'architecture:deptrac-config
                            {--check : exit non-zero when the committed file is stale}';

    protected $description = 'Sinh deptrac.yaml từ config/modules.php (#962) — một nguồn chân lý cho ranh giới module';

    /**
     * The tenancy anchors: every module must know which org/brand/branch a row
     * belongs to, so depending on them is DESIGN, not debt.
     *
     * Chosen from measurement, not intuition — external files importing each:
     *   Branch 146 · Brand 129 · Organization 126 · ShopOrderSetting 38 · Table 31
     *
     * The line is drawn under the top three. `ShopOrderSetting` and `Table` are
     * deliberately NOT kernel: they are read across modules today, but that is
     * real debt to route through a contract, not a fact of tenancy.
     *
     * `BranchTranslation` joins them with 0 external imports because it is
     * Branch's translation row — splitting a model from its translations would
     * be a boundary nobody can honour.
     *
     * @var list<string>
     */
    private const TENANCY_KERNEL = [
        'Organization', 'Brand', 'Branch', 'BranchTranslation',
        // #1537 — `User` joins by the SAME measured criterion, not by convenience:
        // files outside the owning module that import it are Branch 114, User 108,
        // Brand 96, Organization 55. It sits between two classes already accepted
        // as kernel, and it is the same KIND of thing — an identity anchor every
        // module must resolve (who did this) exactly as it must resolve where.
        //
        // Deliberately ONLY the model. Iam / Sso / Device / Peripheral stay out:
        // they are services and infrastructure and must go through a port (see
        // BranchManagementProjectionSource, ProductCatalogProjectionPort). Device
        // measures 27 and UserWorkspaceAccess 4 — an order of magnitude below the
        // line, and pulling the whole cluster in would turn the kernel into a bin
        // where anything inconvenient gets dropped.
        'User',
    ];

    /**
     * Composition root + adapters: they wire modules together by definition, so
     * depending on everything is their JOB, not a violation.
     *
     * `config/modules.php` lumps providers and middleware into `shared`, which
     * is wrong for a boundary check: a service provider binds every module's
     * services, so as "shared code" it produced 100+ false violations on the
     * first run. Split out here with the reason, rather than silently editing
     * the manifest another consumer reads.
     *
     * Controllers/Requests/Resources are adapters at the edge — the previous
     * hand-rolled graph excluded them too ("surface là adapter, đo riêng"), so
     * excluding them keeps the two measurements comparable.
     *
     * @var list<string>
     */
    private const COMPOSITION = [
        'App\\Providers',
        'App\\Console',
        'App\\Http',
        'App\\Broadcasting',
        'App\\Mcp',
        'App\\OpenApi',
        // #1591 — DASHBOARD. Đây là mục `App\Services\*` ĐẦU TIÊN trong danh
        // sách này, nên nó cần lý do mạnh hơn "nghe cũng hợp lý".
        //
        // Đo được, hai tính chất:
        //   · **0 cạnh VÀO** — không module nào phụ thuộc nó. Consumer duy nhất
        //     là hai controller, mà controller vốn đã là Composition.
        //   · **KHÔNG GHI GÌ** — không `save`/`update`/`create`/`delete` nào
        //     trong 821 dòng. Nó không sở hữu bảng, không có bất biến để giữ.
        //
        // Nó nối orders + products + tax breakdown để vẽ đúng hai màn hình. Đó
        // là định nghĩa của "wiring modules together for a delivery surface" —
        // cùng loại việc với controller, chỉ là không nằm trong `App\Http`.
        //
        // Hai tính chất trên KHÔNG phải lời hứa: `CompositionMembershipTest`
        // cưỡng chế chúng cho mọi mục `App\Services\*` trong danh sách này.
        // Thêm một service có người-trong-module gọi, hoặc có một dòng ghi, là
        // ĐỎ ngay — nên chỗ này không thành cái thùng chứa đồ bất tiện.
        'App\\Services\\Dashboard',
        'App\\Services\\Workstation',
        'App\\Services\\Payment\\Observation',
    ];

    public function handle(): int
    {
        $manifest = config('modules');
        $yaml = $this->render($manifest);
        $path = base_path('deptrac.yaml');

        if ($this->option('check')) {
            $current = is_file($path) ? file_get_contents($path) : '';
            if ($current !== $yaml) {
                $this->error('deptrac.yaml lệch với config/modules.php — chạy `php artisan architecture:deptrac-config`');

                return self::FAILURE;
            }
            $this->info('deptrac.yaml khớp config/modules.php');

            return self::SUCCESS;
        }

        file_put_contents($path, $yaml);
        $this->info("Đã ghi {$path}");

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $manifest */
    private function render(array $manifest): string
    {
        $modules = $manifest['modules'];
        // Drop anything that is really composition/adapter, see self::COMPOSITION.
        $shared = array_values(array_filter(
            $manifest['shared'] ?? [],
            static fn (string $ns): bool => ! array_filter(
                self::COMPOSITION,
                static fn (string $c): bool => $ns === $c || str_starts_with($ns, $c.'\\'),
            ),
        ));

        // Mọi class được khai chủ sở hữu TƯỜNG MINH. Chúng phải bị loại khỏi
        // collector theo namespace của module khác, nếu không một class sẽ nằm
        // trong hai layer cùng lúc — Deptrac cảnh báo, và phép đo hỏng đúng như
        // #1539 (`Customer` nuốt `CustomerOrder`, 21 warning bị bỏ qua).
        $claimed = [];
        foreach ($modules as $spec) {
            foreach ($spec['classes'] ?? [] as $fqn) {
                $claimed[] = $fqn;
            }
        }
        // Chỉ phần do MODULE nhận — dùng để trừ khỏi collector của SharedKernel
        // (#1561). Không gộp `shared_classes` vào đây, nếu không SharedKernel sẽ
        // tự trừ mất chính class nó vừa khai.
        $moduleClaimed = $claimed;
        // #1596 — HỢP ĐỒNG CÔNG BỐ. Đo được: 19/597 vi phạm còn lại trỏ vào một
        // class `\Contracts\` — tức việc ĐÃ LÀM ĐÚNG vẫn bị tính là nợ, và mỗi
        // cổng epic này dựng lên lại cộng thêm vào chính con số nó đang kéo xuống.
        // Kiểm bằng thực nghiệm rẻ nhất có thể: cho `ProductSkuService` đọc
        // `OrderStatusVocabulary::OPEN` thay vì `CustomerOrder::OPEN_STATUSES`
        // → 597 y nguyên, chỉ đổi hình dạng cạnh.
        //
        // Danh sách là TƯỜNG MINH, không quét theo thư mục: "để vào folder
        // Contracts là hết bị soi" biến cái rào thành thủ tục. Muốn công bố thì
        // phải khai, và khai là một hành động có người chịu trách nhiệm.
        $publishedContracts = $manifest['published_contracts'] ?? [];
        foreach ($publishedContracts as $fqn) {
            $claimed[] = $fqn;
            $moduleClaimed[] = $fqn;
        }
        // #1583 — công bố theo NAMESPACE, không chỉ theo từng class.
        //
        // API lệnh của Ordering là 53 command + 2 result + 14 value object + 8
        // contract. Liệt kê 77 FQCN thì danh sách trở thành thứ không ai đọc,
        // và một command mới thêm vào sẽ lặng lẽ nằm ngoài — tức đúng loại
        // "khai một lần rồi rữa" mà file này tồn tại để chống.
        //
        // Việc này KHÔNG nới luật "khai tường minh": khai một namespace vẫn là
        // một hành động có người chịu trách nhiệm. Cái chặn lạm dụng không phải
        // độ dài danh sách mà là luật `PublishedContracts` chỉ được phụ thuộc
        // hai kernel — bỏ một class chạm model vào namespace đã công bố là
        // deptrac ĐỎ ngay, không cần ai nhớ.
        $publishedNamespaces = $manifest['published_contract_namespaces'] ?? [];
        // Class lẻ thuộc SharedKernel cũng phải bị trừ khỏi collector namespace
        // của module đang chứa chúng (#1553) — nếu không, chúng nằm hai layer.
        $sharedClasses = $manifest['shared_classes'] ?? [];
        foreach ($sharedClasses as $fqn) {
            $claimed[] = $fqn;
        }
        // #1574 — class LẺ thuộc TenancyKernel.
        //
        // `SharedKernel: ~` khai rằng hạ tầng dùng chung không phụ thuộc vào gì.
        // Nhưng SharedKernel đang chứa cả trait model và model dùng chung, mà một
        // trait khai quan hệ thì BẮT BUỘC phải nhắc tới model — đó không phải nợ,
        // đó là bản chất của trait. Kết quả là 14 cạnh `SharedKernel → TenancyKernel`
        // bị ghi thành nợ, và chúng đóng chu trình ở ĐÁY đồ thị (bài C3 trong
        // LayerCyclesTest ghim đúng tình trạng đó).
        //
        // Cách sửa KHÔNG phải nới `SharedKernel` cho phép phụ thuộc TenancyKernel
        // — làm thế là hợp thức hoá chu trình đáy. Cách sửa là chuyển đúng những
        // class chỉ-nói-về-tenancy sang TenancyKernel, để `SharedKernel: ~` trở
        // lại thành một lời khai ĐÚNG thay vì một lời khai bị baseline che.
        $tenancyClasses = $manifest['tenancy_kernel_classes'] ?? [];
        foreach ($tenancyClasses as $fqn) {
            $claimed[] = $fqn;
        }
        // #1596 — class LẺ thuộc Composition.
        //
        // `COMPOSITION` là danh sách NAMESPACE, nên nó chỉ nhận trọn gói. Nhưng
        // `CustomerOrderHistoryService` là MỘT class trong `App\Services\Customer`
        // — namespace mà phần còn lại thuộc CustomerEngagement thật. Khai cả
        // namespace là sai; khai một class là đúng.
        //
        // Cùng hai tiêu chí như namespace (`CompositionMembershipTest`): 0 cạnh
        // vào từ module, 0 lời gọi ghi.
        $compositionClasses = $manifest['composition_classes'] ?? [];
        foreach ($compositionClasses as $fqn) {
            $claimed[] = $fqn;
            $moduleClaimed[] = $fqn;
        }

        // #1552 — khai TRÙNG là LỖI, không phải thứ để generator tự xử.
        //
        // `App\Services\Workstation` từng nằm cả trong `PlatformIntegration.
        // namespaces` lẫn `COMPOSITION`. Deptrac không báo lỗi — nó cảnh báo
        // "in more than one layer" rồi lấy layer đứng trước, nên khai báo mới
        // im lặng không có tác dụng và con số chỉ nhúc nhích 2 thay vì 19.
        //
        // Không giải bằng thứ tự ưu tiên: một namespace thuộc hai tầng là hai
        // người khai mâu thuẫn nhau, và câu trả lời đúng là hỏi lại họ, không
        // phải đoán hộ.
        foreach ($modules as $module => $spec) {
            foreach ($spec['namespaces'] ?? [] as $ns) {
                if (in_array($ns, self::COMPOSITION, true)) {
                    throw new \RuntimeException(
                        "Namespace `{$ns}` được khai VỪA cho module `{$module}` VỪA cho Composition. ".
                        'Chọn một: nếu nó là tầng nối dây thì bỏ khỏi module; nếu nó là miền thì bỏ khỏi COMPOSITION.'
                    );
                }
            }
        }

        $tenancyModels = self::TENANCY_KERNEL;
        $lines = [];
        $lines[] = '# GENERATED by `php artisan architecture:deptrac-config` — DO NOT EDIT BY HAND.';
        $lines[] = '# Source of truth: backend/config/modules.php (#962 / #1532).';
        $lines[] = '#';
        $lines[] = '# Deptrac replaces the hand-rolled edge counter it used to have. The old one';
        $lines[] = '# kept ONE total per module pair in a single JSON file, so two unrelated PRs';
        $lines[] = '# collided on the same line — 6 of 9 PRs conflicted there on 2026-08-01.';
        $lines[] = '# Deptrac baselines each violation separately, so that collision cannot occur.';
        $lines[] = '# Per-violation baseline (#1532). THIS is the property the old single-number';
        $lines[] = '# file lacked: each class gets its own block, so two PRs paying down debt in';
        $lines[] = '# different classes edit different lines and never collide. Regenerate with';
        $lines[] = '#   deptrac analyse --formatter=baseline -o deptrac-baseline.yaml';
        $lines[] = '# and say in the commit WHY anything was added — the file only shrinks.';
        $lines[] = 'imports:';
        $lines[] = '  - ./deptrac-baseline.yaml';
        $lines[] = 'deptrac:';
        $lines[] = '  paths:';
        $lines[] = '    - ./app';
        $lines[] = '  exclude_files:';
        $lines[] = '    # Generated by Omnify and stamped "DO NOT EDIT". 1016 files whose shape is';
        $lines[] = '    # the generator\'s decision, not the team\'s: a boundary violation in there';
        $lines[] = '    # cannot be refactored (regen overwrites it) and says nothing about how';
        $lines[] = '    # anyone writes code. Measuring them made the count jump 224 → 5809 without';
        $lines[] = '    # adding one actionable item. The hand-written wrappers in App\\Models stay';
        $lines[] = '    # covered — that is where the real decisions live.';
        $lines[] = "    - '#app/Omnify/Modules/.*#'";
        $lines[] = '  layers:';

        // 1. technical shared code
        $lines[] = '    # Technical shared code — helpers, casts, traits, providers. Not a domain.';
        $lines[] = '    - name: SharedKernel';
        $lines[] = '      collectors:';
        foreach ($shared as $ns) {
            // Class do một MODULE nhận tường minh phải bị trừ khỏi collector shared
            // (#1561). Không có phép trừ này thì `App\Services\Omnify\...Service`
            // vừa là SharedKernel vừa là Inventory — Deptrac cảnh báo "in more than
            // one layer" và phép đo hỏng đúng kiểu #1539, chỗ 21 cảnh báo bị bỏ qua
            // đã bịa ra một chu trình 496 cạnh không tồn tại.
            $under = array_values(array_filter(
                // #1574 — class lẻ đã khai TenancyKernel cũng phải trừ khỏi đây.
                // `App\Traits` là tiền tố `shared`, nên khai
                // `App\Traits\AuditsActivity` là TenancyKernel mà không trừ thì
                // nó nằm hai layer, Deptrac cảnh báo rồi lấy layer đứng trước —
                // và khai báo mới im lặng không có tác dụng gì.
                [...$moduleClaimed, ...$tenancyClasses],
                static fn (string $f): bool => str_starts_with($f, $ns.'\\'),
            ));
            if ($under === []) {
                $lines[] = '        - type: classNameRegex';
                $lines[] = '          value: '.$this->nsRegex($ns);

                continue;
            }
            $lines[] = '        - type: bool';
            $lines[] = '          must:';
            $lines[] = '            - type: classNameRegex';
            $lines[] = '              value: '.$this->nsRegex($ns);
            $lines[] = '          must_not:';
            foreach ($under as $f) {
                $lines[] = '            - type: classNameRegex';
                $lines[] = '              value: '.$this->exactRegex($f);
            }
        }
        foreach ($sharedClasses as $fqn) {
            $lines[] = '        - type: classNameRegex';
            $lines[] = '          value: '.$this->exactRegex($fqn);
        }

        // 2. tenancy anchors
        $lines[] = '    # Tenancy anchors (#1532). Every module must know which org/brand/branch a';
        $lines[] = '    # row belongs to, so these are DESIGN, not debt. Boundary picked from';
        $lines[] = '    # measurement: Branch 146 / Brand 129 / Organization 126 external importers.';
        $lines[] = '    - name: TenancyKernel';
        $lines[] = '      collectors:';
        $lines[] = '        - type: classNameRegex';
        $lines[] = '          value: '.$this->entityRegex($tenancyModels);
        foreach ($tenancyClasses as $fqn) {
            $lines[] = '        - type: classNameRegex';
            $lines[] = '          value: '.$this->exactRegex($fqn);
        }

        // 3+4. one layer per module
        foreach ($modules as $module => $spec) {
            // Trừ cả model đã được khai là SharedKernel (#1553): danh sách models
            // của module đi qua `entityRegex`, không qua collector namespace, nên
            // phép trừ `$claimed` bên dưới KHÔNG với tới nó — `AuditLog` vì thế
            // nằm hai layer ngay lần chạy đầu, đúng lỗi #1539 lặp lại.
            $sharedShortNames = array_map(
                static fn (string $f): string => substr((string) strrchr($f, '\\'), 1),
                // #1574 — class lẻ thuộc TenancyKernel phải trừ Y HỆT class lẻ
                // thuộc SharedKernel. Bỏ sót vế này là `AuditLog` nằm hai layer
                // và Deptrac lấy layer đứng trước: đo được +32 cạnh, trong đó có
                // cả những cạnh đáng lẽ hợp lệ (module → TenancyKernel).
                [...$sharedClasses, ...$tenancyClasses],
            );
            $models = array_values(array_diff(
                $spec['models'] ?? [],
                $tenancyModels,
                $sharedShortNames,
            ));
            $lines[] = "    - name: {$module}";
            $lines[] = '      collectors:';
            // A module's own tree (#1360). Same convention `ModuleRegistry` uses to
            // find `App\Modules\<Name>\<Name>ModuleServiceProvider`, applied here so
            // the two cannot drift: code written inside a module is owned by that
            // module without anyone having to remember to declare it. Emitted even
            // when the directory is empty — an unassigned first file is the failure
            // mode this prevents.
            // #1596 — collector theo quy ước này trước đây phát VÔ ĐIỀU KIỆN, nên
            // một class dưới `App\Modules\<Name>\` được khai ở nơi khác (hợp đồng
            // công bố, class dùng chung) nằm HAI layer cùng lúc, và Deptrac lấy layer
            // xuất hiện trước. Hệ quả đo được: khai `NotificationDispatcher` là hợp
            // đồng công bố xong nó VẪN bị tính là Notifications — cổng nhận chính
            // value object của mình bị ghi thành vi phạm. Cùng một lỗi hai-layer mà
            // #1539 / #1553 / #1360 đã sửa cho ba collector khác; đây là cái thứ tư.
            $ownTree = "App\\Modules\\{$module}";
            $claimedUnderOwnTree = array_values(array_filter(
                array_values(array_diff($claimed, $spec['classes'] ?? [])),
                static fn (string $f): bool => str_starts_with($f, $ownTree.'\\'),
            ));
            if ($claimedUnderOwnTree === []) {
                $lines[] = '        - type: classNameRegex';
                $lines[] = '          value: '.$this->nsRegex($ownTree);
            } else {
                $lines[] = '        - type: bool';
                $lines[] = '          must:';
                $lines[] = '            - type: classNameRegex';
                $lines[] = '              value: '.$this->nsRegex($ownTree);
                $lines[] = '          must_not:';
                foreach ($claimedUnderOwnTree as $f) {
                    $lines[] = '            - type: classNameRegex';
                    $lines[] = '              value: '.$this->exactRegex($f);
                }
            }
            foreach ($spec['classes'] ?? [] as $fqn) {
                // #1597 — class đã CÔNG BỐ thì thôi phát ở layer module.
                //
                // Công bố là một OVERLAY lên quyền sở hữu: `PricingResult` vẫn
                // là của Pricing, nhưng trong đồ thị nó phải nằm ở
                // `PublishedContracts`, vì layer là loại trừ lẫn nhau. Không
                // trừ ở đây thì nó nằm hai layer, Deptrac cảnh báo rồi lấy layer
                // đứng trước — và khai báo công bố im lặng không có tác dụng.
                //
                // Lỗi hai-layer thứ BẢY trong file này. Sáu lần trước:
                // #1539 · #1553 · #1360 · #1596 · #1574 · #1552.
                if (in_array($fqn, $publishedContracts, true)) {
                    continue;
                }
                $lines[] = '        - type: classNameRegex';
                $lines[] = '          value: '.$this->exactRegex($fqn);
            }
            foreach ($spec['namespaces'] ?? [] as $ns) {
                // Class do module KHÁC nhận tường minh phải bị trừ ra khỏi
                // collector namespace này (#1541).
                $others = array_values(array_diff($claimed, $spec['classes'] ?? []));
                $under = array_values(array_filter(
                    $others,
                    static fn (string $f): bool => str_starts_with($f, $ns.'\\'),
                ));
                // #1583 — và namespace đã công bố nằm DƯỚI namespace này cũng
                // phải trừ: `App\Services\Order\Commands` nằm trong
                // `App\Services\Order`, nên không trừ thì command vừa thuộc
                // Ordering vừa thuộc PublishedContracts và Deptrac lấy cái
                // đứng trước — đúng lỗi hai-layer đã trả giá bốn lần.
                $underNs = array_values(array_filter(
                    // #1552 — và namespace COMPOSITION nằm dưới namespace này
                    // cũng phải trừ. `App\Services\Payment\Observation` nằm
                    // trong `App\Services\Payment`; không trừ thì nó hai layer,
                    // Deptrac cảnh báo rồi lấy layer đứng trước — khai báo mới
                    // im lặng không có tác dụng. Lỗi hai-layer thứ SÁU.
                    [...$publishedNamespaces, ...self::COMPOSITION],
                    static fn (string $p): bool => str_starts_with($p, $ns.'\\'),
                ));
                if ($under === [] && $underNs === []) {
                    $lines[] = '        - type: classNameRegex';
                    $lines[] = '          value: '.$this->nsRegex($ns);

                    continue;
                }
                $lines[] = '        - type: bool';
                $lines[] = '          must:';
                $lines[] = '            - type: classNameRegex';
                $lines[] = '              value: '.$this->nsRegex($ns);
                $lines[] = '          must_not:';
                foreach ($under as $f) {
                    $lines[] = '            - type: classNameRegex';
                    $lines[] = '              value: '.$this->exactRegex($f);
                }
                foreach ($underNs as $p) {
                    $lines[] = '            - type: classNameRegex';
                    $lines[] = '              value: '.$this->nsRegex($p);
                }
            }
            if ($models !== []) {
                $lines[] = '        - type: classNameRegex';
                $lines[] = '          value: '.$this->entityRegex($models);
            }
        }

        // #1596 — layer hợp đồng công bố. Đặt TRƯỚC Composition để thứ tự đọc
        // khớp thứ tự phụ thuộc: mọi module được phép dùng nó, nó không được
        // phép dùng module nào.
        if ($publishedContracts !== [] || $publishedNamespaces !== []) {
            $lines[] = '    # Published contracts (#1596). Every module may depend on these; they may';
            $lines[] = '    # depend on NO module. That second half is the load-bearing one: a contract';
            $lines[] = "    # whose signature carries the owner's Eloquent model fails right here,";
            $lines[] = '    # which is the mechanical version of "a port must not leak the model".';
            $lines[] = '    - name: PublishedContracts';
            $lines[] = '      collectors:';
            foreach ($publishedContracts as $fqn) {
                $lines[] = '        - type: classNameRegex';
                $lines[] = '          value: '.$this->exactRegex($fqn);
            }
            foreach ($publishedNamespaces as $ns) {
                $lines[] = '        - type: classNameRegex';
                $lines[] = '          value: '.$this->nsRegex($ns);
            }
        }

        // composition root + adapters
        $lines[] = '    # Composition root and edge adapters: providers wire every module by';
        $lines[] = '    # definition, controllers translate HTTP to a use case. Depending on';
        $lines[] = '    # everything is their job. The previous graph excluded surfaces too.';
        $lines[] = '    - name: Composition';
        $lines[] = '      collectors:';
        foreach ($compositionClasses as $fqn) {
            $lines[] = '        - type: classNameRegex';
            $lines[] = '          value: '.$this->exactRegex($fqn);
        }
        foreach (self::COMPOSITION as $ns) {
            // A class declared SharedKernel by exact name must be subtracted here
            // too (#1360). Composition claims whole namespaces, so without this the
            // class lands in two layers — the same defect #1539 cost us, and the
            // same one #1553 fixed for module namespaces but not for this one.
            $under = array_values(array_filter(
                $sharedClasses,
                static fn (string $f): bool => str_starts_with($f, $ns.'\\'),
            ));
            if ($under === []) {
                $lines[] = '        - type: classNameRegex';
                $lines[] = '          value: '.$this->nsRegex($ns);

                continue;
            }
            $lines[] = '        - type: bool';
            $lines[] = '          must:';
            $lines[] = '            - type: classNameRegex';
            $lines[] = '              value: '.$this->nsRegex($ns);
            $lines[] = '          must_not:';
            foreach ($under as $f) {
                $lines[] = '            - type: classNameRegex';
                $lines[] = '              value: '.$this->exactRegex($f);
            }
        }

        // ruleset — every module may use the two kernels and nothing else by default.
        $lines[] = '  ruleset:';
        $lines[] = '    # Baseline behaviour: a module may depend on the two kernels only. Every';
        $lines[] = '    # module→module edge is a violation, recorded individually in';
        $lines[] = '    # deptrac-baseline.yaml and only allowed to SHRINK.';
        $lines[] = '    SharedKernel: ~';
        $lines[] = '    TenancyKernel:';
        $lines[] = '      - SharedKernel';
        foreach (array_keys($modules) as $module) {
            $lines[] = "    {$module}:";
            $lines[] = '      - SharedKernel';
            $lines[] = '      - TenancyKernel';
            // A layer must be allowed to depend on ITSELF. Deptrac does not
            // assume it, and leaving it out recorded 30 `Ordering -> Ordering`
            // entries as debt (#1534) — a module calling its own classes is the
            // module doing its job, not a boundary crossing.
            $lines[] = "      - {$module}";
            if ($publishedContracts !== [] || $publishedNamespaces !== []) {
                $lines[] = '      - PublishedContracts';
            }
        }
        if ($publishedContracts !== [] || $publishedNamespaces !== []) {
            $lines[] = '    PublishedContracts:';
            $lines[] = '      - SharedKernel';
            $lines[] = '      - TenancyKernel';
            // Bản đầu CỐ TÌNH bỏ dòng này, với lý do "một hợp đồng không được
            // phụ thuộc hợp đồng khác". Chạy thử: 4 vi phạm mới, tất cả là
            // `NotificationDispatcher -> NotificationRequest` — một cổng nhận
            // chính value object của nó. Đó là #1534 lặp lại (bỏ tự-phụ-thuộc
            // ghi 30 cạnh `Ordering -> Ordering` thành nợ). Deptrac không phân
            // biệt được "cùng chủ" với "khác chủ" trong một layer, nên luật giữ
            // cho cửa này khỏi phình là DANH SÁCH TƯỜNG MINH, không phải chỗ này.
            $lines[] = '      - PublishedContracts';
        }
        $lines[] = '    Composition:';
        // #1552 — `Composition` phải được phụ thuộc CHÍNH NÓ. Thiếu dòng đó thì
        // một controller gọi một service cũng-là-Composition bị ghi thành vi
        // phạm, và một service Composition gọi service Composition khác cũng
        // vậy. #1591 không lộ ra vì Dashboard là hai class KHÔNG gọi nhau; lần
        // đầu thêm một cụm có tham chiếu nội bộ là hiện ngay 6 cạnh giả.
        //
        // Đây là #1534 lần thứ BA (Ordering→Ordering 30 cạnh, rồi
        // PublishedContracts→PublishedContracts 4 cạnh, giờ đến lượt này). Mỗi
        // lần thêm một layer là phải nhớ lại — nên nhớ hộ bằng comment này.
        foreach (['SharedKernel', 'TenancyKernel', 'Composition', ...array_keys($modules), ...($publishedContracts !== [] || $publishedNamespaces !== [] ? ['PublishedContracts'] : [])] as $l) {
            $lines[] = "      - {$l}";
        }

        return implode("\n", $lines)."\n";
    }

    /** Regex matching exactly one fully-qualified class name. */
    private function exactRegex(string $fqn): string
    {
        // `preg_quote` ĐÃ escape dấu `\` thành `\\`, tức PCRE khớp đúng MỘT
        // backslash. Nhân đôi thêm lần nữa là bắt PCRE tìm HAI dấu phân tách và
        // pattern lặng lẽ không khớp gì — đúng lỗi #1539.
        return "'#^".preg_quote($fqn, '#')."$#'";
    }

    /** Regex matching every class under a namespace prefix. */
    private function nsRegex(string $ns): string
    {
        return "'#^".str_replace('\\', '\\\\', $ns)."\\\\#'";
    }

    /**
     * Regex matching a model AND every class derived from its name.
     *
     * Mirrors ModuleGraph::entityHint: `App\Models\Order`, `App\Omnify\Modules\
     * Order\…`, `App\Policies\OrderPolicy`, `App\Observers\OrderObserver`, and
     * the generated resources/requests all carry the aggregate name. Assigning
     * them by name is what keeps the 1016 generated files from landing in
     * "unassigned" — the hole the old map had (114 classes).
     *
     * @param  list<string>  $models
     */
    private function entityRegex(array $models): string
    {
        sort($models);
        $alt = implode('|', array_map(static fn (string $m): string => preg_quote($m, '#'), $models));

        // Three shapes, matched EXACTLY — never "prefix + any capital" (#1539).
        //
        //   App\Models\Order            the model itself, exact
        //   App\Policies\OrderPolicy    derived, prefix + a KNOWN suffix
        //   App\Omnify\Modules\Order\  generated tree, prefix + separator
        //
        // The previous version allowed `[A-Z]` after the name, meaning `Customer`
        // also matched `CustomerOrder` and `Branch` matched
        // `BranchFloatingSectionOverride` — 21 models landed in two layers at
        // once. That did not break the gate (the baseline absorbed it) but it
        // corrupted the MEASUREMENT: `CustomerOrder` showed up on both sides of
        // an Ordering↔CustomerEngagement "cycle" that was mostly an artefact,
        // and it had already been written into the paydown plan as stage 2.
        $suffixes = 'Policy|Observer|Resource|Request|Factory|Event|Job|Listener|Seeder|Collection';

        return "'#^App\\\\(?:"
            ."Models\\\\({$alt})$"
            ."|(?:Policies|Observers|Events|Jobs|Listeners)\\\\({$alt})(?:{$suffixes})$"
            ."|Omnify\\\\Modules\\\\({$alt})\\\\"
            .")#'";
    }
}
