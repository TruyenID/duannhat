<?php

/**
 * OrderMoneyOverwrite Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\OrderMoneyOverwrite\Models\OrderMoneyOverwriteBaseModel;
use Database\Factories\OrderMoneyOverwriteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use LogicException;

/**
 * OrderMoneyOverwrite (#2885) — bằng chứng lệch tiền, APPEND-ONLY.
 *
 * Cùng khuôn với `PaymentPolicyRevision`: một bản ghi bằng chứng mà sửa được
 * thì không còn là bằng chứng. Unique index `(device_id, local_id)` ở tầng DB
 * chặn việc TẠO trùng; hai guard dưới đây chặn việc SỬA/XOÁ cái đã tạo.
 *
 * Cần cả hai: nếu chỉ có unique index, một `updateOrCreate()` viết sau này —
 * hoặc một `firstOrNew()->save()` — vẫn ghi đè được số cũ mà không vi phạm
 * ràng buộc nào. Mà theo hợp đồng #2885, số khác trên cùng khoá nghĩa là có
 * bug ở đầu kia, và ghi đè sẽ xoá mất dấu vết của chính bug đó.
 */
class OrderMoneyOverwrite extends OrderMoneyOverwriteBaseModel
{
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Bằng chứng ghi đè tiền là bất biến — không sửa được (#2885).');
        });

        static::deleting(function (): never {
            throw new LogicException('Bằng chứng ghi đè tiền là append-only — không xoá được (#2885).');
        });
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): OrderMoneyOverwriteFactory
    {
        return OrderMoneyOverwriteFactory::new();
    }

    //
}
