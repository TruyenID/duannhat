<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\PaymentGatewayConnection;
use App\Models\User;
use App\Omnify\Modules\PaymentGatewayConnection\Policies\PaymentGatewayConnectionPolicyBase;
use App\Policies\Traits\ChecksPaymentAdminContext;
use App\Services\Payment\Admin\PaymentAdminAuthorizationService;
use App\Services\Payment\Policy\ValueObjects\BranchManagementProjection;

class PaymentGatewayConnectionPolicy extends PaymentGatewayConnectionPolicyBase
{
    use ChecksPaymentAdminContext;

    public function __construct(
        private PaymentAdminAuthorizationService $authorization,
    ) {}

    public function view(User $user, PaymentGatewayConnection $record): bool
    {
        if (! parent::view($user, $record)) {
            return false;
        }

        $organizationId = $this->paymentOrganizationId();

        return $organizationId !== null
            && $this->authorization->canViewConnection(
                $user,
                $record,
                $organizationId,
                $this->paymentBranchId(),
            );
    }

    public function viewAny(User $user): bool
    {
        return parent::viewAny($user) && $this->paymentActorCanRead($user);
    }

    public function create(User $user): bool
    {
        $organizationId = $this->paymentOrganizationId();

        return $organizationId !== null
            && $this->authorization->isHqAdmin($user, $organizationId);
    }

    public function update(User $user, PaymentGatewayConnection $record): bool
    {
        return $this->canMutate($user, $record);
    }

    public function rotateCredentials(User $user, PaymentGatewayConnection $record): bool
    {
        $organizationId = $this->paymentOrganizationId();

        if ($organizationId === null) {
            return false;
        }

        return $this->authorization->canRotateCredentials(
            $user,
            $record,
            $organizationId,
            $this->ownershipProjection(),
            $this->paymentBranchId(),
        );
    }

    public function validateConnection(User $user, PaymentGatewayConnection $record): bool
    {
        $organizationId = $this->paymentOrganizationId();

        if ($organizationId === null) {
            return false;
        }

        return $this->authorization->canValidateConnection(
            $user,
            $record,
            $organizationId,
            $this->ownershipProjection(),
            $this->paymentBranchId(),
        );
    }

    public function disconnect(User $user, PaymentGatewayConnection $record): bool
    {
        return $this->canMutate($user, $record);
    }

    private function canMutate(User $user, PaymentGatewayConnection $record): bool
    {
        $organizationId = $this->paymentOrganizationId();

        if ($organizationId === null) {
            return false;
        }

        return $this->authorization->canMutateConnection(
            $user,
            $record,
            $organizationId,
            $this->ownershipProjection(),
            $this->paymentBranchId(),
        );
    }

    private function ownershipProjection(): ?BranchManagementProjection
    {
        $organizationId = $this->paymentOrganizationId();
        $branchId = $this->paymentBranchId();
        $identityBrandId = $this->paymentIdentityBrandId();

        if ($organizationId === null || $branchId === null || $identityBrandId === null) {
            return null;
        }

        $branch = Branch::query()->find($branchId);

        return $branch instanceof Branch
            ? $this->authorization->resolveOwnership($branch, $identityBrandId, $organizationId)
            : null;
    }
}
