<?php

/**
 * Plan-019 — architecture invariants for the Coupon + MenuPromotion
 * domain. Catches regressions where a future commit:
 *   - re-implements the user-editable model without extending the
 *     omnify base (which would lose translatable + audit + casts);
 *   - re-implements the user-editable service without extending the
 *     omnify base (lookup + paginated list scaffolding);
 *   - changes a policy to extend the omnify policy base (per project
 *     convention the user-editable policies stand alone, no extends).
 */
arch('Coupon model extends omnify base')
    ->expect('App\Models\Coupon')
    ->toExtend('App\Omnify\Modules\Coupon\Models\CouponBaseModel');

arch('CouponRedemption model extends omnify base')
    ->expect('App\Models\CouponRedemption')
    ->toExtend('App\Omnify\Modules\CouponRedemption\Models\CouponRedemptionBaseModel');

arch('MenuPromotion model extends omnify base')
    ->expect('App\Models\MenuPromotion')
    ->toExtend('App\Omnify\Modules\MenuPromotion\Models\MenuPromotionBaseModel');

arch('CouponService extends omnify base')
    ->expect('App\Services\Promotion\CouponService')
    ->toExtend('App\Omnify\Modules\Coupon\Services\CouponServiceBase');

arch('MenuPromotionService implements the typed mutation facade contract')
    ->expect('App\Services\Promotion\MenuPromotionService')
    ->toImplement('App\Services\Promotion\Contracts\MenuPromotionMutationFacade');

arch('CouponPolicy is a standalone class, NOT extending the omnify base')
    ->expect('App\Policies\CouponPolicy')
    ->classes()
    ->not->toExtend('App\Omnify\Modules\Coupon\Policies\CouponPolicyBase');

arch('CouponException renders structured 4xx responses')
    ->expect('App\Exceptions\CouponException')
    ->toExtend(RuntimeException::class);

arch('MenuPromotionException renders structured 4xx responses')
    ->expect('App\Exceptions\MenuPromotionException')
    ->toExtend(RuntimeException::class);

arch('promotion services live under App\\Services\\Promotion')
    ->expect('App\Services\Promotion')
    ->classes()
    ->toBeClasses();
