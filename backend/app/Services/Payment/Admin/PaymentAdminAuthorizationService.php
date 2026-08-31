<?php

namespace App\Services\Payment\Admin;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\PaymentGatewayConnection;
use App\Models\ShopPaymentOption;
use App\Models\User;
use App\Omnify\Enums\PaymentConnectionOwnerScopeEnum;
use App\Services\Device\Contracts\DeviceDirectory;
use App\Services\Payment\Policy\Contracts\BranchManagementProjectionSource;
use App\Services\Payment\Policy\Enums\BranchManagementModel;
use App\Services\Payment\Policy\ValueObjects\BranchManagementLookup;
use App\Services\Payment\Policy\ValueObjects\BranchManagementProjection;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;

final readonly class PaymentAdminAuthorizationService
{
    public function __construct(
        private BranchManagementProjectionSource $ownershipSource,
        private DeviceDirectory $devices,
    ) {}

    public function resolveOwnership(Branch $branch, string $identityBrandId, ?string $organizationId = null): BranchManagementProjection
    {
        $organizationId ??= Organization::query()
            ->where('console_organization_id', $branch->console_organization_id)
            ->value('id');

        if (! is_string($organizationId) || $organizationId === '') {
            throw new \InvalidArgumentException('Branch organization could not be resolved for payment admin authorization.');
        }

        return $this->ownershipSource->resolve(new BranchManagementLookup(
            $organizationId,
            $identityBrandId,
            (string) $branch->console_branch_id,
            new DateTimeImmutable('now'),
        ));
    }

    public function belongsToOrganization(Model $record, string $organizationId): bool
    {
        return isset($record->organization_id) && $record->organization_id === $organizationId;
    }

    public function concealUnlessOrganization(Model $record, string $organizationId): ?Model
    {
        return $this->belongsToOrganization($record, $organizationId) ? $record : null;
    }

    public function isHqAdmin(User $user, string $organizationId): bool
    {
        return $user->hasRoleInContext('org-admin', $organizationId)
            || $user->hasPermission('iam.permissions', $organizationId);
    }

    public function isShopManager(User $user, string $organizationId, ?string $branchId = null): bool
    {
        if ($this->isHqAdmin($user, $organizationId)) {
            return true;
        }

        return $user->hasRoleInContext('org-manager', $organizationId, $branchId)
            || $user->hasRoleInContext('shop-manager', $organizationId, $branchId);
    }

    public function isCashier(User $user, string $organizationId, ?string $branchId = null): bool
    {
        if ($this->isShopManager($user, $organizationId, $branchId)) {
            return true;
        }

        return $user->hasRoleInContext('staff', $organizationId, $branchId)
            || $user->hasRoleInContext('shop-staff', $organizationId, $branchId);
    }

    public function canViewConnection(
        User $user,
        PaymentGatewayConnection $connection,
        string $organizationId,
        ?string $branchId = null,
    ): bool {
        if (! $this->belongsToOrganization($connection, $organizationId)) {
            return false;
        }

        return $this->isCashier($user, $organizationId, $branchId);
    }

    public function canMutateConnection(
        User $user,
        PaymentGatewayConnection $connection,
        string $organizationId,
        ?BranchManagementProjection $ownership = null,
        ?string $branchId = null,
    ): bool {
        if (! $this->belongsToOrganization($connection, $organizationId)) {
            return false;
        }

        if ($connection->owner_scope === PaymentConnectionOwnerScopeEnum::Hq) {
            return $this->isHqAdmin($user, $organizationId);
        }

        if ($connection->owner_scope !== PaymentConnectionOwnerScopeEnum::Franchise) {
            return false;
        }

        if ($ownership !== null && $ownership->managementModel === BranchManagementModel::HqManaged) {
            return false;
        }

        if ($branchId === null) {
            return $this->isHqAdmin($user, $organizationId);
        }

        return $this->isShopManager($user, $organizationId, $branchId)
            && $connection->owner_branch_id === $branchId;
    }

    public function canRotateCredentials(
        User $user,
        PaymentGatewayConnection $connection,
        string $organizationId,
        ?BranchManagementProjection $ownership = null,
        ?string $branchId = null,
    ): bool {
        if ($ownership !== null
            && $ownership->managementModel === BranchManagementModel::HqManaged
            && ! $this->isHqAdmin($user, $organizationId)) {
            return false;
        }

        return $this->canMutateConnection($user, $connection, $organizationId, $ownership, $branchId);
    }

    public function canValidateConnection(
        User $user,
        PaymentGatewayConnection $connection,
        string $organizationId,
        ?BranchManagementProjection $ownership = null,
        ?string $branchId = null,
    ): bool {
        return $this->canMutateConnection($user, $connection, $organizationId, $ownership, $branchId);
    }

    public function canManageShopPaymentOption(
        User $user,
        ShopPaymentOption $option,
        string $organizationId,
    ): bool {
        if (! $this->belongsToOrganization($option, $organizationId)) {
            return false;
        }

        return $this->isShopManager($user, $organizationId, $option->branch_id);
    }

    /**
     * Thiết bị KHÔNG BAO GIỜ được sửa chính sách thanh toán — luật này không phụ
     * thuộc thiết bị nào, nên #1666 bỏ luôn tham số `Device` thay vì đưa nó qua
     * cổng: một tham số không được đọc chỉ để giữ nguyên chữ ký là cách êm nhất
     * để cạnh module mọc lại.
     */
    public function deviceCanMutatePaymentPolicy(): bool
    {
        return false;
    }

    public function deviceCanViewPaymentConfiguration(string $deviceId, string $organizationId, string $branchId): bool
    {
        return $this->devices->isActiveInBranch($deviceId, $organizationId, $branchId);
    }
}
