<?php

namespace App\Services\Loyalty\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * #1441 — từ chối một lượt đổi điểm.
 *
 * Mỗi lý do có `error` riêng để customer-web nói được câu đúng: "không đủ
 * điểm" và "phần thưởng đã ngừng" là hai tình huống khác hẳn nhau với khách
 * đang cầm điện thoại, dù cùng là 422.
 */
class PointRedemptionException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function disabled(): self
    {
        return new self('Point redemption is not available.', 'POINTS_DISABLED', 404);
    }

    public static function rewardUnavailable(): self
    {
        return new self('This reward is no longer available.', 'REWARD_UNAVAILABLE');
    }

    /**
     * Tách khỏi `rewardUnavailable()` có chủ đích: "hết hàng" là chuyện tạm
     * thời và khách nên quay lại, "đã ngừng" thì đừng chờ nữa. Gộp chung một
     * mã thì customer-web chỉ nói được một câu cho hai tình huống trái ngược.
     */
    public static function rewardOutOfStock(): self
    {
        return new self('This reward is out of stock.', 'REWARD_OUT_OF_STOCK');
    }

    public static function insufficientPoints(int $balance, int $cost): self
    {
        return new self(
            'Not enough points.',
            'INSUFFICIENT_POINTS',
            422,
            ['balance' => $balance, 'required' => $cost],
        );
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => $this->errorCode,
            ...$this->context,
        ], $this->status);
    }
}
