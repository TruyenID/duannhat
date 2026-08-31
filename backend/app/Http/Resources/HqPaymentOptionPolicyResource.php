<?php

namespace App\Http\Resources;

use App\Models\PaymentGatewayOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HqPaymentOptionPolicyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $row */
        $row = $this->resource;
        /** @var PaymentGatewayOption $option */
        $option = $row['option'];

        return [
            'option_id' => $option->id,
            'option' => new PaymentGatewayOptionResource($option),
            'shop_payment_option_id' => $row['shop_payment_option_id'],
            'preference' => $row['preference'],
            'owner_policy' => $row['owner_policy'],
            'effective_preview' => $row['effective_preview'],
            'version' => $row['version'],
        ];
    }
}
