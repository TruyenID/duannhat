<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Resources\FileResource;
use App\Models\File;
use App\Models\ProductSku;
use App\Services\FileUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * Manages the `gallery` file collection on a ProductSku (variant-level images).
 *
 * Upload flow mirrors {@see ProductImageController}:
 *   1. Client calls POST /api/v1/files/upload → gets a temp File UUID.
 *   2. Client calls POST /skus/{sku}/images/sync with the full ordered list
 *      of UUIDs → backend attaches, reorders, and reverts removed files back
 *      to TEMPORARY (24h window) via ProductSku::syncFiles().
 */
class ProductSkuImageController extends Controller
{
    use HasOrganizationContext;

    public function __construct(
        private readonly FileUploadService $fileUploadService,
    ) {}

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/skus/{sku}/images/sync',
        summary: 'Sync SKU gallery images',
        description: 'Accepts an ordered list of File UUIDs for the variant gallery. Same semantics as the product-level sync: attaches new temp files (making them permanent), updates sort_order from the array position, and reverts removed files back to TEMPORARY with a 24h expiry. Send the complete ordered list on every call — full replace, not a patch.',
        tags: ['ProductSku Images'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sku', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
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
                        description: 'Ordered list of File UUIDs. Index 0 becomes the main image (sort_order 0). Pass an empty array to remove all SKU gallery images.',
                        items: new OA\Items(type: 'string', format: 'uuid')
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated gallery — ordered by sort_order'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden — SKU or file belongs to another org'),
            new OA\Response(response: 404, description: 'SKU not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function sync(Request $request, ProductSku $sku): AnonymousResourceCollection
    {
        $sku->loadMissing('product');
        $this->authorizeOrganization($sku->product);

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

        $this->fileUploadService->syncGallery($sku, $fileIds);

        return FileResource::collection(
            $sku->gallery()->get()
        );
    }
}
