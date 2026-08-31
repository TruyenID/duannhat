<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

/**
 * #2479 — đưa một đơn từ `checkout` trở lại `open` để sửa được.
 *
 * `reason` KHÔNG nullable, cố ý. Reopen là đường duy nhất mở lại một bill đã
 * chốt; nếu nó không để lại lý do thì nó thành cách sửa tiền mà không ai thấy,
 * rẻ hơn hẳn void (vốn luôn có lý do). Bắt buộc lý do là thứ giữ cho hai đường
 * ra khỏi `checkout` cân nhau về mặt dấu vết.
 */
final readonly class ReopenOrderCommand extends MutationCommand
{
    public string $orderId;

    public string $reason;

    public function __construct(MutationContext $context, string $orderId, string $reason)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->orderId = self::uuid($orderId, 'orderId');
        $this->reason = self::safeText($reason, 'reason', 500);
    }
}
