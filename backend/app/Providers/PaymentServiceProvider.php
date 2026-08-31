<?php

namespace App\Providers;

use App\Services\Omnify\PaymentMethodService;
use App\Services\Payment\Admin\PaymentAdminAuthorizationService;
use App\Services\Payment\Configuration\Internal\EloquentPaymentGatewayConfigurationPersistence;
use App\Services\Payment\Configuration\PaymentGatewayConfigurationService;
use App\Services\Payment\Contracts\OpenAccountDebtReads;
use App\Services\Payment\Contracts\PaymentMethodMutationFacade;
use App\Services\Payment\Gateway\PayPay\PayPayPaymentGateway;
use App\Services\Payment\Gateway\PayPay\PayPaySdkClientFactory;
use App\Services\Payment\Gateway\Stripe\StripePaymentGateway;
use App\Services\Payment\Internal\EloquentOpenAccountDebtReads;
use App\Services\Payment\Orchestration\Contracts\PaymentAuthorityVerificationPort;
use App\Services\Payment\Orchestration\Contracts\PaymentMutationFacade;
use App\Services\Payment\Orchestration\Contracts\PaymentPersistencePort;
use App\Services\Payment\Orchestration\Contracts\PaymentQueryPort;
use App\Services\Payment\Orchestration\Internal\EloquentOrderPaymentLedgerWriter;
use App\Services\Payment\Orchestration\Internal\EloquentPaymentAuthorityVerificationPort;
use App\Services\Payment\Orchestration\Internal\EloquentPaymentPersistence;
use App\Services\Payment\Orchestration\Internal\EloquentPaymentQuery;
use App\Services\Payment\Orchestration\Internal\OrderPaymentLedgerWriter;
use App\Services\Payment\Orchestration\Internal\PaymentGatewayOrchestrationBootstrap;
use App\Services\Payment\Orchestration\OrderPaymentOrchestrationCompat;
use App\Services\Payment\Orchestration\PaymentOrchestrator;
use App\Services\Payment\Policy\Admin\EffectivePaymentOptionsPresenter;
use App\Services\Payment\Policy\Admin\PaymentPolicyEvaluationService;
use App\Services\Payment\Policy\Admin\PosEffectivePaymentOptionEnricher;
use App\Services\Payment\Policy\Contracts\PaymentOwnerOptionPolicySource;
use App\Services\Payment\Policy\PaymentPolicySubmissionValidator;
use App\Services\Payment\Policy\Persistence\EloquentPaymentOwnerOptionPolicySource;
use App\Services\Payment\Policy\Persistence\EloquentPaymentPolicyCandidateLoader;
use App\Services\Payment\Policy\Persistence\PaymentGatewayCapabilityMapper;
use App\Services\Payment\Settlement\SettlementAgingReportService;
use App\Services\Payment\Settlement\SettlementFeeEstimator;
use App\Services\Payment\Settlement\SettlementReconciliationService;
use App\Services\Payment\Settlement\SettlementRowAssembler;
use App\Services\Payment\Settlement\Stripe\StripeSettlementApiClient;
use App\Services\Payment\Settlement\Stripe\StripeSettlementClient;
use App\Services\Payment\Settlement\Stripe\StripeSettlementRecorder;
use Illuminate\Support\ServiceProvider;

final class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentMethodMutationFacade::class, PaymentMethodService::class);
        $this->app->singleton(StripePaymentGateway::class);
        $this->app->singleton(PayPaySdkClientFactory::class);
        $this->app->singleton(PayPayPaymentGateway::class);

        $this->app->singleton(EloquentOrderPaymentLedgerWriter::class);
        $this->app->bind(OrderPaymentLedgerWriter::class, EloquentOrderPaymentLedgerWriter::class);

        // #1993 — sổ NỢ ghi sổ (`on_account`). Luật bù trừ hoàn, settlement còn
        // sống và xoá mềm từng nằm trong một câu `DB::table` bên trong
        // `Shop\DebtController`; chúng là luật tiền nên thuộc về đây.
        $this->app->bind(OpenAccountDebtReads::class, EloquentOpenAccountDebtReads::class);

        $this->app->singleton(EloquentPaymentQuery::class);
        $this->app->bind(PaymentQueryPort::class, EloquentPaymentQuery::class);

        $this->app->singleton(EloquentPaymentPersistence::class);
        $this->app->bind(PaymentPersistencePort::class, EloquentPaymentPersistence::class);

        $this->app->singleton(EloquentPaymentAuthorityVerificationPort::class);
        $this->app->bind(PaymentAuthorityVerificationPort::class, EloquentPaymentAuthorityVerificationPort::class);

        $this->app->singleton(EloquentPaymentGatewayConfigurationPersistence::class);
        $this->app->singleton(PaymentGatewayConfigurationService::class);

        $this->app->singleton(PaymentOrchestrator::class);
        $this->app->bind(PaymentMutationFacade::class, PaymentOrchestrator::class);

        $this->app->singleton(PaymentGatewayOrchestrationBootstrap::class);
        $this->app->singleton(OrderPaymentOrchestrationCompat::class);

        $this->app->singleton(PaymentAdminAuthorizationService::class);

        $this->app->singleton(PaymentGatewayCapabilityMapper::class);
        $this->app->singleton(EloquentPaymentPolicyCandidateLoader::class);
        $this->app->singleton(EffectivePaymentOptionsPresenter::class);
        $this->app->singleton(PaymentPolicyEvaluationService::class);
        $this->app->singleton(PaymentPolicySubmissionValidator::class);

        // #1856 — SCOPED, not singleton like its neighbours above.
        //
        // This class memoises per shop (`internalTenderMethodIdCache`,
        // `legacyMethodCache`, `organizationIdCache`). One instance per request
        // is what makes those memos worth having: `KioskController` already
        // injects it to build the option list, and `OrderPaymentService` then
        // asks the same instance whether the tender is internal — so the second
        // question costs nothing. Measured with `DB::listen`: resolved fresh
        // (the previous `app()` call inside the method) every ask was 6
        // queries; scoped, the first is 6 and the rest are 0.
        //
        // NOT singleton, deliberately: the caches would then survive across
        // requests under Octane, and a catalog option deactivated mid-process
        // would keep being treated as an internal tender — a stale money guard.
        // `scoped()` is reset per request, so it cannot outlive the catalog it
        // read.
        $this->app->scoped(PosEffectivePaymentOptionEnricher::class);

        // Was DefaultAllowed* — a placeholder that answered "allowed" for every
        // option, which made the whole HQ policy screen inert downstream (#F3).
        // Scoped, not singleton: it memoises per request and brand policy can
        // change between requests.
        $this->app->scoped(EloquentPaymentOwnerOptionPolicySource::class);
        $this->app->bind(PaymentOwnerOptionPolicySource::class, EloquentPaymentOwnerOptionPolicySource::class);

        // Plan-050 (#1155) — gateway settlement sub-ledger. Tests rebind
        // StripeSettlementClient to an in-memory fake; no settlement code
        // path ever performs real HTTP in the suite.
        $this->app->singleton(StripeSettlementApiClient::class);
        $this->app->bind(StripeSettlementClient::class, StripeSettlementApiClient::class);
        $this->app->singleton(SettlementRowAssembler::class);
        $this->app->singleton(SettlementFeeEstimator::class);
        $this->app->singleton(StripeSettlementRecorder::class);
        $this->app->singleton(SettlementReconciliationService::class);
        $this->app->singleton(SettlementAgingReportService::class);
    }
}
