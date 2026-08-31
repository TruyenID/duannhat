<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * Plan-019 — domain exception class for every coupon-apply / release /
 * preview validation failure that should reach the FE as a structured
 * 4xx (coupon_not_found 404, coupon_paused / coupon_expired / etc. 422,
 * order_not_modifiable 409). The error_code key is the same string the
 * client uses to look up i18n strings, so changing it is a breaking
 * contract — see plans/plan-019/DESIGN.md endpoint #8 error table.
 */
class CouponException extends \RuntimeException
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

    public static function notFound(string $code): self
    {
        return new self('coupon_not_found', 404, "Coupon code [{$code}] not found in this brand.");
    }

    public static function paused(): self
    {
        return new self('coupon_paused', 422, 'Coupon is currently paused.');
    }

    public static function notStarted(\DateTimeInterface $validFrom): self
    {
        return new self('coupon_not_started', 422, 'Coupon is not yet active.', [
            'valid_from' => $validFrom->format(\DateTimeInterface::ATOM),
        ]);
    }

    public static function expired(\DateTimeInterface $validUntil): self
    {
        return new self('coupon_expired', 422, 'Coupon has expired.', [
            'valid_until' => $validUntil->format(\DateTimeInterface::ATOM),
        ]);
    }

    public static function branchNotEligible(): self
    {
        return new self('coupon_branch_not_eligible', 422, 'Coupon does not apply to this branch.');
    }

    public static function minSubtotalNotMet(float $required): self
    {
        return new self('coupon_min_subtotal_not_met', 422, 'Order subtotal is below the coupon minimum.', [
            'min_required' => $required,
        ]);
    }

    public static function exhausted(): self
    {
        return new self('coupon_exhausted', 422, 'Coupon usage limit reached.');
    }

    public static function customerRequired(): self
    {
        return new self('customer_required', 422, 'Coupon requires a customer to be identified.');
    }

    public static function alreadyUsedByCustomer(): self
    {
        return new self('coupon_already_used_by_customer', 422, 'Customer already used this coupon to its per-customer limit.');
    }

    /**
     * #1441 — coupon cá nhân (đổi từ điểm) bị người khác đem đi dùng.
     *
     * Tách riêng khỏi `coupon_not_found` một cách có chủ ý: mã có tồn tại
     * thật, và người cầm nó cần biết vì sao không dùng được, thay vì đi hỏi
     * quầy tại sao coupon "biến mất".
     */
    public static function notOwnedByCustomer(): self
    {
        return new self('coupon_not_yours', 422, 'This coupon belongs to a different customer.');
    }

    public static function noCouponApplied(): self
    {
        return new self('no_coupon_applied', 422, 'Order does not have a coupon to release.');
    }

    public static function orderNotModifiable(string|\BackedEnum $status): self
    {
        $statusValue = $status instanceof \BackedEnum ? $status->value : (string) $status;

        return new self('order_not_modifiable', 409, "Cannot apply or release a coupon when order is [{$statusValue}].", [
            'status' => $statusValue,
        ]);
    }

    public static function alreadyRedeemed(): self
    {
        return new self('coupon_already_redeemed_use_pause_instead', 409, 'Coupon already has redemptions; pause instead of deleting.');
    }

    public static function lockedField(string $field): self
    {
        return new self('coupon_field_locked', 422, "Field [{$field}] cannot be edited after the coupon has been redeemed.", [
            'field' => $field,
        ]);
    }

    /**
     * Stacking guard — the order has at least one item bound to a
     * MenuPromotion whose stacking_mode is `exclusive_with_coupons`.
     *
     * @param  array<int, string>  $exclusivePromotionIds
     * @param  array<int, string>  $exclusiveItemIds
     */
    public static function excludedByActivePromotion(array $exclusivePromotionIds, array $exclusiveItemIds): self
    {
        return new self('coupon_excluded_by_active_promotion', 422, 'Cart has at least one item under an exclusive promotion that blocks coupons.', [
            'exclusive_promotion_ids' => array_values($exclusivePromotionIds),
            'exclusive_item_ids' => array_values($exclusiveItemIds),
        ]);
    }
}
