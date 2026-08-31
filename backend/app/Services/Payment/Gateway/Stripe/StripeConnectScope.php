<?php

namespace App\Services\Payment\Gateway\Stripe;

use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;
use App\Services\Payment\ProviderEvent\LegacyGlobalStripeConnection;
use App\Services\Payment\ProviderEvent\StripePlatformAccount;

/** Resolves Stripe Connect request scope from non-secret connection identity. */
final class StripeConnectScope
{
    /**
     * @return array<string, mixed>
     */
    public static function requestOptions(GatewayConnectionData $connection): array
    {
        if (LegacyGlobalStripeConnection::isLegacy($connection)) {
            return [];
        }

        $account = self::stripeAccountId($connection);
        if ($account === null) {
            return [];
        }

        return ['stripe_account' => $account];
    }

    /**
     * @return array<string, string>
     */
    public static function summaryFields(GatewayConnectionData $connection): array
    {
        $account = self::stripeAccountId($connection);

        return [
            'connect_account_scope' => $account ?? 'platform',
            'merchant_account_reference' => $connection->merchantAccountReference,
        ];
    }

    private static function stripeAccountId(GatewayConnectionData $connection): ?string
    {
        $reference = trim($connection->merchantAccountReference);

        if ($reference === '' || str_starts_with($reference, 'legacy:')) {
            return null;
        }

        if (preg_match('/^acct_[A-Za-z0-9_]+$/', $reference) !== 1) {
            return null;
        }

        // #2893 — tài khoản của CHÍNH TA không phải tài khoản kết nối. Kèm
        // `Stripe-Account` trỏ về chính mình là bảo Stripe "đóng vai" một tài
        // khoản mà ta đã là — Stripe không hứa gì cho ca đó, và đây là đường
        // đọc phí/số dư thật (`StripeSettlementApiClient`), nên hỏng ở đây là
        // hỏng đối soát tiền chứ không phải hỏng một trang.
        //
        // Chỉ có ý nghĩa từ khi hàng connection mang định danh THẬT của PSP.
        // `STRIPE_ACCOUNT_ID` rỗng ⇒ `isPlatformAccount()` luôn false ⇒ y hệt
        // hành vi trước #2893.
        if (StripePlatformAccount::isPlatformAccount($reference)) {
            return null;
        }

        return $reference;
    }
}
