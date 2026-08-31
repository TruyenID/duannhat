<?php

/**
 * WorkstationLogRecord Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\WorkstationLogRecord\Models\WorkstationLogRecordBaseModel;
use Database\Factories\WorkstationLogRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use LogicException;

/**
 * WorkstationLogRecord (#2901) — một dòng log đã kéo về, BẤT BIẾN sau khi ghi.
 *
 * ## Vì sao chặn SỬA
 *
 * Unique index `(device_id, local_id)` chặn việc TẠO trùng, nhưng một
 * `updateOrCreate()` hay `firstOrNew()->save()` viết sau này vẫn ghi đè được
 * nội dung cũ mà không vi phạm ràng buộc nào. Hợp đồng #2901 nói rõ: gửi lại
 * cùng khoá là `duplicates++`, **KHÔNG cập nhật hàng cũ** — nội dung khác trên
 * cùng khoá nghĩa là có bug ở đầu kia, và ghi đè xoá mất dấu vết của chính bug
 * đó. Cùng khuôn {@see OrderMoneyOverwrite} (#2885).
 *
 * ## Vì sao KHÔNG chặn XOÁ (khác #2885)
 *
 * Bảng kia là bằng chứng tiền, append-only vĩnh viễn. Bảng này là bộ đệm chẩn
 * đoán chở PII với hạn giữ **14 ngày** — chặn xoá ở đây sẽ chặn luôn
 * `workstation-logs:prune`, tức biến một cam kết về quyền riêng tư thành một
 * `LogicException` hằng đêm. Xoá được là một phần của thiết kế, không phải một
 * lỗ hổng của nó.
 */
class WorkstationLogRecord extends WorkstationLogRecordBaseModel
{
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Dòng log máy trạm là bất biến — gửi lại cùng (device_id, local_id) là no-op, không phải cập nhật (#2901).');
        });
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): WorkstationLogRecordFactory
    {
        return WorkstationLogRecordFactory::new();
    }

    //
}
