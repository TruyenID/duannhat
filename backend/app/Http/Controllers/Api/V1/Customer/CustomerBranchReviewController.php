<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreBranchReviewRequest;
use App\Models\CustomerOrder;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Services\Customer\BranchReviewService;
use App\Services\Order\Internal\CustomerOrderSnapshot;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Customer Branch Reviews',
    description: 'Post-order branch review endpoints. Uses order UUID as opaque access token (no auth required).',
)]
class CustomerBranchReviewController extends Controller
{
    public function __construct(
        private readonly BranchReviewService $branchReviewService,
    ) {}

    #[OA\Post(
        path: '/api/v1/customer/orders/{orderId}/branch-review',
        summary: 'Submit a branch review',
        description: 'Submit a 1-5 star rating with optional comment for the branch associated with a closed order. One review per order.',
        tags: ['Customer Branch Reviews'],
        parameters: [
            new OA\Parameter(name: 'orderId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['rating'],
                properties: [
                    new OA\Property(property: 'rating', type: 'integer', minimum: 1, maximum: 5),
                    new OA\Property(property: 'service_rating', type: 'integer', minimum: 1, maximum: 5, nullable: true),
                    new OA\Property(property: 'staff_rating', type: 'integer', minimum: 1, maximum: 5, nullable: true),
                    new OA\Property(property: 'space_rating', type: 'integer', minimum: 1, maximum: 5, nullable: true),
                    new OA\Property(property: 'highlights', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
                    new OA\Property(property: 'photo_file_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), nullable: true),
                    new OA\Property(property: 'comment', type: 'string', maxLength: 2000, nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Review submitted',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                                new OA\Property(property: 'rating', type: 'integer'),
                                new OA\Property(property: 'service_rating', type: 'integer', nullable: true),
                                new OA\Property(property: 'staff_rating', type: 'integer', nullable: true),
                                new OA\Property(property: 'space_rating', type: 'integer', nullable: true),
                                new OA\Property(property: 'highlights', type: 'array', items: new OA\Items(type: 'string')),
                                new OA\Property(property: 'comment', type: 'string', nullable: true),
                                new OA\Property(property: 'photos', type: 'array', items: new OA\Items(properties: [
                                    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                                    new OA\Property(property: 'url', type: 'string', nullable: true),
                                ])),
                                new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 404, description: 'Order not found'),
            new OA\Response(response: 422, description: 'Order not closed, already reviewed, or validation error'),
        ],
    )]
    public function store(StoreBranchReviewRequest $request, string $orderId): JsonResponse
    {
        $order = CustomerOrder::find($orderId);

        if (! $order) {
            return response()->json(['message' => 'Order not found', 'code' => 'order_not_found'], 404);
        }

        if ($order->status !== CustomerOrderStatusEnum::Closed) {
            return response()->json(['message' => 'Order is not closed', 'code' => 'order_not_closed'], 422);
        }

        $customerId = auth('customer')->id();
        $validated = $request->validated();

        $result = $this->branchReviewService->submit(
            CustomerOrderSnapshot::fromModel($order),
            $validated['rating'],
            $validated['comment'] ?? null,
            $customerId,
            [
                'service_rating' => $validated['service_rating'] ?? null,
                'staff_rating' => $validated['staff_rating'] ?? null,
                'space_rating' => $validated['space_rating'] ?? null,
                'highlights' => $validated['highlights'] ?? null,
                'photo_file_ids' => $validated['photo_file_ids'] ?? null,
            ],
        );

        if ($result['already_existed']) {
            return response()->json(['message' => 'Order already has a branch review', 'code' => 'already_reviewed'], 422);
        }

        $review = $result['review'];

        return response()->json([
            'data' => $review->toReviewArray(),
        ], 201);
    }

    #[OA\Get(
        path: '/api/v1/customer/orders/{orderId}/branch-reviewable',
        summary: 'Check if order is eligible for branch review',
        description: 'Returns whether the order can be reviewed for the branch, including any existing review data.',
        tags: ['Customer Branch Reviews'],
        parameters: [
            new OA\Parameter(name: 'orderId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Reviewable status',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'can_review', type: 'boolean'),
                                new OA\Property(
                                    property: 'existing_review',
                                    type: 'object',
                                    nullable: true,
                                    properties: [
                                        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                                        new OA\Property(property: 'rating', type: 'integer'),
                                        new OA\Property(property: 'service_rating', type: 'integer', nullable: true),
                                        new OA\Property(property: 'staff_rating', type: 'integer', nullable: true),
                                        new OA\Property(property: 'space_rating', type: 'integer', nullable: true),
                                        new OA\Property(property: 'highlights', type: 'array', items: new OA\Items(type: 'string')),
                                        new OA\Property(property: 'comment', type: 'string', nullable: true),
                                        new OA\Property(property: 'photos', type: 'array', items: new OA\Items(properties: [
                                            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                                            new OA\Property(property: 'url', type: 'string', nullable: true),
                                        ])),
                                        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                                    ],
                                ),
                                new OA\Property(
                                    property: 'branch',
                                    type: 'object',
                                    properties: [
                                        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                                        new OA\Property(property: 'name', type: 'string'),
                                    ],
                                ),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 404, description: 'Order not found'),
            new OA\Response(response: 422, description: 'Order not closed'),
        ],
    )]
    public function reviewable(string $orderId): JsonResponse
    {
        $order = CustomerOrder::find($orderId);

        if (! $order) {
            return response()->json(['message' => 'Order not found', 'code' => 'order_not_found'], 404);
        }

        if ($order->status !== CustomerOrderStatusEnum::Closed) {
            return response()->json(['message' => 'Order is not closed', 'code' => 'order_not_closed'], 422);
        }

        $status = $this->branchReviewService->getReviewableStatus(CustomerOrderSnapshot::fromModel($order));

        $existingReview = $status['existing_review'];

        return response()->json([
            'data' => [
                'can_review' => $status['can_review'],
                'existing_review' => $existingReview?->toReviewArray(),
                'branch' => $status['branch'],
            ],
        ]);
    }
}
