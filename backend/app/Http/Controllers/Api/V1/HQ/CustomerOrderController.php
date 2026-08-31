<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Resources\CustomerOrderResource;
use App\Models\CustomerOrder;
use App\Services\Customer\CustomerOrderService;
use App\Services\Order\Commands\VoidOrderCommand;
use App\Services\Order\Contracts\OrderMutationFacade;
use App\Services\Order\Internal\OrderMutationContextFactory;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class CustomerOrderController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly CustomerOrderService $service,
        private readonly OrderMutationFacade $orders,
    ) {}

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/orders',
        summary: 'Order report with aggregates (cross-branch)',
        description: 'Returns paginated orders plus an aggregate block with total_revenue, order_count, avg_order_value for completed orders matching the filters.',
        tags: ['HQ Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'confirmed', 'completed', 'cancelled'])),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string'), description: 'Search by order code, customer name, or phone'),
            new OA\Parameter(name: 'order_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['dine_in', 'takeaway'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated orders + aggregate', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CustomerOrder')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                new OA\Property(property: 'aggregate', type: 'object', properties: [
                    new OA\Property(property: 'total_revenue', type: 'integer'),
                    new OA\Property(property: 'order_count', type: 'integer'),
                    new OA\Property(property: 'avg_order_value', type: 'integer'),
                ]),
            ])),
        ]
    )]
    public function index(Request $request)
    {
        $this->authorize('viewAny', CustomerOrder::class);

        $filters = [
            'organization_id' => $this->getOrganizationId(),
            'brand_id' => $request->attributes->get('brand_id'),
            'branch_id' => $request->input('branch_id'),
            'status' => $request->input('status'),
            'order_type' => $request->input('order_type'),
            'search' => $request->input('search'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'sort' => $request->input('sort', '-created_at'),
            'per_page' => min($request->integer('per_page', 25), 100),
        ];

        $orders = $this->service->list($filters);
        $aggregate = $this->service->aggregate($filters);

        return CustomerOrderResource::collection($orders)
            ->additional(['aggregate' => $aggregate]);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/orders/{customerOrder}',
        summary: 'Show order detail (read-only)',
        tags: ['HQ Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customerOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Order detail', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/CustomerOrder'),
            ])),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(Request $request, CustomerOrder $customerOrder): CustomerOrderResource
    {
        $this->authorizeOrganization($customerOrder);
        // The order is bound by its global id, so without this a brand admin
        // could read a sibling brand's order by swapping the slug in the URL.
        $this->authorizeBrand($customerOrder);
        $this->authorize('view', $customerOrder);

        $order = $this->service->findById($customerOrder->id);

        return new CustomerOrderResource($order);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/orders/{customerOrder}/void',
        summary: 'Void an order (cross-branch, brand-scoped)',
        description: 'Same teardown as the shop-side void — coupon released, every line voided, held tables freed, audit logged, OrderVoided broadcast. `void_reason` is mandatory so HQ cancellations are always attributable. Refuses a settled bill (409) and any order still holding collected payments (409 `void_blocked_collected_payment` — refund first).',
        tags: ['HQ Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customerOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['void_reason'],
            properties: [
                new OA\Property(property: 'void_reason', type: 'string', minLength: 1, maxLength: 500, example: 'Khách đổi ý'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Order voided', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/CustomerOrder'),
            ])),
            new OA\Response(response: 403, description: 'Order belongs to another organization'),
            new OA\Response(response: 404, description: 'Order not found in this brand'),
            new OA\Response(response: 409, description: 'Order is closed, or still holds collected payments'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function voidOrder(Request $request, CustomerOrder $customerOrder): CustomerOrderResource
    {
        $this->authorizeOrganization($customerOrder);
        $this->authorizeBrand($customerOrder);
        $this->authorize('void', $customerOrder);

        $data = $request->validate([
            'void_reason' => ['required', 'string', 'max:500'],
        ]);

        // plan-047 T2.12 (#1090) — route through the canonical facade; the
        // typed command carries tenant/actor identity and the same reason the
        // legacy path validated.
        $this->orders->void(new VoidOrderCommand(
            OrderMutationContextFactory::fromOrder($customerOrder, actorId: $request->user()?->id ? (string) $request->user()->id : null),
            (string) $customerOrder->id,
            $data['void_reason'],
        ));

        return new CustomerOrderResource($customerOrder->refresh());
    }
}
