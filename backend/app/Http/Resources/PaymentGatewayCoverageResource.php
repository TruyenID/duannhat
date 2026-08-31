<?php

namespace App\Http\Resources;

use App\Models\Branch;
use App\Models\PaymentGatewayConnection;
use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentGatewayCoverageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $row */
        $row = $this->resource;
        /** @var Branch $branch */
        $branch = $row['branch'];
        $connection = $row['connection'] ?? null;

        return [
            'shop_id' => $branch->id,
            'shop_slug' => $branch->slug,
            'shop_name' => $branch->name,
            'management_model' => $row['management_model'],
            'connection_ready' => $row['connection_ready'],
            'setup_required' => $row['setup_required'],
            'reason_code' => $row['reason_code'],
            'public_error_code' => $row['setup_required'] ? 'PAYMENT_CONNECTION_REQUIRED' : null,

            // The admin coverage table renders these four columns. Before #F5
            // none of them existed in the payload, so the UI read `undefined`,
            // concatenated it into a translation key, and printed
            // `hq.payments.shops.management.undefined` on every row while the
            // connection column stayed a permanent em-dash.
            'readiness' => $row['connection_ready'] ? 'ready' : 'setup_required',
            'connection_health' => $connection instanceof PaymentGatewayConnection
                ? $this->scalar($connection->health)
                : null,
            'connection_display' => $connection instanceof PaymentGatewayConnection
                ? $this->connectionDisplay($connection, $request)
                : null,
            'options_effective' => $row['options_effective'] ?? 0,
            'options_total' => $row['options_total'] ?? 0,
        ];
    }

    /** "Stripe · acct_1234" — provider label plus the merchant account it charges through. */
    private function connectionDisplay(PaymentGatewayConnection $connection, Request $request): string
    {
        $account = (string) $connection->merchant_account_id;
        $provider = $connection->provider;

        if ($provider === null) {
            return $account;
        }

        // Reuse the provider resource so this label inherits the same
        // never-null translation resolution as the connection list (#F7).
        $label = (new PaymentGatewayProviderResource($provider))->toArray($request)['name'] ?? null;

        return is_string($label) && $label !== '' ? $label.' · '.$account : $account;
    }

    private function scalar(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return $value === null ? null : (string) $value;
    }
}
