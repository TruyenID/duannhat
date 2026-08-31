<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\HQ\PaymentGatewayConnectionDisconnectRequest;
use App\Http\Requests\HQ\PaymentGatewayConnectionIndexRequest;
use App\Http\Requests\HQ\PaymentGatewayConnectionRotateRequest;
use App\Http\Requests\HQ\PaymentGatewayConnectionStoreRequest;
use App\Http\Requests\HQ\PaymentGatewayConnectionUpdateRequest;
use App\Http\Resources\HqPaymentGatewayConnectionResource;
use App\Models\PaymentGatewayConnection;
use App\Services\Payment\Configuration\PaymentGatewayConfigurationService;
use App\Services\Payment\Configuration\Support\SensitivePaymentDataGuard;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PaymentGatewayConnectionController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly PaymentGatewayConfigurationService $configuration,
    ) {}

    public function index(PaymentGatewayConnectionIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PaymentGatewayConnection::class);

        $connections = $this->configuration->listConnections(
            $this->getOrganizationId(),
            (string) $request->attributes->get('brand_id'),
            $request->validated(),
        );

        // Paginators expose their rows through getCollection(); loading the count
        // on the paginator itself silently no-ops and `coverage.assigned_shop_count`
        // comes back missing.
        $connections->getCollection()->loadCount('shopPaymentOptions');

        // Returning the paginator (not a plain collection) is what puts `meta`
        // and `links` in the body — the admin list declares `PaginatedResponse`
        // and rendered an empty pager for as long as this returned a bare
        // collection (#F6).
        return HqPaymentGatewayConnectionResource::collection($connections);
    }

    public function store(PaymentGatewayConnectionStoreRequest $request): JsonResponse
    {
        $this->authorize('create', PaymentGatewayConnection::class);

        // Guard the RAW body, not `validated()`. The service also guards, but it
        // only ever sees `validated()` — which has already dropped `api_secret`
        // because the store request does not declare it. So the guard there can
        // never fire, and a caller could post a live secret to this endpoint and
        // get 201 back while `rotate()` (which guards `all()`) rejected the very
        // same body. The secret was not persisted, but it travelled through the
        // request and into whatever logs the request — exactly what this guard
        // exists to prevent (#F9).
        SensitivePaymentDataGuard::rejectIfPresent(
            $request->all(),
            $this->configuration->correlationId(),
        );

        $result = $this->configuration->createConnection(
            $this->getOrganizationId(),
            (string) $request->attributes->get('brand_id'),
            $request->validated(),
            $this->configuration->correlationId(),
        );

        return (new HqPaymentGatewayConnectionResource(
            $result['connection']->load(['provider', 'paymentGatewayConnectionOptions.option']),
        ))->response()->setStatusCode($result['created'] ? 201 : 200);
    }

    public function show(Request $request, string $connection): HqPaymentGatewayConnectionResource
    {
        $model = $this->resolveConnection($request, $connection);
        $this->authorizeOrganization($model);
        $this->authorizeBrand($model);
        $this->authorize('view', $model);

        return new HqPaymentGatewayConnectionResource(
            $model->load(['provider', 'paymentGatewayConnectionOptions.option'])->loadCount('shopPaymentOptions'),
        );
    }

    public function update(
        PaymentGatewayConnectionUpdateRequest $request,
        string $connection,
    ): HqPaymentGatewayConnectionResource {
        $model = $this->resolveConnection($request, $connection);
        $this->authorizeOrganization($model);
        $this->authorizeBrand($model);
        $this->authorize('update', $model);

        $updated = $this->configuration->updateConnection(
            $this->getOrganizationId(),
            (string) $request->attributes->get('brand_id'),
            $model->id,
            $request->validated(),
            $this->configuration->correlationId(),
        );

        return new HqPaymentGatewayConnectionResource(
            $updated->load(['provider', 'paymentGatewayConnectionOptions.option'])->loadCount('shopPaymentOptions'),
        );
    }

    public function validateConnection(Request $request, string $connection): HqPaymentGatewayConnectionResource
    {
        $model = $this->resolveConnection($request, $connection);
        $this->authorizeOrganization($model);
        $this->authorizeBrand($model);
        $this->authorize('validateConnection', $model);

        $validated = $this->configuration->validateConnection(
            $this->getOrganizationId(),
            (string) $request->attributes->get('brand_id'),
            $model->id,
            (string) $request->user()?->id,
            $this->configuration->correlationId(),
        );

        return new HqPaymentGatewayConnectionResource(
            $validated->load(['provider', 'paymentGatewayConnectionOptions.option'])->loadCount('shopPaymentOptions'),
        );
    }

    public function rotate(
        PaymentGatewayConnectionRotateRequest $request,
        string $connection,
    ): JsonResponse {
        $model = $this->resolveConnection($request, $connection);
        $this->authorizeOrganization($model);
        $this->authorizeBrand($model);
        $this->authorize('rotateCredentials', $model);

        SensitivePaymentDataGuard::rejectIfPresent(
            $request->all(),
            $this->configuration->correlationId(),
        );

        $result = $this->configuration->rotateConnectionSecret(
            $this->getOrganizationId(),
            (string) $request->attributes->get('brand_id'),
            $model->id,
            $request->validated(),
            (string) $request->user()?->id,
            $this->configuration->correlationId(),
        );

        return response()->json([
            'data' => [
                'connection' => new HqPaymentGatewayConnectionResource(
                    $result['connection']->load(['provider', 'paymentGatewayConnectionOptions.option']),
                ),
                'key_fingerprint' => $result['fingerprint'],
                'secret_version' => $result['secret_version'],
            ],
        ]);
    }

    public function disconnectImpact(Request $request, string $connection): JsonResponse
    {
        $model = $this->resolveConnection($request, $connection);
        $this->authorizeOrganization($model);
        $this->authorizeBrand($model);
        $this->authorize('disconnect', $model);

        return response()->json([
            'data' => $this->configuration->disconnectImpact(
                $this->getOrganizationId(),
                (string) $request->attributes->get('brand_id'),
                $model->id,
            ),
        ]);
    }

    public function destroy(
        PaymentGatewayConnectionDisconnectRequest $request,
        string $connection,
    ): JsonResponse {
        $model = $this->resolveConnection($request, $connection);
        $this->authorizeOrganization($model);
        $this->authorizeBrand($model);
        $this->authorize('disconnect', $model);

        $this->configuration->disconnectConnection(
            $this->getOrganizationId(),
            (string) $request->attributes->get('brand_id'),
            $model->id,
            $request->validated(),
            $this->configuration->correlationId(),
        );

        return response()->json(null, 204);
    }

    private function resolveConnection(Request $request, string $connectionId): PaymentGatewayConnection
    {
        return $this->configuration->showConnection(
            $this->getOrganizationId(),
            (string) $request->attributes->get('brand_id'),
            $connectionId,
        );
    }
}
