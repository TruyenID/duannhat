<?php

/**
 * OrderPayment Store Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\PaymentMethod;
use App\Models\TillTenderType;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Omnify\Modules\OrderPayment\Requests\OrderPaymentStoreRequestBase;
use App\Services\Order\Enums\OrderSplitMode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
/**
 * OrderPaymentStoreRequest — add project-specific authorization and validation here.
 *
 * Inherited from base:
 *   - authorize(): bool  (returns true — override for auth checks)
 *   - rules(): array     (returns schemaRules() — override to add custom rules)
 *   - attributes(): array (returns schemaAttributes() — override to rename fields)
 */
use Illuminate\Validation\ValidationException;

class OrderPaymentStoreRequest extends OrderPaymentStoreRequestBase
{
    public function rules(): array
    {
        return [
            'payment_method_id' => ['required', 'uuid', $this->paymentMethodExistsRule()],
            'amount' => ['required', 'numeric', 'gt:0'],
            'tip_amount' => ['nullable', 'numeric', 'min:0'],
            'tendered_amount' => ['nullable', 'numeric'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            // #1156 — optional brand-level tender attribution. Validated
            // against the order org's tender vocabulary when present; NEVER
            // required (absence must not block any payment path).
            'tender_key' => ['nullable', 'string', 'max:50', $this->tenderKeyExistsRule()],
            'note' => ['nullable', 'string'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            'expected_total_amount' => ['nullable', 'numeric', 'min:0'],

            // Plan 021 — split-bill audit metadata. Persisted on the row as-is
            // (informational; no business logic reads these keys).
            // Plan 038 — enum extended with `by_amount` (split-bill by explicit
            // per-person amount; see split-bill-by-amount-tab.tsx).
            'metadata' => ['nullable', 'array'],
            // #2860 — MỘT nguồn sinh luật cho cả hai validator. Trước đây chỗ
            // này gõ tay `equal,by_items,by_amount` còn endpoint đặt chế độ trên
            // đơn gõ tay `by_people,by_items,custom`; hai tập giao nhau đúng một
            // giá trị và không gì đỏ suốt nhiều tháng.
            'metadata.split_mode' => ['nullable', 'string', OrderSplitMode::validationRule()],
            'metadata.bill_index' => ['nullable', 'integer', 'min:0'],
            'metadata.total_bills' => ['nullable', 'integer', 'min:1'],
            'metadata.label' => ['nullable', 'string', 'max:50'],
            'metadata.item_allocations' => ['nullable', 'array'],
            'metadata.item_allocations.*.item_id' => ['required_with:metadata.item_allocations.*', 'uuid', 'exists:customer_order_items,id'],
            'metadata.item_allocations.*.units' => ['required_with:metadata.item_allocations.*', 'integer', 'min:1'],
            // Plan 033 — informational pre-tax/pre-charge subtotal for this
            // sub-check, persisted for audit/reprint. Service-layer validator
            // does NOT read this for business logic — it recomputes the total
            // from item_allocations and compares against `amount` directly.
            'metadata.expected_sub_total' => ['nullable', 'numeric', 'min:0'],

            // Plan 038 — split by amount per person + debt settlement link.
            // by_amount: same shape as equal but with an explicit amount per row.
            // settles_payment_id: linkage from a settlement payment to the original debt.
            'metadata.amount' => ['nullable', 'numeric', 'min:0'],
            'metadata.settles_payment_id' => ['nullable', 'uuid'],

            // Plan 047 T6.6 — resolver-backed option/revision identity for POS payments.
            'gateway_option_id' => ['nullable', 'uuid', 'exists:payment_gateway_options,id'],
            'gateway_connection_id' => ['nullable', 'uuid', 'exists:payment_gateway_connections,id'],
            'policy_revision' => ['required_with:gateway_option_id', 'integer', 'min:1'],
        ];
    }

    /**
     * Tenant-scope the `payment_method_id` existence check.
     *
     * `PaymentMethod` is org/branch-owned (BR-PM05: `branch_id` NULL = the
     * method is shared org-wide, a UUID = it belongs to that one branch). A
     * bare `exists:payment_methods,id` accepts ANY method id in the whole
     * table, so a caller authorised on shop A could stamp a payment with shop
     * B's (or another organisation's) private method — an IDOR / cross-tenant
     * leak of the payment-method dimension into A's ledger.
     *
     * Scope to methods that (a) belong to the order's organisation AND
     * (b) are either org-wide (branch_id NULL) or owned by the order's own
     * branch. Soft-deleted methods are excluded — the model's SoftDeletes
     * scope would make the service's later `findOrFail` throw anyway.
     */
    private function paymentMethodExistsRule(): Exists
    {
        $order = $this->route('customerOrder');
        $order = $order instanceof CustomerOrder
            ? $order
            : CustomerOrder::query()->select(['id', 'organization_id', 'branch_id'])->find($order);

        $organizationId = $order?->organization_id;
        $branchId = $order?->branch_id;

        return Rule::exists('payment_methods', 'id')
            ->where(function ($query) use ($organizationId, $branchId) {
                $query
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true)
                    ->where(function ($scope) use ($branchId) {
                        $scope->whereNull('branch_id')
                            ->orWhere('branch_id', $branchId);
                    })
                    ->whereNull('deleted_at');
            });
    }

    /**
     * #1156 — a supplied `tender_key` must be an existing ACTIVE row of the
     * order org's tender vocabulary (`till_tender_types`): either an
     * org-wide row (branch_id NULL) or one scoped to the order's own branch.
     * Same tenant-scoping rationale as paymentMethodExistsRule() — a bare
     * exists check would let a caller stamp another org's vocabulary onto
     * this ledger. Purely attributional: it never changes any amount, and
     * omitting it is always allowed.
     */
    private function tenderKeyExistsRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $order = $this->route('customerOrder');
            $order = $order instanceof CustomerOrder
                ? $order
                : CustomerOrder::query()->select(['id', 'organization_id', 'branch_id'])->find($order);

            $exists = TillTenderType::query()
                ->where('organization_id', $order?->organization_id)
                ->where('tender_key', (string) $value)
                ->where('is_active', true)
                ->where(function ($scope) use ($order) {
                    $scope->whereNull('branch_id')
                        ->orWhere('branch_id', $order?->branch_id);
                })
                ->exists();

            if (! $exists) {
                $fail(__("The tender key ':key' is not part of this shop's tender vocabulary.", ['key' => (string) $value]));
            }
        };
    }

    /**
     * Plan-038 T10.3 + T10.4 — enforce debt-specific invariants.
     *
     *   - `on_account` payment method requires `order.customer_id`.
     *   - `settles_payment_id` (if set) must reference a confirmed `on_account`
     *     payment on the same customer that has not already been settled.
     *
     * Runs after rules() — short-circuits with a translated key the FE
     * surfaces via apiFetch's ApiError envelope.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $methodId = $this->input('payment_method_id');
            if (! $methodId) {
                return;
            }
            $method = PaymentMethod::query()
                ->select(['id', 'type'])
                ->whereKey($methodId)
                ->first();
            if (! $method) {
                return;
            }

            if ($method->type === 'on_account') {
                $orderId = $this->route('customerOrder');
                $order = is_object($orderId)
                    ? $orderId
                    : CustomerOrder::query()->select(['id', 'customer_id'])->whereKey($orderId)->first();
                if (! $order || ! $order->customer_id) {
                    $v->errors()->add('payment_method_id', 'customer_required_for_debt');
                    throw new ValidationException($v);
                }
            }

            $settlesId = $this->input('metadata.settles_payment_id');
            if ($settlesId) {
                $orig = OrderPayment::query()
                    ->with(['paymentMethod:id,type'])
                    ->whereKey($settlesId)
                    ->first();
                if (! $orig) {
                    $v->errors()->add('metadata.settles_payment_id', 'settles_payment_not_found');
                    throw new ValidationException($v);
                }
                if (! $orig->paymentMethod || $orig->paymentMethod->type !== 'on_account') {
                    $v->errors()->add('metadata.settles_payment_id', 'settles_wrong_type');
                    throw new ValidationException($v);
                }
                // `status` casts to PaymentStatusEnum, so an enum-vs-string
                // `!==` is ALWAYS true and would reject every settlement. Compare
                // the backing value. 'confirmed' is kept alongside 'succeeded' to
                // mirror the debt-eligibility filter in
                // Shop/DebtController::whereIn(['confirmed','succeeded']).
                $origStatus = $orig->status instanceof PaymentStatusEnum
                    ? $orig->status->value
                    : $orig->status;
                if ($origStatus !== 'confirmed' && $origStatus !== 'succeeded') {
                    $v->errors()->add('metadata.settles_payment_id', 'settles_not_confirmed');
                    throw new ValidationException($v);
                }
                // #821 A7 — `order_payments` has NO customer_id column, so
                // `$orig->customer_id` was always null and this guard never
                // fired: a settlement could be pointed at another customer's
                // debt. The owner lives on the debt's ORDER.
                $origCustomerId = CustomerOrder::query()
                    ->whereKey($orig->customer_order_id)
                    ->value('customer_id');

                $orderId = $this->route('customerOrder');
                $order = is_object($orderId)
                    ? $orderId
                    : CustomerOrder::query()->select(['id', 'customer_id'])->whereKey($orderId)->first();
                if ($order && $origCustomerId && (string) $origCustomerId !== (string) $order->customer_id) {
                    $v->errors()->add('metadata.settles_payment_id', 'settles_wrong_customer');
                    throw new ValidationException($v);
                }

                // #821 A4 — nothing compared the amount handed over against the
                // amount owed. A 5,000,000 debt could be cleared by a 30,000
                // coffee: the settlement is recorded, the debt drops off
                // /debts, and 4,970,000 evaporates with no audit trail.
                //
                // A debt is a single payment row and `settles_payment_id` is a
                // one-shot link — there is no partial-settlement model — so the
                // payment must cover the debt exactly.
                $settleAmount = (float) $this->input('amount');
                if (abs($settleAmount - (float) $orig->amount) > 0.01) {
                    $v->errors()->add('metadata.settles_payment_id', 'settles_amount_mismatch');
                    throw new ValidationException($v);
                }

                // #821 A5 — which settlements count as "already settled".
                //
                // Originally this filtered nothing, so a FAILED settlement (declined
                // card) still counted: the debt vanished from /debts AND every retry
                // was rejected. Narrowing it to confirmed|succeeded fixed that but
                // opened the opposite hole — a PENDING settlement no longer blocked
                // anything. A non-auto-confirm method (card terminal) lets you create
                // two pending settlements against one ¥5,000,000 debt; confirm() only
                // checks that the row is Pending and re-validates nothing, so both
                // confirm and the customer is charged ¥10,000,000 for one debt.
                //
                // In flight (pending) or done (confirmed/succeeded) both block. Failed
                // and cancelled do not, so a declined card can be retried. Refunded
                // does not either — reversing a settlement must hand the debt back.
                // refund_of_id IS NULL excludes the settlement's own reversal, which
                // now inherits settles_payment_id (see OrderPaymentService::refund)
                // and would otherwise block re-settlement forever.
                $alreadySettled = OrderPayment::query()
                    ->where('metadata->settles_payment_id', $settlesId)
                    ->whereNull('refund_of_id')
                    ->whereIn('status', [
                        PaymentStatusEnum::Pending->value,
                        'confirmed',
                        PaymentStatusEnum::Succeeded->value,
                    ])
                    ->exists();
                if ($alreadySettled) {
                    $v->errors()->add('metadata.settles_payment_id', 'settles_already_settled');
                    throw new ValidationException($v);
                }
            }
        });
    }
}
