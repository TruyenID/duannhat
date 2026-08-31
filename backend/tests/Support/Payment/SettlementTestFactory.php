<?php

namespace Tests\Support\Payment;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayProvider;
use App\Models\PaymentProviderEvent;
use Illuminate\Support\Str;

/**
 * Plan-050 — shared builders for settlement tests: a stripe-coded
 * connection and inbox provider-event rows shaped like real (redacted)
 * Stripe webhook snapshots.
 */
final class SettlementTestFactory
{
    public static function provider(string $code = 'stripe'): PaymentGatewayProvider
    {
        return PaymentGatewayProvider::query()->where('code', $code)->first()
            ?? PaymentGatewayProvider::factory()->create([
                'code' => $code,
                'name' => ucfirst($code),
                'is_active' => true,
            ]);
    }

    public static function stripeConnection(): PaymentGatewayConnection
    {
        // #3074 — mỗi lượt gọi dựng TENANT RIÊNG, không mượn hàng có sẵn.
        //
        // `payment_gateway_connections` nay UNIQUE trên khoá tự nhiên (provider ·
        // environment · organization · brand · owner_scope · owner_branch_key),
        // nên hai lượt gọi mượn cùng một org/brand ngẫu nhiên sẽ đâm index. Mà
        // mọi bài gọi helper này hai lần đều đang nói "hai connection KHÁC NHAU",
        // tức hai chủ sở hữu khác nhau — `inRandomOrder()` chỉ là tiện tay.
        //
        // Chi nhánh cũng dựng theo brand vừa tạo: bản cũ bốc một chi nhánh bất kỳ
        // trong DB, có thể thuộc tổ chức khác hẳn connection.
        $organization = Organization::factory()->create();
        $brand = Brand::factory()->create([
            'console_organization_id' => $organization->console_organization_id,
        ]);
        $branch = Branch::factory()->create([
            'console_organization_id' => $organization->console_organization_id,
            'console_brand_id' => $brand->console_brand_id,
        ]);

        return PaymentGatewayConnection::factory()->create([
            'provider_id' => self::provider('stripe')->id,
            'organization_id' => $organization->id,
            'brand_id' => $brand->id,
            'owner_branch_id' => $branch->id,
            'environment' => 'test',
            'merchant_account_id' => 'acct_'.Str::lower(Str::random(14)),
            'health' => 'ready',
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $redactedPayload
     */
    public static function stripeEvent(
        PaymentGatewayConnection $connection,
        string $eventType,
        array $redactedPayload = [],
        ?string $providerObjectId = null,
    ): PaymentProviderEvent {
        return PaymentProviderEvent::factory()->create([
            'organization_id' => $connection->organization_id,
            'connection_id' => $connection->id,
            'provider' => 'stripe',
            'environment' => $connection->environment instanceof \BackedEnum
                ? $connection->environment->value
                : (string) $connection->environment,
            'state' => 'processing',
            'provider_event_id' => 'evt_'.Str::lower(Str::random(20)),
            'event_type' => $eventType,
            'provider_object_id' => $providerObjectId,
            'payload_hash' => hash('sha256', Str::random(32)),
            'redacted_payload' => $redactedPayload,
            'outcome' => null,
            'delivery_count' => 1,
            'processing_attempts' => 0,
            'lease_token' => null,
            'lease_expires_at' => null,
            'next_retry_at' => null,
            'last_error_code' => null,
            'redacted_error' => null,
        ]);
    }
}
