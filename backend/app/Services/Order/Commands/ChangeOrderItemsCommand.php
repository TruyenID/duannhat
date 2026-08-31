<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Order\Enums\OrderItemMutation;
use App\Services\Order\ValueObjects\OrderLineSelectionPayload;

final readonly class ChangeOrderItemsCommand extends MutationCommand
{
    public string $orderId;

    public ?string $itemId;

    public string $lineFingerprint;

    public function __construct(
        MutationContext $context,
        string $orderId,
        public OrderItemMutation $operation,
        string $lineFingerprint,
        public OrderLineSelectionPayload $payload,
        ?string $itemId = null,
        /**
         * #1715 — giá một đơn vị client đang HIỂN THỊ cho dòng này. Server chỉ
         * dùng để TỪ CHỐI (409 `line_unit_price_drift`), không bao giờ để tính.
         *
         * Cố ý nằm NGOÀI `$payload`: payload là `CanonicalMutationPayload` và
         * `lineFingerprint` được xác minh theo nó. Con số client *đoán* không
         * thuộc về phát biểu chính tắc "khách đã chọn gì", nên để trong đó sẽ
         * làm fingerprint đổi theo một thứ không phải lựa chọn của khách.
         */
        public ?float $expectedUnitPrice = null,
    ) {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->orderId = self::uuid($orderId, 'orderId');
        $this->itemId = self::nullableUuid($itemId, 'itemId');
        $this->lineFingerprint = self::verifiedFingerprint($lineFingerprint, 'lineFingerprint', $payload);

        if ($this->itemId !== null && $this->itemId !== $payload->lineId) {
            throw new \InvalidArgumentException('itemId must identify the supplied order-line payload.');
        }
    }
}
