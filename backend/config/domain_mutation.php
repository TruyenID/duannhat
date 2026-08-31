<?php

use App\Services\Customer\Contracts\CustomerAuthorityVerificationPort;
use App\Services\Customer\Internal\EloquentCustomerAuthorityVerification;
use App\Services\Order\Contracts\OrderEvidenceVerificationPort;
use App\Services\Order\Contracts\OrderPricingResolutionPort;
use App\Services\Order\Internal\CustomerOrderPricingResolution;
use App\Services\Order\Internal\OfflineOrderEvidenceVerifier;
use App\Services\Payment\Orchestration\Contracts\PaymentAuthorityVerificationPort;
use App\Services\Payment\Orchestration\Internal\EloquentPaymentAuthorityVerificationPort;

return [
    /*
    | Exact final verifier class per port and issuance scope.
    |
    | Production composition must list each real, final adapter explicitly;
    | implementing the port never grants issuance authority by itself.
    */
    'issuance_adapters' => [
        // plan-047 T2.12 — the pricing resolver is the only class allowed to
        // seal a TrustedOrderSnapshot. Without this entry the authority stays
        // fail-closed and no order snapshot can be issued at all.
        OrderPricingResolutionPort::class => [
            'order.trusted_snapshot' => CustomerOrderPricingResolution::class,
            'order.persist_resolved_items' => CustomerOrderPricingResolution::class,
        ],
        // #1096 — the offline evidence verifier is the ONLY class allowed to
        // seal a snapshot for a replayed offline order. Without this entry the
        // authority stays fail-closed and no offline replay can be issued.
        OrderEvidenceVerificationPort::class => [
            'order.trusted_snapshot' => OfflineOrderEvidenceVerifier::class,
        ],
        // #1550 — cổng xác minh thẩm quyền của Customer. Không có mục này thì
        // `VerificationAuthority` fail-closed và KHÔNG một thao tác ghi khách
        // nào chạy được — kể cả khi class đã implement đúng interface.
        CustomerAuthorityVerificationPort::class => [
            'customer.verified_mutation' => EloquentCustomerAuthorityVerification::class,
        ],
        PaymentAuthorityVerificationPort::class => [
            'payment.resolved_method' => EloquentPaymentAuthorityVerificationPort::class,
            'payment.verified_preparation' => EloquentPaymentAuthorityVerificationPort::class,
            'payment.verified_refund' => EloquentPaymentAuthorityVerificationPort::class,
        ],
    ],
];
