<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * #1653 — tìm chỗ code nêu tên một cột **không tồn tại**.
 *
 * ## Bốn lỗi cùng khuôn, mỗi cái tìm được do tình cờ
 *
 * | | đọc gì | cột | hậu quả |
 * |---|---|---|---|
 * | #1614 | `$variant->product?->code` | chưa bao giờ có | trường payload luôn null |
 * | #1645 | `ShopOrderSetting::…->value('tax_rate')` | **đã DROP** | Z-report khai doanh thu chịu thuế là 非課税 |
 * | #1651 | `$order->currency_code` | không có | tầng ưu tiên ma trên hoá đơn |
 * | plan-043 T1.13 | `$order->tax_rate` | không có | **mọi hoá đơn lưu tax_amount = 0** |
 *
 * Gốc chung: Eloquent trả `null` cho thuộc tính không tồn tại **thay vì báo
 * lỗi**, nên `null` đọc lên y hệt "dữ liệu còn thiếu". Không exception, không
 * log, test cũ vẫn xanh.
 *
 * ## Nguyên tắc: PHÁT HIỆN rồi TỪ CHỐI, không cố phân giải
 *
 * Bản dựng đầu cố trả lời "cột này thuộc model nào" và sa lầy: 25 phát hiện,
 * gần như toàn bộ dương tính giả. Regex không phân giải được kiểu.
 *
 * Nhưng bài toán không đòi phân giải. Nó chỉ đòi: chuỗi nào **chắc chắn** đọc
 * được thì kiểm, chuỗi nào có dấu hiệu mơ hồ thì **bỏ qua cả chuỗi**. Không cần
 * biết `$x->pluck()` trỏ vào đâu — chỉ cần biết là có nó.
 *
 * Năm dấu hiệu từ chối, mỗi cái ứng với một lớp nhiễu ĐO ĐƯỢC:
 *
 * | dấu hiệu | vì sao mơ hồ |
 * |---|---|
 * | closure (`fn (`, `function (`) | `whereHas('rel', fn ($q) => $q->where('col'))` — cột của QUAN HỆ |
 * | `join` / `selectRaw` / `withCount`… | kéo cột bảng khác, hoặc alias `as cnt`, vào phạm vi |
 * | `::` thứ hai | chuỗi model LỒNG NHAU — cột cuối thuộc model ngoài, không phải model trong |
 * | `$var->method(` | `pluck` trên COLLECTION nằm lọt trong span |
 * | span > 2000 ký tự | regex gần như chắc chắn đã vượt biên câu lệnh |
 *
 * `$session->branch_id` KHÔNG kích hoạt dấu hiệu thứ tư: đọc thuộc tính không
 * có `(` ngay sau tên. Phân biệt được hai cái đó là điều làm cho lát cắt này
 * giữ được #1645 trong khi vẫn bỏ được nhiễu.
 *
 * Trước tất cả những cái trên, comment bị bóc ({@see self::codeOnly()}) — mã
 * mới là thứ chạy được, và một câu văn mô tả lỗi cũ không phải là lỗi (#2511).
 *
 * ## Cái này KHÔNG bắt được
 *
 * ⚠️ **Dạng `$var->attr`** (tức #1614 và #1651) — cần suy luận kiểu, tức
 * PHPStan/Psalm. Con số 0 ở đây nghĩa là "sạch ở dạng tra được", KHÔNG phải
 * "hết cột ma". Viết thẳng ra để không ai đọc nhầm.
 *
 * ## Vì sao dạng bắt được thì "không có cột" = lỗi thật
 *
 * Cột chuỗi trong query builder đi thẳng vào SQL: accessor hay relation KHÔNG
 * cứu được (`where('full_name', …)` với `getFullNameAttribute()` vẫn là
 * `Unknown column`). Khác hẳn dạng `$model->attr`, nơi accessor là chuyện thường.
 */
final class PhantomColumnsCommand extends Command
{
    protected $signature = 'architecture:phantom-columns {--json}';

    protected $description = 'Tìm tên cột không tồn tại nêu trên chuỗi truy vấn của một model (#1653)';

    /** Method nhận tên cột ở tham số ĐẦU TIÊN. */
    private const COLUMN_FIRST_ARG = [
        'value', 'pluck', 'orderBy', 'orderByDesc', 'groupBy',
        'where', 'whereNull', 'whereNotNull', 'whereIn', 'whereNotIn', 'sum', 'max', 'min', 'avg',
    ];

    /** Dấu hiệu "chuỗi này mơ hồ" — gặp là bỏ qua CẢ chuỗi. */
    private const AMBIGUITY_MARKERS = [
        '#\bfn\s*\(|\bfunction\s*\(#',
        '#->(selectRaw|withCount|withSum|withAvg|withMax|withMin|join|leftJoin|rightJoin|crossJoin)\s*\(#',
        '#\$\w+(?:->\w+)*->\w+\s*\(#',
        // `::` thứ hai trong span ⇒ có chuỗi model khác lồng/kề bên.
        '#::(?:query\(\)|[a-zA-Z]\w*\()[\s\S]*?::#',
    ];

    public function handle(): int
    {
        $models = $this->modelsByShortName();
        $findings = [];
        $skippedAmbiguous = 0;
        $unresolvedModels = [];

        foreach ($this->phpFiles(app_path()) as $path) {
            // #2786 — file có thể biến mất giữa lúc liệt kê và lúc đọc.
            $source = @file_get_contents($path);
            if ($source === false) {
                continue;
            }
            $relative = str_replace(base_path().'/', '', $path);

            foreach ($this->columnMentions($source, $skippedAmbiguous) as [$modelName, $method, $column, $line]) {
                $class = $models[$modelName] ?? null;

                if ($class === null) {
                    $unresolvedModels[$modelName] = true;

                    continue;
                }

                $columns = $this->columnsFor($class);

                if ($columns === null || in_array($column, $columns, true)) {
                    continue;
                }

                $findings[] = [
                    'file' => $relative,
                    'line' => $line,
                    'model' => $modelName,
                    'method' => $method,
                    'column' => $column,
                ];
            }
        }

        $report = [
            'phantom_count' => count($findings),
            'phantom' => $findings,
            // Báo ra, không nuốt: mỗi chuỗi bỏ qua là một chỗ KHÔNG được kiểm.
            // Con số này lớn là bình thường — nó là cái giá của việc thà bỏ sót
            // còn hơn báo oan.
            'skipped_ambiguous' => $skippedAmbiguous,
            'unresolved_models' => array_keys($unresolvedModels),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $findings === [] ? self::SUCCESS : self::FAILURE;
        }

        foreach ($findings as $f) {
            $this->error(sprintf('%s:%d  %s::…->%s(\'%s\')', $f['file'], $f['line'], $f['model'], $f['method'], $f['column']));
        }

        $this->line(sprintf('bỏ qua %d chuỗi mơ hồ · dạng $var->attr KHÔNG được quét', $skippedAmbiguous));

        return $findings === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: int}> */
    private function columnMentions(string $source, int &$skipped): array
    {
        $source = $this->codeOnly($source);

        if (! preg_match_all("#\b([A-Z]\w+)::(?:query\(\)|[a-zA-Z]\w*\()#", $source, $starts, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return [];
        }

        $methods = implode('|', self::COLUMN_FIRST_ARG);
        $out = [];

        foreach ($starts as $i => $start) {
            $from = $start[0][1];
            // Biên phải = `;` (hoặc trần). CỐ Ý KHÔNG cắt ở `Model::` kế tiếp:
            // cắt như vậy thì một chuỗi LỒNG NHAU
            // (`Menu::…whereIn('id', MenuProduct::…)->value('branch_id')`) lọt
            // qua — span của `Menu::` dừng trước `MenuProduct::` nên không thấy
            // `::` thứ hai, rồi span của `MenuProduct::` nuốt luôn `value()` của
            // Menu. Để span chạy tới `;` thì dấu hiệu `::` thứ hai bắt được, và
            // CẢ câu lệnh bị từ chối — thà bỏ sót còn hơn gán nhầm.
            $end = min(
                ($semi = strpos($source, ';', $from)) === false ? PHP_INT_MAX : $semi,
                $from + 2000,
            );
            $span = substr($source, $from, $end - $from);

            // LỒNG NHAU: nếu từ `Model::` trước đó tới đây còn ngoặc CHƯA ĐÓNG
            // thì chuỗi này nằm bên trong chuỗi kia, và cái `->value()` ở cuối
            // câu thuộc về chuỗi NGOÀI. Dấu hiệu `::` thứ hai bắt được chuỗi
            // ngoài; cái này bắt chuỗi trong. Đếm ngoặc là đủ, không cần parser.
            $prev = $starts[$i - 1][0][1] ?? null;
            // CHỈ xét chuỗi model trước đó nếu nó nằm TRONG CÙNG câu lệnh. Không
            // chặn theo câu lệnh thì đoạn "ở giữa" trải qua code không liên quan,
            // ngoặc gần như luôn lệch, và bộ quét từ chối cả những chuỗi lành —
            // đã đo: nó nuốt mất chính ca #1645.
            $stmtStart = ($sc = strrpos(substr($source, 0, $from), ';')) === false ? 0 : $sc + 1;

            if ($prev !== null && $prev >= $stmtStart) {
                $between = substr($source, $prev, $from - $prev);
                if (substr_count($between, '(') > substr_count($between, ')')) {
                    $skipped++;

                    continue;
                }
            }

            foreach (self::AMBIGUITY_MARKERS as $marker) {
                if (preg_match($marker, $span)) {
                    $skipped++;

                    continue 2;
                }
            }

            if (! preg_match_all("#->({$methods})\(\s*'([a-z0-9_]+)'#", $span, $hits, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                continue;
            }

            foreach ($hits as $hit) {
                $out[] = [
                    $start[1][0],
                    $hit[1][0],
                    $hit[2][0],
                    1 + substr_count(substr($source, 0, $from + $hit[0][1]), "\n"),
                ];
            }
        }

        return $out;
    }

    /**
     * Xoá comment, giữ NGUYÊN mọi thứ khác — kể cả độ dài.
     *
     * #2511: bộ quét đọc docblock y như đọc mã, nên một câu văn MÔ TẢ lỗi đã
     * sửa bị đếm thành chính lỗi đó (`EloquentDeviceDirectory:14` giải thích
     * `->pluck('device')` của chỗ gọi cũ mà #1666 đã thay). Đúng khuôn #1921
     * gọi tên khi thêm `businessTimeCodeOnly()`.
     *
     * Hai chi tiết không được đổi, vì phần còn lại của lớp này chạy trên
     * OFFSET BYTE của chuỗi trả về:
     *
     * - **Thay bằng khoảng trắng, không cắt bỏ.** Cắt thì mọi offset sau đó
     *   dịch, và số dòng trong báo cáo (`substr_count(…, "\n")`) sai theo —
     *   một báo cáo trỏ nhầm dòng còn tệ hơn không báo.
     * - **Giữ `\n`.** Cùng lý do, và vì `\n` là thứ duy nhất bộ đếm dòng nhìn.
     *
     * Đoạn không mang thẻ `<?php` (bài test đơn vị nạp một mẩu câu lệnh) bị
     * `token_get_all` coi là HTML thuần và không comment nào bị bóc — nên ghép
     * thẻ mở vào rồi cắt lại đúng số byte đó. `<?php ` luôn ra một `T_OPEN_TAG`
     * đúng bằng chính nó, nên phép cắt không lệch.
     */
    private function codeOnly(string $source): string
    {
        $prefix = str_contains($source, '<?php') ? '' : '<?php ';
        $out = '';

        foreach (token_get_all($prefix.$source) as $token) {
            if (! is_array($token)) {
                $out .= $token;

                continue;
            }

            $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                ? preg_replace('/[^\n]/', ' ', $token[1])
                : $token[1];
        }

        return $prefix === '' ? $out : substr($out, strlen($prefix));
    }

    /** @return array<string, class-string<Model>> */
    private function modelsByShortName(): array
    {
        $out = [];

        foreach ($this->phpFiles(app_path('Models')) as $path) {
            $fqcn = 'App\\Models\\'.basename($path, '.php');

            if (! class_exists($fqcn)) {
                continue;
            }

            $reflection = new ReflectionClass($fqcn);

            if ($reflection->isSubclassOf(Model::class) && ! $reflection->isAbstract()) {
                $out[basename($path, '.php')] = $fqcn;
            }
        }

        return $out;
    }

    /**
     * Cột thật của model, hoặc `null` khi bảng không tồn tại.
     *
     * `null` (BỎ QUA) chứ không phải mảng rỗng: mảng rỗng biến MỌI tên cột của
     * model đó thành "cột ma" và chôn báo cáo thật dưới nhiễu.
     *
     * @return list<string>|null
     */
    private function columnsFor(string $modelClass): ?array
    {
        static $cache = [];

        if (array_key_exists($modelClass, $cache)) {
            return $cache[$modelClass];
        }

        $table = (new $modelClass)->getTable();

        return $cache[$modelClass] = Schema::hasTable($table) ? Schema::getColumnListing($table) : null;
    }

    /** @return list<string> */
    private function phpFiles(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $out = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }
}
