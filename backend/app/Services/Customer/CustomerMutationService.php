<?php

declare(strict_types=1);

namespace App\Services\Customer;

use App\Services\Customer\Commands as C;
use App\Services\Customer\Contracts\CustomerAuthenticationPort;
use App\Services\Customer\Contracts\CustomerAuthorityVerificationPort;
use App\Services\Customer\Contracts\CustomerMutationFacade;
use App\Services\Customer\Contracts\CustomerPersistencePort;
use App\Services\Customer\Results\CustomerAuthenticationResult;
use App\Services\Customer\Results\CustomerMergeResult;
use App\Services\Customer\Results\CustomerMutationResult;
use App\Services\Customer\Results\CustomerResolvedResult;

/**
 * API GHI công bố của Customer (#1550).
 *
 * Mỏng, đúng khuôn `ProductService` / `MenuMutationService` — nhưng KHÁC ở một
 * điểm và điểm đó là toàn bộ giá trị của lớp này: mười một trong mười lăm method
 * đi qua {@see CustomerAuthorityVerificationPort} TRƯỚC khi chạm persistence.
 *
 * Persistence không nhận Command trần; nó nhận `VerifiedCustomerMutation`, thứ
 * chỉ cổng xác minh đóng dấu được. Nên "quên xác minh" không phải một lỗi cần
 * nhớ để tránh — nó không biên dịch được.
 *
 * Ba method không đi qua xác minh, mỗi cái một lý do đọc được:
 *
 *   register / registerAccount  — khách CHƯA TỒN TẠI, chưa có thẩm quyền để
 *                                 xác minh; thứ gác cửa ở đây là scope evidence
 *                                 và fingerprint trên chính Command.
 *   findOrCreate                — như trên, cộng thêm việc nó có thể chỉ ĐỌC.
 *   login                       — chưa đăng nhập thì chưa có thẩm quyền; nó đi
 *                                 qua cổng xác thực rồi mới cấp token dưới
 *                                 thẩm quyền vừa chứng minh.
 */
final class CustomerMutationService implements CustomerMutationFacade
{
    public function __construct(
        private readonly CustomerPersistencePort $persistence,
        private readonly CustomerAuthorityVerificationPort $authority,
        private readonly CustomerAuthenticationPort $authentication,
    ) {}

    public function register(C\RegisterCustomerCommand $command): CustomerMutationResult
    {
        return $this->persistence->insertCustomer($command);
    }

    public function registerAccount(C\RegisterCustomerAccountCommand $command): CustomerAuthenticationResult
    {
        return $this->persistence->insertAccountAndIssueToken($command);
    }

    /**
     * Đăng nhập là con đường DUY NHẤT không đi qua cổng xác minh thẩm quyền —
     * và đúng ra phải vậy: chưa đăng nhập thì chưa có thẩm quyền nào để xác
     * minh. Thay vào đó nó đi qua {@see CustomerAuthenticationPort}, cổng KHÔNG
     * ghi gì; việc cấp token chạy sau, dưới thẩm quyền vừa chứng minh được.
     */
    public function login(C\LoginCustomerCommand $command): CustomerAuthenticationResult
    {
        $evidence = $this->authentication->authenticate($command);

        return $this->persistence->issueAccessToken(
            $this->authority->verifyTokenIssueAuthority(new C\IssueCustomerAccessTokenCommand(
                $command->context,
                $evidence->customerId,
                $command->tokenName,
                $evidence->authenticationEventId,
            )),
        );
    }

    public function issueAccessToken(C\IssueCustomerAccessTokenCommand $command): CustomerAuthenticationResult
    {
        return $this->persistence->issueAccessToken($this->authority->verifyTokenIssueAuthority($command));
    }

    public function revokeAccessToken(C\RevokeCustomerAccessTokenCommand $command): CustomerMutationResult
    {
        return $this->persistence->revokeAccessToken($this->authority->verifyTokenRevokeAuthority($command));
    }

    public function reviseGlobalProfile(C\ReviseGlobalCustomerProfileCommand $command): CustomerMutationResult
    {
        return $this->persistence->applyGlobalProfileRevision($this->authority->verifyGlobalProfileAuthority($command));
    }

    public function reviseTenantProfile(C\ReviseTenantCustomerProfileCommand $command): CustomerMutationResult
    {
        return $this->persistence->applyTenantProfileRevision($this->authority->verifyTenantProfileAuthority($command));
    }

    public function verifyEmail(C\VerifyCustomerEmailCommand $command): CustomerMutationResult
    {
        return $this->persistence->recordEmailVerification($this->authority->verifyEmailAuthority($command));
    }

    public function linkScope(C\LinkCustomerScopeCommand $command): CustomerMutationResult
    {
        return $this->persistence->linkScope($this->authority->verifyLinkAuthority($command));
    }

    public function unlinkScope(C\UnlinkCustomerScopeCommand $command): CustomerMutationResult
    {
        return $this->persistence->unlinkScope($this->authority->verifyUnlinkAuthority($command));
    }

    public function findOrCreate(C\FindOrCreateCustomerCommand $command): CustomerResolvedResult
    {
        return $this->persistence->findOrInsertCustomer($command);
    }

    public function changeCredentials(C\ChangeCustomerCredentialsCommand $command): CustomerMutationResult
    {
        return $this->persistence->replaceCredentials($this->authority->verifyCredentialAuthority($command));
    }

    public function merge(C\MergeCustomersCommand $command): CustomerMergeResult
    {
        return $this->persistence->mergeCustomers($this->authority->verifyMergeAuthority($command));
    }

    public function archive(C\CustomerLifecycleCommand $command): CustomerMutationResult
    {
        return $this->persistence->markArchived($this->authority->verifyLifecycleAuthority($command));
    }

    public function restore(C\CustomerLifecycleCommand $command): CustomerMutationResult
    {
        return $this->persistence->markRestored($this->authority->verifyLifecycleAuthority($command));
    }
}
