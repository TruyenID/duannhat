<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\HQ\PaymentGatewayOptionPolicyUpdateRequest;
use App\Http\Resources\HqPaymentOptionPolicyResource;
use App\Models\PaymentGatewayConnection;
use App\Services\Payment\Configuration\PaymentGatewayConfigurationService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PaymentGatewayOptionPolicyController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly PaymentGatewayConfigurationService $configuration,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PaymentGatewayConnection::class);

        $policies = $this->configuration->listOptionPolicies(
            $this->getOrganizationId(),
            (string) $request->attributes->get('brand_id'),
        );

        return HqPaymentOptionPolicyResource::collection($policies);
    }

    public function update(
        PaymentGatewayOptionPolicyUpdateRequest $request,
        string $option,
    ): HqPaymentOptionPolicyResource {
        $this->authorize('viewAny', PaymentGatewayConnection::class);

        $policy = $this->configuration->updateOptionPolicy(
            $this->getOrganizationId(),
            (string) $request->attributes->get('brand_id'),
            $option,
            $request->validated(),
            $this->configuration->correlationId(),
        );

        return new HqPaymentOptionPolicyResource([
            'option' => $policy->option,
            'shop_payment_option_id' => $policy->id,
            'preference' => $policy->preference,
            'owner_policy' => $policy->preference->value === 'blocked' ? 'denied' : 'allowed',
            'effective_preview' => match ($policy->preference->value) {
                'blocked' => 'blocked_upstream',
                'enabled' => 'default_on',
                'disabled' => 'default_off',
                default => 'inherit_provider_default',
            },
            'version' => $policy->version,
        ]);
    }
}
