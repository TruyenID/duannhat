<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Brand;
use App\Services\Provisioning\ReadinessService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Brand đã đủ điều kiện bán hàng chưa (#2344).
 *
 * Phơi ra HTTP đúng thứ `php artisan provisioning:reconcile --dry-run` in ở
 * CLI, không hơn: cùng hai provisioner, cùng ba trạng thái.
 *
 * **CHỈ ĐỌC — gọi `plan()`, không bao giờ `ensure()`.** Một GET không được sửa
 * dữ liệu, và đó không phải sự cẩn thận thừa: nếu endpoint này tự vá thì mỗi
 * lượt admin-web mở trang là một lượt ghi vào catalog, và người vận hành mất
 * luôn khả năng NHÌN cái đang thiếu trước khi quyết định. Muốn sửa thì chạy
 * lệnh — một hành động có chủ ý, có log.
 */
class BrandReadinessController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ReadinessService $readiness,
    ) {}

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/readiness',
        summary: 'Baseline readiness for a brand and its shops',
        description: 'Read-only checklist of the provisioning baseline (tax types, Reverb credentials, combo product type, per-shop order settings and floor plan). Never writes — run `php artisan provisioning:reconcile` to fix what it reports.',
        tags: ['HQ'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Readiness checklist',
                content: new OA\JsonContent(properties: [
                    new OA\Property(
                        property: 'data',
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'ready', type: 'boolean'),
                            new OA\Property(
                                property: 'checks',
                                type: 'array',
                                items: new OA\Items(properties: [
                                    new OA\Property(property: 'subject', type: 'string', example: 'brand:betoya'),
                                    new OA\Property(property: 'key', type: 'string', example: 'brand.tax_types'),
                                    new OA\Property(property: 'state', type: 'string', enum: ['satisfied', 'missing', 'skipped']),
                                    new OA\Property(property: 'detail', type: 'string'),
                                ], type: 'object'),
                            ),
                        ],
                    ),
                ]),
            ),
            new OA\Response(response: 403, description: 'Brand belongs to another organization'),
            new OA\Response(response: 404, description: 'Unknown brand slug'),
        ],
    )]
    public function show(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Branch::class);

        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');

        return response()->json(['data' => $this->readiness->forBrand($brand)]);
    }
}
