<?php

namespace App\Http\Requests\HQ;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentGatewayConnectionStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provider_code' => ['required', 'string', Rule::in(['stripe', 'paypay', 'sbps'])],
            'environment' => ['required', 'string', Rule::in(['sandbox', 'test', 'live'])],
            'merchant_account_id' => ['required', 'string', 'max:191'],
            'merchant_store_id' => ['nullable', 'string', 'max:191'],
            'merchant_terminal_id' => ['nullable', 'string', 'max:191'],
            'merchant_display_name' => ['nullable', 'string', 'max:191'],
            'charge_model' => ['required', 'string', Rule::in(['direct', 'destination', 'separate_charges_and_transfers', 'provider_native'])],
            'identity_brand_id' => ['required', 'uuid'],
            'brand_owner_org_unit_id' => ['required', 'uuid'],
            'operator_org_unit_id' => ['required', 'uuid'],
            'ownership_revision' => ['required', 'string', 'max:32'],

            // Credentials are NEVER supplied at create — they arrive later
            // through `POST /payment-gateways/{connection}/rotate`, which is the
            // only path wired to the encrypted secret store and its append-only
            // audit trail.
            //
            // These have to be `prohibited` rather than merely absent from the
            // rules. Undeclared keys are dropped silently by `validated()`, so a
            // caller could post a live `api_secret` here and get 201 back while
            // the same body was refused by rotate. The secret never reached the
            // database, but it crossed the request boundary and could sit in any
            // log that captured the body — which is precisely what the sensitive
            // -data guard exists to prevent (#F9).
            'api_secret' => ['prohibited'],
            'secret' => ['prohibited'],
            'api_key' => ['prohibited'],
            'client_secret' => ['prohibited'],
            'webhook_secret' => ['prohibited'],
            'private_key' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $rejected = 'Credentials are not accepted when creating a connection. Store them with the rotate endpoint instead.';

        return [
            'api_secret.prohibited' => $rejected,
            'secret.prohibited' => $rejected,
            'api_key.prohibited' => $rejected,
            'client_secret.prohibited' => $rejected,
            'webhook_secret.prohibited' => $rejected,
            'private_key.prohibited' => $rejected,
        ];
    }
}
