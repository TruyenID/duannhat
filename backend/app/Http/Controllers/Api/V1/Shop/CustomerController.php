<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Controllers\Traits\PresentsPointLedger;
use App\Http\Requests\CustomerStoreRequest;
use App\Http\Requests\CustomerUpdateRequest;
use App\Http\Resources\CustomerOrderResource;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\CustomerPointEntry;
use App\Services\Customer\CustomerService;
use App\Services\Loyalty\CustomerPointService;
use App\Services\Order\CustomerOutstandingOrderService;
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
        path: '/api/v1/shops/{shopSlug}/customers',
        summary: 'List branch customers',
        tags: ['Customers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string'), description: 'Search by name, phone, or email'),
            new OA\Parameter(name: 'phone', in: 'query', required: false, schema: new OA\Schema(type: 'string'), description: 'Phone prefix lookup'),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'with_trashed', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated customer list', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Customer')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Customer::class);

        $customers = $this->service->list([
            'organization_id' => $this->getOrganizationId(),
            'branch_id' => $request->attributes->get('shop_id'),
            'search' => $request->input('search'),
            'phone' => $request->input('phone'),
            'with_trashed' => $request->boolean('with_trashed'),
            'sort' => $request->input('sort', '-created_at'),
            'per_page' => min($request->integer('per_page', 25), 100),
            // #1718 — cột "Điểm" ở màn khách hàng của cửa hàng. Là số dư ví
            // toàn brand của khách, không phải điểm tích ở chi nhánh này.
            'with_point_balance' => true,
        ]);

        return CustomerResource::collection($customers);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/customers',
        summary: 'Create a customer',
        tags: ['Customers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['first_name'],
            properties: [
                new OA\Property(property: 'first_name', type: 'string', maxLength: 100),
                new OA\Property(property: 'last_name', type: 'string', maxLength: 100, nullable: true),
                new OA\Property(property: 'phone', type: 'string', maxLength: 20, nullable: true),
                new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true),
                new OA\Property(property: 'address', type: 'string', maxLength: 500, nullable: true),
                new OA\Property(property: 'tax_code', type: 'string', maxLength: 50, nullable: true),
                new OA\Property(property: 'note', type: 'string', nullable: true),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Customer created', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/Customer'),
            ])),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function store(CustomerStoreRequest $request): JsonResponse
    {
        $this->authorize('create', Customer::class);

        $data = $request->validated();
        $data['organization_id'] = $this->getOrganizationId();
        $data['branch_id'] = $request->attributes->get('shop_id');
        $data['brand_id'] = $request->attributes->get('brand_id');

        $customer = $this->service->create($data);

        return (new CustomerResource($customer))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/customers/find-or-create',
        summary: 'Find a customer by exact phone, or create a minimal one',
        description: 'Used by the POS create-order flow where staff captures only a phone number. If a customer with the same phone already exists in this org+branch, returns them (status 200). Otherwise creates a minimal customer with first_name="Khách" and the given phone (status 201). Staff can fill in full details later via the customer admin screen.',
        tags: ['Customers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['phone'],
            properties: [
                new OA\Property(property: 'phone', type: 'string', minLength: 1, maxLength: 20),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Existing customer returned', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/Customer'),
                new OA\Property(property: 'created', type: 'boolean', example: false),
            ])),
            new OA\Response(response: 201, description: 'New customer created', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/Customer'),
                new OA\Property(property: 'created', type: 'boolean', example: true),
            ])),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function findOrCreate(Request $request): JsonResponse
    {
        $this->authorize('create', Customer::class);

        $data = $request->validate([
            'phone' => ['required', 'string', 'min:1', 'max:20'],
        ]);

        [$customer, $wasCreated] = $this->service->findOrCreateByPhone(
            trim($data['phone']),
            [
                'organization_id' => $this->getOrganizationId(),
                'branch_id' => $request->attributes->get('shop_id'),
                'brand_id' => $request->attributes->get('brand_id'),
            ],
        );

        return (new CustomerResource($customer))
            ->additional(['created' => $wasCreated])
            ->response()
            ->setStatusCode($wasCreated ? 201 : 200);
    }

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/customers/{customer}/points',
        summary: 'Point balance, ledger and redeemed coupons for one customer',
        description: 'The wallet is BRAND-WIDE, not this branch: points earned anywhere in the brand spend anywhere in the brand (BR-PT04). Read-only.',
        tags: ['Customers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
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
                'balance' => $this->points->balance($customer),
                'lifetime_points' => $this->points->lifetimeEarned($customer),
                'entries' => collect($history['data'])
                    ->map(fn (CustomerPointEntry $entry): array => $this->pointEntryToArray($entry))
                    ->values()
                    ->all(),
            ],
            'meta' => [
                ...$history['meta'],
                // Ví điểm là TOÀN CỤC theo khách (BR-PT04), không phải điểm
                // tích tại chi nhánh này. Trả ra để màn hình ghi nhãn được —
                // đây đúng là chỗ người trực quầy dễ đọc nhầm nhất.
                'scope' => 'brand',
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/customers/{customer}/outstanding',
        summary: 'List this customer\'s unpaid orders in the shop',
        description: 'Returns every order in the `paying` lifecycle that still has paid_amount < total_amount, ordered by most-recent checkout first. Used by POS PaymentDialog to remind staff when a returning customer owes money from a previous visit. Includes a computed `total_owed` across the returned orders for quick display.',
        tags: ['Customers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Outstanding orders', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CustomerOrder')),
                new OA\Property(property: 'total_owed', type: 'string', example: '1450.00'),
            ])),
            new OA\Response(response: 404, description: 'Customer not found'),
        ]
    )]
    public function outstanding(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeOrganization($customer);
        $this->authorize('view', $customer);

        // #1596 — "đơn còn nợ" là câu hỏi của Ordering, không phải của
        // CustomerEngagement. Controller là Composition nên hỏi thẳng.
        $outstanding = app(CustomerOutstandingOrderService::class);
        $orders = $outstanding->listOutstanding(
            $customer->id,
            $this->getOrganizationId(),
            $request->attributes->get('shop_id'),
        );
        // #1992 — cộng trên danh sách vừa lấy. Bản cũ gọi `outstandingTotal()`,
        // mà hàm đó gọi lại `listOutstanding()`: cùng một truy vấn chạy hai lần
        // mỗi lần thu ngân mở màn thanh toán.
        $totalOwed = $outstanding->totalOf($orders);

        return response()->json([
            'data' => CustomerOrderResource::collection($orders)
                ->toArray($request),
            'total_owed' => $totalOwed,
        ]);
    }

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/customers/{customer}',
        summary: 'Show customer detail',
        tags: ['Customers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Customer detail', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/Customer'),
            ])),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(Request $request, Customer $customer): CustomerResource
    {
        $this->authorizeOrganization($customer);
        $this->authorize('view', $customer);

        return new CustomerResource($customer);
    }

    #[OA\Put(
        path: '/api/v1/shops/{shopSlug}/customers/{customer}',
        summary: 'Update customer',
        tags: ['Customers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'first_name', type: 'string', maxLength: 100),
            new OA\Property(property: 'last_name', type: 'string', maxLength: 100, nullable: true),
            new OA\Property(property: 'phone', type: 'string', nullable: true),
            new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true),
            new OA\Property(property: 'address', type: 'string', nullable: true),
            new OA\Property(property: 'tax_code', type: 'string', nullable: true),
            new OA\Property(property: 'note', type: 'string', nullable: true),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Customer updated', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/Customer'),
            ])),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function update(CustomerUpdateRequest $request, Customer $customer): CustomerResource
    {
        $this->authorizeOrganization($customer);
        $this->authorize('update', $customer);

        $customer = $this->service->update($customer, $request->validated());

        return new CustomerResource($customer);
    }

    #[OA\Delete(
        path: '/api/v1/shops/{shopSlug}/customers/{customer}',
        summary: 'Soft delete customer',
        tags: ['Customers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeOrganization($customer);
        $this->authorize('delete', $customer);

        $this->service->delete($customer);

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/customers/{customer}/restore',
        summary: 'Restore soft-deleted customer',
        tags: ['Customers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Customer restored', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/Customer'),
            ])),
        ]
    )]
    public function restore(Request $request, Customer $customer): CustomerResource
    {
        $this->authorizeOrganization($customer);
        $this->authorize('restore', $customer);

        $customer = $this->service->restore($customer);

        return new CustomerResource($customer);
    }
}
