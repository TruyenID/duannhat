<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Customer\OrderPaymentService;
use App\Services\Payment\Orchestration\Internal\EloquentOrderPaymentLedgerWriter;
use App\Services\Payment\Orchestration\Internal\OrderPaymentLedgerWriter;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->organizationId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->organizationId,
        'console_organization_id' => $this->organizationId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->organizationId,
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->organizationId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->operator = User::factory()->create([
        'console_organization_id' => $this->organizationId,
    ]);

    $this->cash = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->organizationId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
        'is_auto_confirm' => true,
        'requires_tendered' => true,
    ]);
});

it('binds the ledger writer contract to the eloquent implementation', function () {
    expect(app(OrderPaymentLedgerWriter::class))
        ->toBeInstanceOf(EloquentOrderPaymentLedgerWriter::class);
});

it('lists payment orchestration and catalog boundaries', function () {
    expect(config('domain-mutation-guard.aggregates.payment.boundaries'))->toBe([
        'app/Omnify/Modules/PaymentMethod/Services/PaymentMethodServiceBase.php',
        'app/Services/Omnify/PaymentMethodService.php',
        // #1611 (7a-2b) — the RUNTIME writer the #1637 note was waiting for.
        // Payments stamps its own pointer table instead of reading a
        // gateway-named column off Ordering's row.
        'app/Services/Payment/Internal/OrderPaymentIntentPointer.php',
        'app/Services/Payment/Orchestration/Internal/EloquentOrderPaymentLedgerWriter.php',
        'app/Services/Payment/Orchestration/Internal/EloquentPaymentPersistence.php',
        'app/Services/Payment/Orchestration/Internal/PaymentGatewayOrchestrationBootstrap.php',
        // plan-054 — same role as the Stripe provisioner beside it.
        'app/Services/Payment/Orchestration/Internal/PayPayCanonicalPaymentMethodProvisioner.php',
        'app/Services/Payment/Orchestration/Internal/PayPayCustomerWebBootstrap.php',
        // #2454 — scheduler bookkeeping only: stamps `last_swept_at` so the
        // sweep's backoff ladder knows when each QR was last asked about.
        'app/Services/Payment/Gateway/PayPay/PayPayQrSweepSchedule.php',
        'app/Services/Payment/Orchestration/Internal/StripeCanonicalPaymentMethodProvisioner.php',
        'app/Services/Payment/Policy/Persistence/EloquentPaymentPolicyRevisionPersistence.php',
        'app/Services/Payment/Policy/Admin/PaymentPolicyEvaluationService.php',
        'app/Services/Payment/Configuration/Internal/EloquentPaymentGatewayConfigurationPersistence.php',
        'app/Services/Payment/ProviderEvent/LegacyGlobalStripeConnection.php',
        'app/Services/Payment/ProviderEvent/ProviderEventInboxService.php',
        'app/Services/Payment/ProviderEvent/ProviderEventIntakeService.php',
        // #1195 — the plan-050 settlement layer. Its three tables
        // (payment_settlements, gateway_payouts, settlement_report_batches)
        // belonged to no aggregate, so these writers were invisible to the
        // guard; declaring the tables surfaced them and they were registered
        // here. Money rows: what the gateway ACTUALLY paid out.
        'app/Services/Payment/Settlement/SettlementRowAssembler.php',
        'app/Services/Payment/Settlement/SettlementReconciliationService.php',
        'app/Services/Payment/Settlement/Stripe/StripeSettlementRecorder.php',
        // #2864 — đánh dấu tiền của merchant KHÁC trên tài khoản Stripe dùng
        // chung. Ghi đúng một cột `status` trên `payment_settlements`.
        'app/Services/Payment/Settlement/ForeignSettlementMarker.php',
        // #2893 — chuyển quy thuộc bản ghi tiền từ hàng connection tổng hợp
        // sang hàng THẬT. Ghi `connection_id` của ba bảng đối soát + danh tính
        // merchant của connection; KHÔNG ghi `order_payments`.
        'app/Services/Payment/Settlement/SettlementAttributionMigrator.php',
        // #3084 — thay UUID bịa ở hai cột quyền sở hữu bằng dấu "chưa phân
        // giải". Không chạm tiền và không chạm quy thuộc cổng; chỉ làm cho việc
        // chưa biết trở nên tra ra được bằng một câu SQL.
        'app/Services/Payment/Settlement/UnresolvedOwnershipBackfill.php',
    ]);
});

it('keeps OrderPaymentService off direct order_payments mutations', function () {
    $source = file_get_contents(app_path('Services/Customer/OrderPaymentService.php'));

    expect($source)->toContain('OrderPaymentLedgerWriter')
        ->and($source)->not->toMatch('/OrderPayment::(create|update|save|delete)\(/');
});

it('keeps StripePaymentService off direct order_payments mutations', function () {
    $source = file_get_contents(app_path('Services/Customer/StripePaymentService.php'));

    expect($source)->toContain('OrderPaymentService')
        ->and($source)->not->toMatch('/OrderPayment::create\(/')
        ->and($source)->not->toContain('OrderPaymentLedgerWriter');
});

it('creates ledger rows through the writer boundary', function () {
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'order_type' => 'takeaway',
        'status' => 'checkout',
        'total_amount' => 1000,
        'paid_amount' => 0,
    ]);

    $payment = app(OrderPaymentService::class)->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cash->id,
        'amount' => 400,
        'tendered_amount' => 400,
        'received_by_id' => $this->operator->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);

    expect($payment)->toBeInstanceOf(OrderPayment::class)
        ->and($payment->status->value)->toBe('succeeded')
        ->and((float) $order->fresh()->paid_amount)->toBe(400.0);
});

it('routes settlement through OrderService rather than OrderClosingService::close', function () {
    $source = file_get_contents(app_path('Services/Customer/OrderPaymentService.php'));

    expect($source)->toContain('settleIfPaid')
        ->and($source)->not->toContain('OrderClosingService::close');
});
