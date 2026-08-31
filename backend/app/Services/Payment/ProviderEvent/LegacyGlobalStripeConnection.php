<?php

namespace App\Services\Payment\ProviderEvent;

use App\Models\Brand;
use App\Models\Organization;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayProvider;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic synthetic connection for customer-web webhooks that still use
 * config('services.stripe.*').
 *
 * ## NGƯNG DÙNG từ #2893 — giữ lại, KHÔNG xoá
 *
 * Docblock cũ nói hàng này đứng tạm *"until per-shop PaymentGatewayConnection
 * rows land"*. Hàng theo quán đã có từ lâu; chỉ đường webhook là chưa chuyển
 * sang dùng nó, nên bước di trú nằm dở suốt nhiều tháng và 968 bản ghi tiền
 * (747 settlement · 220 provider event · 1 payout) quy về một tổ chức tổng hợp
 * KHÔNG có thành viên nào — tức vô hình với chính chủ sở hữu.
 *
 * Từ #2893, đường phân giải thật là {@see StripePlatformAccount}: sự kiện của
 * tài khoản nền thuộc về hàng connection mang đúng `acct_…` ấy. Hàng tổng hợp
 * này còn lại đúng hai vai, cả hai đều là vai QUÁ KHỨ:
 *
 *  1. **chủ sở hữu lịch sử** của bản ghi tiền chưa/không di trú được — nên
 *     `payment_settlements.connection_id` còn FK vào nó; xoá là phá sổ tiền;
 *  2. **lưới cuối** của {@see WebhookConnectionResolver} khi `STRIPE_ACCOUNT_ID`
 *     chưa khai — rơi vào đó có log cảnh báo, không im lặng như trước.
 *
 * `payments:migrate-stripe-attribution --apply` đánh `is_active=false` lên
 * hàng này. Đừng bật lại, và đừng "dọn" bằng cách xoá: sản phẩm ĐÃ RELEASE
 * (ruling #2872), đây là chứng từ chứ không phải rác.
 */
final class LegacyGlobalStripeConnection
{
    public const CONNECTION_ID = '00000000-0000-4000-8000-000000000001';

    public const ORGANIZATION_ID = '00000000-0000-4000-8000-000000000002';

    public const BRAND_ID = '00000000-0000-4000-8000-000000000003';

    public const BRAND_OWNER_ORG_UNIT_ID = '00000000-0000-4000-8000-000000000004';

    public const OPERATOR_ORG_UNIT_ID = '00000000-0000-4000-8000-000000000005';

    public const OWNERSHIP_REVISION = 'legacy-v1';

    public const MERCHANT_REFERENCE = 'legacy:global-platform';

    public function connectionData(): GatewayConnectionData
    {
        return new GatewayConnectionData(
            self::CONNECTION_ID,
            PaymentGatewayProviderCodeEnum::Stripe,
            $this->resolveEnvironment(),
            self::MERCHANT_REFERENCE,
            1,
        );
    }

    public function resolveModel(): PaymentGatewayConnection
    {
        return DB::transaction(function (): PaymentGatewayConnection {
            $existing = PaymentGatewayConnection::query()->find(self::CONNECTION_ID);
            if ($existing !== null) {
                return $existing;
            }

            $this->ensureOrganization();
            $provider = $this->ensureProvider();
            $brand = $this->ensureBrand();

            $connection = new PaymentGatewayConnection([
                'provider_id' => $provider->id,
                'organization_id' => self::ORGANIZATION_ID,
                'brand_id' => $brand->id,
                'owner_branch_id' => null,
                'identity_brand_id' => (string) $brand->id,
                'owner_scope' => 'hq',
                'brand_owner_org_unit_id' => self::BRAND_OWNER_ORG_UNIT_ID,
                'operator_org_unit_id' => self::OPERATOR_ORG_UNIT_ID,
                'ownership_revision' => self::OWNERSHIP_REVISION,
                'environment' => $this->resolveEnvironment(),
                'merchant_account_id' => self::MERCHANT_REFERENCE,
                'merchant_display_name' => 'Legacy global Stripe',
                'charge_model' => 'direct',
                'health' => 'ready',
                'is_active' => true,
            ]);
            $connection->id = self::CONNECTION_ID;
            $connection->save();

            return $connection;
        });
    }

    private function ensureOrganization(): Organization
    {
        $organization = Organization::query()->find(self::ORGANIZATION_ID);
        if ($organization !== null) {
            return $organization;
        }

        return Organization::unguarded(fn (): Organization => Organization::query()->create([
            'id' => self::ORGANIZATION_ID,
            'console_organization_id' => self::ORGANIZATION_ID,
            'name' => 'Legacy Stripe Intake',
            'slug' => 'legacy-stripe-intake',
            'is_active' => true,
        ]));
    }

    private function ensureBrand(): Brand
    {
        $brand = Brand::query()->find(self::BRAND_ID);
        if ($brand !== null) {
            return $brand;
        }

        return Brand::unguarded(fn (): Brand => Brand::query()->create([
            'id' => self::BRAND_ID,
            'console_brand_id' => self::BRAND_ID,
            'console_organization_id' => self::ORGANIZATION_ID,
            'name' => 'Legacy Stripe Intake',
            'slug' => 'legacy-stripe-intake',
            'is_active' => true,
        ]));
    }

    private function ensureProvider(): PaymentGatewayProvider
    {
        $provider = PaymentGatewayProvider::query()
            ->where('code', PaymentGatewayProviderCodeEnum::Stripe->value)
            ->first();

        if ($provider !== null) {
            return $provider;
        }

        return PaymentGatewayProvider::query()->create([
            'code' => PaymentGatewayProviderCodeEnum::Stripe->value,
            'is_active' => true,
            'name' => 'Stripe',
            'sort_order' => 10,
        ]);
    }

    public static function isLegacy(GatewayConnectionData $connection): bool
    {
        return $connection->connectionId === self::CONNECTION_ID
            || $connection->merchantAccountReference === self::MERCHANT_REFERENCE;
    }

    private function resolveEnvironment(): PaymentGatewayEnvironmentEnum
    {
        // #2893 — cùng một phép đo với đường phân giải tài khoản nền; giữ ở MỘT
        // chỗ để hàng tổng hợp và hàng thật không bao giờ nằm khác môi trường.
        return StripePlatformAccount::environment();
    }
}
