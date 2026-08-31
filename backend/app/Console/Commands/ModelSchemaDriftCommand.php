<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionNamedType;

/**
 * #1657 — model KHAI một cột mà bảng của nó KHÔNG có.
 *
 * Cùng họ với `PhantomColumnsCommand` (#1653) nhưng đo thứ khác, và đo được
 * **chính xác**: `$fillable`, `$casts`, `$primaryKey` là dữ liệu tĩnh trên model,
 * không phải chuỗi trôi trong một câu truy vấn — không cần suy luận kiểu, không
 * cần từ chối chỗ mơ hồ. So thẳng với `Schema::getColumnListing()`.
 *
 * ## Ba trục, và vì sao mỗi trục hỏng theo kiểu KHÔNG kêu tiếng nào
 *
 * | trục | hỏng thế nào |
 * |---|---|
 * | `$fillable` | `create([... 'x' => $v])` **im lặng bỏ** `x`; không exception, dữ liệu chỉ đơn giản là không tới DB |
 * | `$casts` | cast một cột không có = không bao giờ chạy; ngày tháng/bool đọc lên nguyên dạng thô |
 * | `$primaryKey` | `save()` phát `WHERE <khoá> IS NULL` → **0 dòng, không lỗi**, `save()` vẫn trả `true` |
 *
 * Trục thứ ba là trục đắt nhất và là trục duy nhất còn phát hiện: xem phần dưới.
 *
 * ## Ba lớp dương tính giả PHẢI trừ, nếu không báo cáo thành rác
 *
 * **1. Thuộc tính `type: File` của Omnify.** `Product.thumbnail`, `Product.gallery`,
 * `PointReward.image` nằm trong `$fillable` nhưng KHÔNG phải cột — chúng là quan
 * hệ morph tới bảng `files`, và có mặt trong `$fillable` là **đúng quy ước**
 * (trait `HasFiles` nhận giá trị rồi tự ghi sang bảng kia). Nhận diện bằng: tên đó
 * cũng là một method trả về `Relation`. Không trừ lớp này thì 3/5 phát hiện đầu
 * tiên là báo oan.
 *
 * **2. Cast khoá chính do Laravel TỰ CHÈN.** `Model::getCasts()` merge thêm
 * `[$keyName => $keyType]` khi `$incrementing` bật — nên `getCasts()` của một model
 * khoá-ma luôn chứa một khoá ma, dù model **không khai** cast nào như vậy. Trừ đúng
 * phần Laravel chèn (chứ không đọc `casts()` bằng reflection) vì đọc reflection sẽ
 * **bỏ sót** cast do trait `mergeCasts` thêm vào. Ca "khai cast trên chính khoá ma"
 * không bị mất: trục `$primaryKey` đã báo nó rồi.
 *
 * **3. Attribute ẢO có setter (#2041).** `CustomerOrder.discount_amount`,
 * `.service_charge`, `.tax_amount` không còn là cột — chúng là `Attribute` ghi
 * xuyên xuống `order_conditions`. Laravel đòi một attribute phải có trong
 * `$fillable` mới mass-assign được, **kể cả attribute ảo**, nên có mặt ở đó là
 * đúng quy ước — cùng loại ngoại lệ với `PointReward.image` ở mục 1.
 *
 * Trừ bằng **set** mutator (`hasSetMutator`/`hasAttributeSetMutator`), KHÔNG phải
 * `isAccessor()` mà trục `$casts` dùng. Hai trục hỏi hai câu khác nhau: `$casts`
 * hỏi "đọc lên có đi qua accessor không", còn `$fillable` hỏi "ghi xuống có ai
 * NHẬN không". Một attribute chỉ có `get:` mà nằm trong `$fillable` thì
 * `create([... 'x' => $v])` vẫn **im lặng bỏ** `$v` — đúng hồi quy trục này sinh
 * ra để bắt. Trừ theo accessor sẽ che mất chính lớp lỗi đó.
 *
 * ## Kết quả đo (2026-08-02, 172 model có bảng)
 *
 * `$fillable` **0** · `$casts` **0** · `$primaryKey` **2**.
 *
 * Hai cái còn lại là `InvoiceCounter` (`"branch_id,year_month"`) và
 * `MenuMenuSection` (`"id"`) — bảng khoá kép, mà Eloquent không diễn đạt được. Đã
 * đo tận tay: `save()` trên dòng đã tồn tại **không nổ, và không ghi gì**. Hiện
 * chưa ai ghi qua model (`InvoiceNumberAllocator` đi `DB::table`, pivot đi
 * `attach`/`updateExistingPivot`), nên đây là bẫy **tiềm ẩn** chứ chưa phải lỗi
 * đang chạy — và `RefusesKeylessWrites` biến nó thành tiếng nổ thay vì mất dữ liệu im lặng.
 */
final class ModelSchemaDriftCommand extends Command
{
    protected $signature = 'architecture:model-schema-drift {--json}';

    protected $description = 'Tìm model khai cột không tồn tại trong $fillable / $casts / $primaryKey (#1657)';

    public function handle(): int
    {
        $fillable = [];
        $casts = [];
        $primaryKey = [];
        $scanned = 0;

        foreach ($this->models() as $model) {
            $table = $model->getTable();

            if (! Schema::hasTable($table)) {
                continue;
            }

            $scanned++;
            $columns = Schema::getColumnListing($table);
            $short = (new ReflectionClass($model))->getShortName();

            foreach ($model->getFillable() as $attribute) {
                if (in_array($attribute, $columns, true)
                    || $this->isRelation($model, $attribute)
                    || $this->isSettableAttribute($model, $attribute)) {
                    continue;
                }

                $fillable[] = ['model' => $short, 'table' => $table, 'attribute' => $attribute];
            }

            foreach (array_keys($this->declaredCasts($model)) as $attribute) {
                if (in_array($attribute, $columns, true) || $this->isAccessor($model, $attribute)) {
                    continue;
                }

                $casts[] = ['model' => $short, 'table' => $table, 'attribute' => $attribute];
            }

            if (! in_array($model->getKeyName(), $columns, true)) {
                $primaryKey[] = [
                    'model' => $short,
                    'table' => $table,
                    'attribute' => $model->getKeyName(),
                    'incrementing' => $model->getIncrementing(),
                ];
            }
        }

        $report = [
            'models_scanned' => $scanned,
            'fillable_count' => count($fillable),
            'fillable' => $fillable,
            'casts_count' => count($casts),
            'casts' => $casts,
            'primary_key_count' => count($primaryKey),
            'primary_key' => $primaryKey,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $this->exitCode($report);
        }

        foreach (['fillable' => $fillable, 'casts' => $casts, 'primary_key' => $primaryKey] as $axis => $rows) {
            foreach ($rows as $row) {
                $this->error(sprintf('%s::$%s → "%s" không phải cột của `%s`', $row['model'], $axis, $row['attribute'], $row['table']));
            }
        }

        $this->line(sprintf('quét %d model có bảng · fillable %d · casts %d · primaryKey %d',
            $scanned, count($fillable), count($casts), count($primaryKey)));

        return $this->exitCode($report);
    }

    /**
     * @param  array{fillable_count: int, casts_count: int, primary_key_count: int}  $report
     */
    private function exitCode(array $report): int
    {
        $total = $report['fillable_count'] + $report['casts_count'] + $report['primary_key_count'];

        return $total === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * `$casts` model tự khai, KHÔNG kèm phần Laravel merge cho khoá chính.
     *
     * @return array<string, mixed>
     */
    private function declaredCasts(Model $model): array
    {
        $casts = $model->getCasts();

        if ($model->getIncrementing()) {
            unset($casts[$model->getKeyName()]);
        }

        return $casts;
    }

    /**
     * Tên này là một QUAN HỆ chứ không phải cột — dạng `type: File` của Omnify.
     *
     * Kiểm bằng KIỂU TRẢ VỀ, không gọi method: gọi sẽ dựng truy vấn, và với model
     * chưa có dữ liệu thì một số quan hệ ném lỗi.
     */
    private function isRelation(Model $model, string $name): bool
    {
        if (! method_exists($model, $name)) {
            return false;
        }

        $type = (new ReflectionClass($model))->getMethod($name)->getReturnType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return false;
        }

        return is_a($type->getName(), Relation::class, true);
    }

    private function isAccessor(Model $model, string $name): bool
    {
        return $model->hasGetMutator($name) || $model->hasAttributeMutator($name);
    }

    /**
     * Attribute ảo NHẬN được giá trị mass-assign — ngoại lệ hợp lệ của `$fillable`.
     *
     * Cố ý hỏi **set** chứ không phải get: `$fillable` nói về đường GHI. Một
     * attribute chỉ có `get:` thì mass-assign vẫn rơi vào hư không, và đó là lỗi
     * trục này phải báo chứ không phải trừ đi.
     */
    private function isSettableAttribute(Model $model, string $name): bool
    {
        return $model->hasSetMutator($name) || $model->hasAttributeSetMutator($name);
    }

    /** @return list<Model> */
    private function models(): array
    {
        $out = [];

        foreach (glob(app_path('Models').'/*.php') ?: [] as $path) {
            $fqcn = 'App\\Models\\'.basename($path, '.php');

            if (! class_exists($fqcn)) {
                continue;
            }

            $reflection = new ReflectionClass($fqcn);

            if ($reflection->isSubclassOf(Model::class) && ! $reflection->isAbstract()) {
                $out[] = new $fqcn;
            }
        }

        return $out;
    }
}
