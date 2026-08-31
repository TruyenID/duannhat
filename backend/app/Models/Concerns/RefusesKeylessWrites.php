<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use LogicException;

/**
 * #1657 — model có khoá chính KHÔNG tồn tại trong bảng: nổ thay vì mất im lặng.
 *
 * Bảng khoá kép (`invoice_counters` = branch_id+year_month, `menu_menu_sections`
 * = menu_id+menu_section_id) không có cột nào làm khoá đơn, mà Eloquent thì bắt
 * buộc phải có một `$primaryKey`. Hệ quả đo được:
 *
 * ```php
 * $row = MenuMenuSection::query()->first();   // đọc OK
 * $row->display_order = 99;
 * $row->save();                               // trả TRUE
 * // → UPDATE … WHERE "id" is null → 0 dòng. Giá trị trong DB VẪN LÀ CŨ.
 * ```
 *
 * Không exception, không log, `save()` báo thành công. Đúng cùng khuôn với họ lỗi
 * "cột ma" (#1614/#1645/#1651/#1655): sai mà không kêu tiếng nào.
 *
 * Trait này chặn ở `updating` và `deleting` — hai chỗ Eloquent dựng mệnh đề WHERE
 * từ khoá chính. **Không** chặn `creating`: lúc insert khoá null là chuyện bình
 * thường, và insert vẫn ghi đúng (nó không cần WHERE).
 *
 * Đây là RÀO, không phải bản sửa. Cách ghi đúng cho hai bảng này vẫn là
 * `DB::table(...)` với đủ cột khoá (xem `InvoiceNumberAllocator`),
 * hoặc `attach()`/`updateExistingPivot()` cho pivot.
 */
trait RefusesKeylessWrites
{
    protected static function bootRefusesKeylessWrites(): void
    {
        foreach (['updating', 'deleting'] as $event) {
            static::registerModelEvent($event, function (self $model) use ($event): void {
                if ($model->getKey() !== null) {
                    return;
                }

                throw new LogicException(sprintf(
                    '%s::%s() bị chặn: bảng `%s` khoá kép nên $primaryKey="%s" luôn null, '
                    .'câu lệnh sẽ chạy WHERE NULL và ÂM THẦM không đụng dòng nào. '
                    .'Ghi qua DB::table() với đủ cột khoá (#1657).',
                    static::class,
                    $event === 'updating' ? 'save' : 'delete',
                    $model->getTable(),
                    $model->getKeyName(),
                ));
            });
        }
    }
}
