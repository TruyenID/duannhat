<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreReviewRequest;
use App\Models\CustomerOrder;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Services\Customer\ProductReviewService;
use App\Services\Customer\ValueObjects\ReviewedOrder;
use App\Services\FileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Customer Reviews',
    description: 'Post-order product review endpoints. Uses order UUID as opaque access token (no auth required).',
)]
class CustomerReviewController extends Controller
{
    public function __construct(
        private readonly ProductReviewService $reviewService,
        private readonly FileUploadService $fileUploadService,
    ) {}

    /**
     * Upload review photos (multipart) for a closed order.
     *
     * Files land as TEMPORARY rows on the polymorphic `files` table (collection
     * `review`, on the configured upload disk — `filesystems.uploads`, #2163)
     * and are made permanent + attached to the BranchReview when the
     * branch-review submit includes their ids in `photo_file_ids`.
     * Unattached temp files are swept by the existing cleanupExpired job (12h).
     */
    public function uploadPhotos(Request $request, string $orderId): JsonResponse
    {
        $order = CustomerOrder::find($orderId);

        if (! $order) {
            return response()->json(['message' => 'Order not found', 'code' => 'order_not_found'], 404);
        }

        if ($order->status !== CustomerOrderStatusEnum::Closed) {
            return response()->json(['message' => 'Order is not closed', 'code' => 'order_not_closed'], 422);
        }

        $request->validate([
            'photos' => ['required', 'array', 'min:1', 'max:6'],
            'photos.*' => ['file', 'image', 'max:5120'], // 5MB each
        ]);

        $uploaded = [];
        foreach ($request->file('photos') as $file) {
            $record = $this->fileUploadService->uploadTemp(
                $file,
                (string) $order->organization_id,
                collection: 'review',
                // #2163 — ĐỌC config, đừng ghi cứng. `'public'` ở đây trùng với
                // mặc định của `filesystems.uploads`, nên nó chạy đúng **do
                // trùng hợp**: một quán đổi `UPLOADS_DISK` sẽ đẩy ảnh /files
                // sang disk mới còn ảnh đánh giá ở lại `public`, và không có gì
                // kêu lên. Cùng họ với đích upload của shop, chỉ là chưa nổ.
                disk: (string) config('filesystems.uploads'),
            );
            $uploaded[] = ['id' => $record->id, 'url' => $record->getUrl()];
        }

        return response()->json(['data' => $uploaded], 201);
    }

    #[OA\Get(
        path: '/api/v1/customer/orders/{orderId}/reviewable',
        summary: 'List order items eligible for review',
        description: 'Returns the items of a closed order with an `already_reviewed` flag. Voided items are excluded.',
        tags: ['Customer Reviews'],
        parameters: [
            new OA\Parameter(name: 'orderId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Reviewable items list',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'order_id', type: 'string', format: 'uuid'),
                                new OA\Property(
                                    property: 'items',
                                    type: 'array',
                                    items: new OA\Items(
                                        properties: [
                                            new OA\Property(property: 'order_item_id', type: 'string', format: 'uuid'),
                                            new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
                                            new OA\Property(property: 'product_name', type: 'string'),
                                            new OA\Property(property: 'product_image', type: 'string', format: 'uri', nullable: true),
                                            new OA\Property(property: 'variant_name', type: 'string', nullable: true),
                                            new OA\Property(property: 'already_reviewed', type: 'boolean'),
                                        ],
                                        type: 'object',
                                    ),
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

        $items = $this->reviewService->reviewableItems((string) $order->id);

        return response()->json([
            'data' => [
                'order_id' => $order->id,
                // Currency the order was created in — customer-web formats the
                // review-card prices with this so they never render in the
                // ambient selected-branch currency (e.g. JPY order shown as USD).
                'currency' => $order->branch?->currency ?? 'JPY',
                'items' => $items,
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/customer/orders/{orderId}/reviews',
        summary: 'Submit batch product reviews',
        description: 'Batch submit 1-5 star reviews for items in a closed order. A binary recommend sentiment (up when rating >= 3, else down) is derived server-side. Already-reviewed and voided items are skipped; a concurrent duplicate is skipped, not errored.',
        tags: ['Customer Reviews'],
        parameters: [
            new OA\Parameter(name: 'orderId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reviews'],
                properties: [
                    new OA\Property(
                        property: 'reviews',
                        type: 'array',
                        items: new OA\Items(
                            required: ['order_item_id', 'product_id', 'rating'],
                            properties: [
                                new OA\Property(property: 'order_item_id', type: 'string', format: 'uuid'),
                                new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
                                new OA\Property(property: 'rating', type: 'integer', minimum: 1, maximum: 5, description: '1-5 star rating'),
                                new OA\Property(property: 'tags', type: 'array', items: new OA\Items(type: 'string', maxLength: 50), maxItems: 10, nullable: true),
                                new OA\Property(property: 'comment', type: 'string', maxLength: 1000, nullable: true),
                            ],
                            type: 'object',
                        ),
                    ),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Reviews submitted',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'created', type: 'integer'),
                                new OA\Property(property: 'skipped', type: 'integer'),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 404, description: 'Order not found'),
            new OA\Response(response: 422, description: 'Validation error or order not closed'),
        ],
    )]
    public function store(StoreReviewRequest $request, string $orderId): JsonResponse
    {
        $order = CustomerOrder::find($orderId);

        if (! $order) {
            return response()->json(['message' => 'Order not found', 'code' => 'order_not_found'], 404);
        }

        if ($order->status !== CustomerOrderStatusEnum::Closed) {
            return response()->json(['message' => 'Order is not closed', 'code' => 'order_not_closed'], 422);
        }

        // Opportunistically attach customer_id if authenticated
        $customerId = auth('customer')->id();

        // #962 — service nhận bốn cột nó thật sự dùng, không nhận cả model đơn.
        $result = $this->reviewService->submit(
            new ReviewedOrder(
                id: (string) $order->id,
                organizationId: $order->organization_id,
                brandId: $order->brand_id,
                branchId: $order->branch_id,
            ),
            $request->validated()['reviews'],
            $customerId,
        );

        return response()->json(['data' => $result], 201);
    }
}
