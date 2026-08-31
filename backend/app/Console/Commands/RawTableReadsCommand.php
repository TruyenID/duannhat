<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * #1597 — đếm lớp nợ mà deptrac KHÔNG NHÌN THẤY: một module đọc thẳng BẢNG của
 * module khác bằng query builder thô.
 *
 * Mọi con số của #962 đo **cạnh giữa các class**. `DB::table('menus')` không
 * import class nào, nên nó không phải một cạnh — nhưng nó vẫn là Ordering đọc
 * thẳng bảng của Catalog, và nó **chưa từng xuất hiện trong bất kỳ phép đo nào**
 * của epic. Lỗ hổng kiểu đó tệ hơn một con số xấu: nó làm phép đo tự nhận là
 * đầy đủ trong khi không.
 *
 * Phát hiện ra nó là do tình cờ — một `DB::table('product_category')` nằm ngay
 * cạnh chỗ đang sửa ở PR #1620. Lệnh này để lần sau không phải trông vào may.
 *
 * ## Cái nó KHÔNG bắt được — đọc trước khi tin con số
 *
 * 1. **Bảng không có model** (pivot thuần như `product_category`) không tra
 *    được chủ sở hữu, vì chủ sở hữu suy ra từ danh sách `models` của
 *    `config/modules.php`. Chúng vào mục `unowned`, KHÔNG bị bỏ im lặng.
 * 2. **`DB::select()` / `DB::statement()` / SQL thô** không phân tích được tên
 *    bảng bằng regex an toàn. Số lượng được báo riêng để không ai tưởng 0.
 * 3. File không thuộc module nào (Composition, `App\Support`…) bị bỏ qua có chủ
 *    đích — Composition được phép phụ thuộc mọi module theo thiết kế.
 *
 * Nói cách khác con số này là **cận dưới**. Đó là lý do nó được báo kèm cả ba
 * mục trên chứ không đứng một mình.
 *
 * ## Bảng của TenancyKernel KHÔNG phải nợ (#1622)
 *
 * Mọi module được phép phụ thuộc TenancyKernel (`Organization` · `Brand` ·
 * `Branch` · `BranchTranslation` · `User`), nên đọc BẢNG của nó cũng vậy. Bản
 * đầu của lệnh này (#1621) thiếu luật đó và **báo thừa 5 chỗ** — một phép đo
 * chặt hơn đồ thị tầng, tức nó đòi trả một khoản nợ không tồn tại.
 *
 * Danh sách lấy bằng **reflection** từ {@see DeptracConfigCommand::TENANCY_KERNEL},
 * không chép lại: bản sao lệch nhau là cách hai phép đo âm thầm nói hai chuyện.
 */
final class RawTableReadsCommand extends Command
{
    protected $signature = 'architecture:raw-table-reads {--json} {--path= : Thư mục quét (mặc định app/)}';

    protected $description = 'Đếm chỗ một module đọc thẳng bảng của module khác bằng query builder thô (deptrac không thấy)';

    /**
     * `DB::table('x')` và `->from('x')` — hai dạng cùng nghĩa.
     *
     * `(?: as \w+)?` là **bản vá của #1622**, không phải trang trí: bản đầu
     * (#1621) đòi dấu nháy đóng ngay sau tên bảng, nên mọi truy vấn đặt bí danh
     * — `DB::table('customer_order_items as coi')` — **không khớp và biến mất
     * khỏi phép đo**. Đo lại: **40 chỗ** dùng bí danh, nhiều hơn cả con số 21
     * mà bản đầu báo cáo.
     */
    private const READ_PATTERN = "#(?:DB::table\(|->from\()'([a-z0-9_]+)(?: +as +[a-z0-9_]+)?'#";

    private const UNPARSEABLE_PATTERN = '#DB::(select|statement)\(#';

    /**
     * Chỉ để ĐẾM: có bao nhiêu truy vấn thô đặt bí danh. Con số này là hàng rào
     * cho chính điểm mù của #1625 — nó phải khác 0, bất kể khoản nợ nào đã trả.
     */
    private const ALIASED_PATTERN = "#(?:DB::table\(|->from\()'[a-z0-9_]+ +as +[a-z0-9_]+'#";

    public function handle(): int
    {
        $modules = config('modules.modules', []);
        $tableOwner = $this->tableOwners($modules);
        $kernelTables = $this->tenancyKernelTables();
        [$nsOwner, $classOwner] = $this->classOwners($modules);

        $cross = [];
        $unowned = [];
        $unparseable = 0;
        $sameModule = 0;
        $kernelReads = 0;
        $aliased = 0;

        $root = $this->option('path') ?: app_path();
        if (! is_dir($root)) {
            $this->error("scan path does not exist: {$root}");

            return self::FAILURE;
        }

        foreach ($this->phpFiles($root) as $path) {
            $source = file_get_contents($path);
            if ($source === false) {
                continue;
            }
            $source = $this->withoutComments($source);

            $unparseable += preg_match_all(self::UNPARSEABLE_PATTERN, $source);
            $aliased += preg_match_all(self::ALIASED_PATTERN, $source);

            if (! preg_match_all(self::READ_PATTERN, $source, $matches)) {
                continue;
            }

            $reader = $this->ownerOf($this->fqcnFor($path, $root), $nsOwner, $classOwner);
            if ($reader === null) {
                continue;
            }

            $relative = str_replace(base_path().'/', '', $path);
            foreach ($matches[1] as $table) {
                if (! array_key_exists($table, $tableOwner)) {
                    $unowned[$table][] = $relative;

                    continue;
                }
                if (in_array($table, $kernelTables, true)) {
                    // TenancyKernel: mọi module được phép. Đếm RIÊNG, không gộp
                    // vào `same_module_count` — con số đó là hàng rào "bộ quét
                    // còn sống", gộp vào là thổi phồng nó.
                    $kernelReads++;

                    continue;
                }
                if ($tableOwner[$table] === $reader) {
                    // Đọc bảng của CHÍNH module mình — hợp lệ, nhưng vẫn đếm:
                    // nó là bằng chứng bộ quét còn CHẠY. Không có con số này
                    // thì một regex hỏng sẽ trả 0 cạnh xuyên module và đọc
                    // giống hệt "đã sửa xong hết".
                    $sameModule++;

                    continue;
                }
                $cross[] = ['reader' => $reader, 'table' => $table, 'owner' => $tableOwner[$table], 'file' => $relative];
            }
        }

        $payload = [
            'cross_module' => $cross,
            'cross_module_count' => count($cross),
            'unowned_tables' => array_map('array_unique', $unowned),
            'unparseable_raw_sql' => $unparseable,
            'same_module_count' => $sameModule,
            'tenancy_kernel_count' => $kernelReads,
            'aliased_read_count' => $aliased,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info(sprintf('Đọc thô XUYÊN MODULE: %d', count($cross)));
        $grouped = [];
        foreach ($cross as $row) {
            $grouped[$row['reader'].' → '.$row['owner']][] = $row['table'].'  ('.basename($row['file']).')';
        }
        uasort($grouped, static fn (array $a, array $b): int => count($b) <=> count($a));
        foreach ($grouped as $pair => $rows) {
            $this->line(sprintf('  %-36s %2d   %s', $pair, count($rows), implode(', ', array_unique($rows))));
        }

        $this->newLine();
        $this->line(sprintf('Đọc thô TRONG module (hợp lệ, đếm để biết bộ quét còn chạy): %d', $sameModule));
        $this->line(sprintf('Đọc bảng TenancyKernel (hợp lệ — mọi module được phép): %d', $kernelReads));
        $this->line(sprintf('Trong đó đặt BÍ DANH (điểm mù của #1625, nay đã đếm): %d', $aliased));
        $this->warn(sprintf(
            'Cận dưới: %d bảng không tra được chủ (không có model) · %d chỗ SQL thô không phân tích được.',
            count($unowned),
            $unparseable,
        ));

        return self::SUCCESS;
    }

    /**
     * Comments describe architecture but are not executable reads. PHP's lexer
     * already distinguishes both comment forms, so do not approximate this with
     * another regular expression (#2374).
     */
    private function withoutComments(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /**
     * @param  array<string, array<string, mixed>>  $modules
     * @return array<string, string>
     */
    private function tableOwners(array $modules): array
    {
        $owners = [];
        foreach ($modules as $name => $definition) {
            foreach ($definition['models'] ?? [] as $model) {
                $class = 'App\\Models\\'.$model;
                if (! class_exists($class)) {
                    continue;
                }
                try {
                    $owners[(new $class)->getTable()] = $name;
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return $owners;
    }

    /**
     * Bảng của các model TenancyKernel — mọi module được phép đọc.
     *
     * @return list<string>
     */
    private function tenancyKernelTables(): array
    {
        /** @var list<string> $models */
        $models = (new ReflectionClass(DeptracConfigCommand::class))->getConstant('TENANCY_KERNEL') ?: [];

        $tables = [];
        foreach ($models as $model) {
            $class = 'App\\Models\\'.$model;
            if (! class_exists($class)) {
                continue;
            }
            try {
                $tables[] = (new $class)->getTable();
            } catch (\Throwable) {
                continue;
            }
        }

        return $tables;
    }

    /**
     * @param  array<string, array<string, mixed>>  $modules
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function classOwners(array $modules): array
    {
        $namespaces = [];
        $classes = [];
        foreach ($modules as $name => $definition) {
            foreach ($definition['namespaces'] ?? [] as $namespace) {
                $namespaces[$namespace] = $name;
            }
            foreach ($definition['classes'] ?? [] as $class) {
                $classes[$class] = $name;
            }
        }

        return [$namespaces, $classes];
    }

    /**
     * @param  array<string, string>  $namespaceOwners
     * @param  array<string, string>  $classOwners
     */
    private function ownerOf(string $fqcn, array $namespaceOwners, array $classOwners): ?string
    {
        if (isset($classOwners[$fqcn])) {
            return $classOwners[$fqcn];
        }

        // Namespace DÀI NHẤT thắng: `App\Services\Order\Internal` phải thua
        // một khai báo riêng cho chính nó, nếu sau này có.
        $best = null;
        $bestLength = -1;
        foreach ($namespaceOwners as $namespace => $module) {
            if (str_starts_with($fqcn, $namespace.'\\') && strlen($namespace) > $bestLength) {
                $best = $module;
                $bestLength = strlen($namespace);
            }
        }

        return $best;
    }

    private function fqcnFor(string $path, string $root): string
    {
        $relative = str_replace(rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR, '', $path);

        return 'App\\'.str_replace('/', '\\', preg_replace('#\.php$#', '', $relative) ?? '');
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $directory): array
    {
        $files = [];
        /** @var \SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
