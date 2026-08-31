<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Controllers\Traits\PresentsPointLedger;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\CustomerPointEntry;
use App\Services\Customer\CustomerService;
use App\Services\Loyalty\CustomerPointService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class CustomerController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;
    use PresentsPointLedger;

    public function __construct(
        private readonly CustomerService $service,
        private readonly CustomerPointService $points,
    ) {}

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/customers',
        summary: 'List customers across all branches',
        tags: ['HQ Customers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid'), description: 'Filter by branch'),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated customer list', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Customer')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ])),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Customer::class);

        $customers = $this->service->list([
            'organization_id' => $this->getOrganizationId(),
            'brand_id' => $request->attributes->get('brand_id'),
            'branch_id' => $request->input('branch_id'),
            'search' => $request->input('search'),
            'with_trashed' => $request->boolean('with_trashed'),
            'sort' => $request->input('sort', '-created_at'),
            'per_page' => min($request->integer('per_page', 25), 100),
            'with_point_balance' => true,
            'with_branch' => true,
        ]);

        return CustomerResource::collection($customers);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/customers/{customer}',
        summary: 'Show customer with cross-branch order history',
        tags: ['HQ Customers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Customer with orders', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'object', allOf: [
                    new OA\Schema(ref: '#/components/schemas/Customer'),
                    new OA\Schema(properties: [
                        new OA\Property(property: 'orders', type: 'array', items: new OA\Items(ref: '#/components/schemas/CustomerOrder')),
                    ]),
                ]),
            ])),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(Request $request, Customer $customer): CustomerResource
    {
        $this->authorizeOrganization($customer);
        $this->authorize('view', $customer);

        $customer->load('customerOrders');

        return new CustomerResource($customer);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/customers/{customer}/points',
        summary: 'Point balance, ledger and redeemed coupons for one customer',
        description: 'Read-only. The ledger is append-only; there is no balance column — balance is SUM(points).',
        tags: ['HQ Customers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Balance + paginated ledger'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function points(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeOrganization($customer);
        $this->authorize('view', $customer);

        $history = $this->points->history(
            $customer,
            perPage: min($request->integer('per_page', 20), 50),
            page: max(1, $request->integer('page', 1)),
        );

        return response()->json([
            'data' => [
                // Số dư và tổng tích luỹ là HAI con số khác nhau và cả hai đều
                // cần: số dư là cái khách tiêu được, tổng tích luỹ là cái xét
                // hạng (tiêu điểm không làm tụt hạng — xem `lifetimeEarned`).
                'balance' => $this->points->balance($customer),
                'lifetime_points' => $this->points->lifetimeEarned($customer),
                'entries' => collect($history['data'])
                    ->map(fn (CustomerPointEntry $entry): array => $this->pointEntryToArray($entry))
                    ->values()
                    ->all(),
            ],
            'meta' => $history['meta'],
        ]);
    }
}
