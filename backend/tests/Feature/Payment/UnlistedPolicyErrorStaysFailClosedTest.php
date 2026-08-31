<?php

/**
 * #1856 — a policy error code that is NOT in `EMITTED_ERROR_CODES` must still
 * REFUSE the payment, even on the softened aliased path.
 *
 * `OrderPaymentService::assertPolicyAllowedOrObserve()` catches
 * `PaymentConfigurationException` and, for the two known policy verdicts, turns
 * the refusal into an observation while enforcement is optional. Anything else
 * is a genuine configuration fault and is rethrown:
 *
 *     if (! in_array($e->errorCode, PaymentPolicySubmissionValidator::EMITTED_ERROR_CODES, true)) {
 *         throw $e;
 *     }
 *
 * Measured while reviewing #1834: **deleting that guard left all 22 related
 * tests green.** Nothing pinned it. `PaymentPolicyErrorCodesStayObservableTest`
 * only checks that the validator's own `CODE_*` constants are listed — its own
 * docblock states that a code thrown from `PaymentPolicyEvaluationService` is
 * outside its scope, and that is exactly the code this guard exists to catch.
 *
 * Without the guard, catching the CLASS silently log-and-allows a fault that has
 * nothing to do with policy staleness — a fail-OPEN on the money path.
 *
 * WHERE THE FAULT IS INJECTED, and why it is not a mock of the thing under test:
 * `PaymentPolicySubmissionValidator` and `PaymentPolicyEvaluationService` are
 * both `final`, and stubbing either would replace the code path being measured.
 * `PaymentOwnerOptionPolicySource` is the real INTERFACE seam underneath both —
 * the validator calls `effectiveOptions()`, which consults this source, and
 * neither catches. So a throw here travels the genuine call chain into the
 * consumer's `catch`, which is precisely the "thrown anywhere on that path"
 * case the guard's own docblock names.
 */

use App\Models\OrderPayment;
use App\Models\PaymentGatewayOption;
use App\Models\PaymentMethod;
use App\Services\Payment\Configuration\Exceptions\PaymentConfigurationException;
use App\Services\Payment\Policy\Admin\PaymentPolicyEvaluationService;
use App\Services\Payment\Policy\Contracts\PaymentOwnerOptionPolicySource;
use App\Services\Payment\Policy\Enums\UpstreamPolicyState;
use App\Services\Payment\Policy\PaymentPolicySubmissionValidator;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Payment\PaymentPolicyApiFixtures;

/** A code no `CODE_*` constant on the validator declares. */
const UNLISTED_POLICY_CODE = 'PAYMENT_GATEWAY_CONNECTION_REVOKED';

beforeEach(function () {
    $this->fixtures = new PaymentPolicyApiFixtures;
    $this->fixtures->bind();
    $this->fixtures->seedConnection();
    $this->fixtures->seedCashPaymentMethod();

    // A GATEWAY-routed method, not cash. The rethrow guard sits above the
    // internal-tender exemption so cash would work too, but paying with a
    // tender that is exempt anyway would make this test pass even if the guard
    // moved below the exemption one day.
    PaymentMethod::factory()->create([
        'organization_id' => $this->fixtures->organization->id,
        'branch_id' => $this->fixtures->shop->id,
        'code' => 'card',
        'type' => 'card',
        'is_active' => true,
    ]);

    $this->optionId = (string) PaymentGatewayOption::query()->value('id');
    $this->device = $this->fixtures->seedWorkstationDevice();

    // Guard the premise. If someone later promotes this string to a real
    // `CODE_*` constant, the test would silently start measuring the OBSERVABLE
    // path instead and keep passing while proving nothing.
    expect(PaymentPolicySubmissionValidator::EMITTED_ERROR_CODES)
        ->not->toContain(UNLISTED_POLICY_CODE);
});

function payWithUnlistedPolicyFault(array $extra, object $ctx): TestResponse
{
    app()->bind(PaymentOwnerOptionPolicySource::class, fn () => new class implements PaymentOwnerOptionPolicySource
    {
        public function resolve(string $brandId, string $optionId): UpstreamPolicyState
        {
            throw new PaymentConfigurationException(
                'Gateway connection was revoked.',
                UNLISTED_POLICY_CODE,
                422,
            );
        }
    });

    // The evaluation service is a singleton; drop any instance built during
    // fixture setup so it is rebuilt with the throwing source above.
    app()->forgetInstance(PaymentPolicyEvaluationService::class);
    app()->forgetInstance(PaymentPolicySubmissionValidator::class);

    $order = $ctx->fixtures->seedCheckoutOrder(1500.0);
    $ctx->lastOrderId = $order->id;

    return test()->withHeaders([
        'Authorization' => "Bearer {$ctx->device->device_token}",
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/workstation/payments', array_merge([
        'order_id' => $order->id,
        'payment_method' => 'card',
        'amount' => 1500,
    ], $extra));
}

// ĐÃ GỠ #2410 — bài "trên đường ALIASED" đã xoá cùng lớp alias.
//
// Nó ghim cái guard `policy_identity_via_legacy_alias` trong `OrderPaymentService`,
// thứ nuốt lỗi policy khi danh tính chỉ tới được nhờ tên trường cũ. Lớp alias đã
// xoá (0 lượt thắng trên 252 payment workstation trong 7 ngày), nên tình huống
// đó không còn dựng lại được và guard cũng đã đi cùng.

it('#1856 surfaces the unlisted code unchanged on the canonical path', function () {
    // Ghim rằng đường chuẩn vẫn TỪ CHỐI và báo đúng mã (không bị viết lại thành
    // PAYMENT_POLICY_STALE).
    //
    // Trước #2410 có một giới hạn đã đo ghi ở đây: bài này xanh kể cả khi xoá
    // guard rethrow, vì đường chuẩn không mang dấu alias nên nhánh kế tiếp cũng
    // ném. Giới hạn đó nay hết nghĩa — guard và nhánh alias đều đã bị gỡ, và
    // `throw $e` là đường duy nhất còn lại.
    config(['payments.policy_enforcement.required' => false]);

    payWithUnlistedPolicyFault([
        'gateway_option_id' => $this->optionId,
        'policy_revision' => 3,
    ], $this)
        ->assertStatus(422)
        ->assertJsonPath('code', UNLISTED_POLICY_CODE);

    expect(OrderPayment::query()->where('customer_order_id', $this->lastOrderId)->count())->toBe(0);
});
