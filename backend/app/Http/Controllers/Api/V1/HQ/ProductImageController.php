<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Resources\FileResource;
use App\Models\File;
use App\Models\Product;
use App\Services\FileUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * Manages the `gallery` file collection on a Product.
 *
 * Upload flow:
 *   1. Client calls POST /api/v1/files/upload → gets a temp File UUID.
 *   2. Client calls POST /products/{product}/images/sync with the full ordered
 *      list of UUIDs → backend attaches, reorders, and soft-deletes removed
 *      files in one transaction via Product::syncFiles().
 *
 * The first file in the response (sort_order = 0) is the main/primary image.
 */
class ProductImageController extends Controller
{
    use HasOrganizationContext;

    public function __construct(
        private readonly FileUploadService $fileUploadService,
    ) {}

    // =========================================================================
    //  sync
    // =========================================================================

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/products/{product}/images/sync',
        summary: 'Sync product gallery images',
        description: 'Accepts an ordered list of File UUIDs. Attaches new temp files to the product (making them permanent), updates sort_order from the array position, and reverts gallery files NO LONGER in the list back to TEMPORARY status with a 24h expiry — they sit in a "trash bin" until cleanupExpired() sweeps both the DB row and the physical file in MinIO. Within the 24h window the file can be re-attached by including its id in another sync call. Send the complete ordered list on every call — this is a full replace, not a patch.',
        tags: ['Product Images'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['file_ids'],
                properties: [
                    new OA\Property(
                        property: 'file_ids',
                        type: 'array',
                        maxItems: 20,
                        description: 'Ordered list of File UUIDs. Index 0 becomes the main image (sort_order 0). Pass an empty array to remove all gallery images.',
                        items: new OA\Items(type: 'string', format: 'uuid')
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated gallery — ordered by sort_order'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden — product or file belongs to another org'),
            new OA\Response(response: 404, description: 'Product not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function sync(Request $request, Product $product): AnonymousResourceCollection
    {
        $this->authorizeOrganization($product);

        $validated = $request->validate([
            'file_ids' => ['present', 'array', 'max:20'],
            'file_ids.*' => ['uuid'],
        ]);

        $fileIds = $validated['file_ids'];

        // Chặn cướp file: nếu không có bước này, một người dùng gắn được
        // temp upload của tổ chức khác vào sản phẩm của mình.
        if (! $this->fileUploadService->filesBelongToOrganization($fileIds, $this->getOrganizationId())) {
            abort(403, 'One or more files do not belong to your organization.');
        }

        $this->fileUploadService->syncGallery($product, $fileIds);

        return FileResource::collection(
            $product->gallery()->get()
        );
    }
}
