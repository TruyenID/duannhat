<?php

/**
 * PaymentMethod Service
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Services\Omnify;

use App\Models\PaymentMethod;
use App\Omnify\Modules\PaymentMethod\Services\PaymentMethodServiceBase;
use App\Services\Payment\Contracts\PaymentMethodMutationFacade;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentMethodService extends PaymentMethodServiceBase implements PaymentMethodMutationFacade
{
    /**
     * Create a new PaymentMethod, appending it at the end of the sort order.
     *
     * sort_order is always assigned server-side as max(sort_order) + 1 within
     * the organization so users cannot manipulate ordering via the create form.
     */
    protected function applyListEagerLoads($query): void
    {
        $query->with('translations');
    }

    protected function applyFindByIdEagerLoads($query): void
    {
        $query->with('translations');
    }

    public function create(array $data): PaymentMethod
    {
        $organizationId = $data['organization_id']
            ?? request()->attributes->get('organization_id');

        $nextSortOrder = (int) PaymentMethod::where('organization_id', $organizationId)
            ->max('sort_order') + 1;

        $data['sort_order'] = $nextSortOrder;

        return parent::create($data)->load('translations');
    }

    public function update(PaymentMethod $model, array $data): PaymentMethod
    {
        return parent::update($model, $data)->load('translations');
    }

    public function restore(PaymentMethod $model): PaymentMethod
    {
        return parent::restore($model)->load('translations');
    }

    /**
     * Reorder payment methods by assigning sequential sort_order values.
     *
     * Uses the same 2-phase pattern as MenuService::reorderMenus() to avoid
     * temporary constraint conflicts during the update sweep.
     *
     * @param  array<int, string>  $paymentMethodIds  ordered IDs from the UI
     *
     * @throws ValidationException when ids contain duplicates or don't match the org's full set
     */
    public function reorder(string $organizationId, array $paymentMethodIds): void
    {
        if (count($paymentMethodIds) !== count(array_unique($paymentMethodIds))) {
            throw ValidationException::withMessages([
                'payment_method_ids' => ['payment_method_ids must not contain duplicate IDs.'],
            ]);
        }

        $orgTotal = PaymentMethod::where('organization_id', $organizationId)->count();

        $matchedCount = PaymentMethod::where('organization_id', $organizationId)
            ->whereIn('id', $paymentMethodIds)
            ->count();

        if ($matchedCount !== count($paymentMethodIds)) {
            throw ValidationException::withMessages([
                'payment_method_ids' => ['One or more IDs do not belong to this organisation.'],
            ]);
        }

        if ($orgTotal !== count($paymentMethodIds)) {
            throw ValidationException::withMessages([
                'payment_method_ids' => ["payment_method_ids must cover all payment methods. Expected {$orgTotal}, received ".count($paymentMethodIds).'.'],
            ]);
        }

        DB::transaction(function () use ($organizationId, $paymentMethodIds) {
            // Phase 1: move to negative values to free positive slots
            PaymentMethod::where('organization_id', $organizationId)
                ->whereIn('id', $paymentMethodIds)
                ->update(['sort_order' => DB::raw('0 - sort_order')]);

            // Phase 2: assign final 1-based sort_order
            foreach ($paymentMethodIds as $index => $id) {
                PaymentMethod::where('organization_id', $organizationId)
                    ->where('id', $id)
                    ->update(['sort_order' => $index + 1]);
            }
        });
    }
}
