<?php

declare(strict_types=1);

namespace App\Services\Payment\Contracts;

use App\Models\PaymentMethod;
use App\Services\Omnify\PaymentMethodService;

/**
 * Public mutation contract for legacy HQ PaymentMethod CRUD.
 *
 * Transports inject this interface — not {@see PaymentMethodService}
 * directly — so the domain-mutation guard does not treat Omnify base CRUD as a
 * consumer bypass.
 */
interface PaymentMethodMutationFacade
{
    public function create(array $data): PaymentMethod;

    public function update(PaymentMethod $method, array $data): PaymentMethod;

    public function delete(PaymentMethod $method): bool;

    public function restore(PaymentMethod $method): PaymentMethod;

    /**
     * @param  array<int, string>  $paymentMethodIds
     */
    public function reorder(string $organizationId, array $paymentMethodIds): void;
}
