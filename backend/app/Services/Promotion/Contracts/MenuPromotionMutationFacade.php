<?php

declare(strict_types=1);

namespace App\Services\Promotion\Contracts;

use App\Models\MenuPromotion;

/**
 * Public mutation contract for shop MenuPromotion CRUD.
 *
 * Transports inject this interface — not {@see MenuPromotionService} directly —
 * so the domain-mutation guard does not treat Omnify base CRUD as a consumer
 * bypass ({@see DomainMutationGuard} generated_service_consumer).
 */
interface MenuPromotionMutationFacade
{
    public function create(array $data): MenuPromotion;

    public function update(MenuPromotion $promotion, array $data): MenuPromotion;

    public function delete(MenuPromotion $promotion): bool;

    public function restore(MenuPromotion $promotion): MenuPromotion;

    public function toggle(MenuPromotion $promotion): MenuPromotion;
}
