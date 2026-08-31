<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Safe HQ admin serialization — secrets and hidden refs are never exposed (G4). */
class HqPaymentGatewayConnectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $connection = $this->resource;

        return [
            'id' => $connection->id,
            'provider_id' => $connection->provider_id,
            'provider' => $this->whenLoaded('provider', fn () => new PaymentGatewayProviderResource($connection->provider)),
            'owner_scope' => $connection->owner_scope,
            'environment' => $connection->environment,
            'merchant_account_id' => $connection->merchant_account_id,
            'merchant_store_id' => $connection->merchant_store_id,
            'merchant_terminal_id' => $connection->merchant_terminal_id,
            'merchant_display_name' => $connection->merchant_display_name,
            'charge_model' => $connection->charge_model,
            'health' => $connection->health,
            'health_reason_code' => $connection->health_reason_code,
            'last_validated_at' => $connection->last_validated_at?->toISOString(),
            'is_active' => $connection->is_active,
            'ownership_revision' => $connection->ownership_revision,
            'identity_brand_id' => $connection->identity_brand_id,
            'brand_owner_org_unit_id' => $connection->brand_owner_org_unit_id,
            'operator_org_unit_id' => $connection->operator_org_unit_id,
            'key_fingerprint' => $connection->key_fingerprint,
            'secret_version' => $connection->secret_version,
            // Plan-048 T3.6 — the URL the merchant registers with the provider
            // (generic intake route, Gate 3). Server-built so the admin UI never
            // guesses the public host; the ?connection hint pins resolution to
            // this connection (it must still match provider + be active).
            'webhook_url' => $this->webhookUrl($connection),
            'capabilities' => PaymentGatewayConnectionOptionResource::collection(
                $this->whenLoaded('paymentGatewayConnectionOptions', fn () => $connection->paymentGatewayConnectionOptions, collect()),
            ),
            'coverage' => [
                'assigned_shop_count' => $this->whenCounted('shopPaymentOptions'),
            ],
            'created_at' => $connection->created_at?->toISOString(),
            'updated_at' => $connection->updated_at?->toISOString(),
        ];
    }

    private function webhookUrl(mixed $connection): ?string
    {
        $providerCode = $connection->provider?->code;
        $code = $providerCode instanceof \BackedEnum ? $providerCode->value : $providerCode;

        if (! is_string($code) || $code === '' || $code === 'internal') {
            return null;
        }

        return url('/api/v1/webhooks/payment/'.$code).'?connection='.$connection->id;
    }
}
