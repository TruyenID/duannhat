<?php

namespace App\Services\Payment\Policy;

use App\Services\Payment\Policy\Contracts\PaymentPolicyPublicationClock;
use App\Services\Payment\Policy\Contracts\PaymentPolicyRevisionPersistence;
use App\Services\Payment\Policy\Enums\PaymentPolicyPublicationSource;
use App\Services\Payment\Policy\ValueObjects\PaymentPolicyPublication;
use App\Services\Payment\Policy\ValueObjects\PaymentPolicySnapshotInput;
use App\Services\Payment\Policy\ValueObjects\PublishedPaymentPolicyRevision;

final readonly class PaymentPolicyRevisionPublisher
{
    public function __construct(
        private PaymentPolicySnapshotSerializer $serializer,
        private PaymentPolicyRevisionPersistence $persistence,
        private PaymentPolicyPublicationClock $clock,
    ) {}

    public function publish(
        PaymentPolicySnapshotInput $input,
        PaymentPolicyPublicationSource $source,
    ): PublishedPaymentPolicyRevision {
        return $this->persistence->publishAtomically(new PaymentPolicyPublication(
            $input,
            $this->serializer->serialize($input),
            $source,
            $this->clock->now(),
        ));
    }
}
