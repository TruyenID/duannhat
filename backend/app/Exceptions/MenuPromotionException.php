<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * Plan-019 — domain exception for MenuPromotion mutations + the
 * stacking-guard at addItems. Mirrors CouponException's contract
 * (error_code → i18n key, structured meta).
 */
class MenuPromotionException extends \RuntimeException
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message = '',
        public readonly array $meta = [],
    ) {
        parent::__construct($message !== '' ? $message : $errorCode);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error_code' => $this->errorCode,
            'meta' => $this->meta,
        ], $this->status);
    }

    public static function alreadyUsedUseDeactivateInstead(int $itemCount): self
    {
        return new self(
            'promotion_already_used_use_deactivate_instead',
            409,
            'Promotion has been applied to at least one order item; deactivate instead of deleting.',
            ['items_with_promotion_count' => $itemCount],
        );
    }

    public static function lockedField(string $field, int $itemCount): self
    {
        return new self(
            'promotion_field_locked',
            422,
            "Field [{$field}] cannot be edited after the promotion has been applied to ≥1 order item.",
            ['field' => $field, 'items_with_promotion_count' => $itemCount],
        );
    }

    /**
     * Stacking guard reverse direction — order already has a coupon and
     * the addItems call is trying to add a product whose currently-active
     * promotion is exclusive_with_coupons. POS resolves via a confirm
     * dialog "auto-remove coupon to add this item?" (Decision B7).
     *
     * @param  array{id: string, product_sku_id: string, applied_promotion_id: string}  $blockedItem
     */
    public static function cannotAddPromotionItemWithCoupon(string $couponId, array $blockedItem): self
    {
        return new self(
            'cannot_add_promotion_item_with_coupon',
            422,
            'Cannot add a promotion-discounted item while a coupon is applied; release the coupon first.',
            [
                'suggested_action' => 'release_coupon_then_retry',
                'coupon_id' => $couponId,
                'blocked_item' => $blockedItem,
            ],
        );
    }
}
