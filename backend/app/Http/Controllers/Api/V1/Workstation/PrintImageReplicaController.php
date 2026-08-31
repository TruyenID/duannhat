<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Workstation;

use App\Http\Controllers\Controller;
use App\Models\PrintImageRaster;
use App\Services\Print\PrintImageResolver;
use App\Services\Print\PrintImageStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * #1957 mảnh B — máy trạm kéo ảnh in về.
 *
 * ## Hai bước, cố ý
 *
 * `index` trả **danh mục**: mỗi ảnh có hiệu lực, kèm hash + kích thước của từng
 * biến thể bề rộng. `show` trả **byte** theo hash.
 *
 * Gộp byte vào `index` sẽ khiến mỗi lần tick 60 giây đẩy vài trăm KB qua đường
 * truyền của quán chỉ để nói "chưa có gì đổi". Tách ra thì `index` nhỏ và máy
 * trạm chỉ gọi `show` cho hash nó CHƯA có.
 *
 * ## Byte là bất biến theo hash, nên cache được vĩnh viễn
 *
 * Một hash chỉ ứng với đúng một chuỗi byte — đó là ý nghĩa của địa chỉ theo nội
 * dung. Vì vậy `show` phát `Cache-Control: immutable`: máy trạm đã có hash đó
 * thì không bao giờ cần hỏi lại, kể cả sau khi cài lại app.
 *
 * ## S3 — thiết bị chỉ được kéo ảnh của CHI NHÁNH nó
 *
 * Thiết bị bị ghim chi nhánh từ lúc pair, nên một `branch_id` khác trong query
 * là hành vi cố ý, không phải nhầm lẫn. Cùng luật với
 * {@see PrintTemplateReplicaController}.
 */
class PrintImageReplicaController extends Controller
{
    public function __construct(
        private readonly PrintImageResolver $resolver,
        private readonly PrintImageStore $store,
    ) {}

    /** GET /workstation/print-images */
    public function index(Request $request): JsonResponse
    {
        $device = $request->attributes->get('device');
        $branchId = $device?->branch_id;

        if (! $branchId) {
            return response()->json(['data' => [], 'generated_at' => now()->toIso8601String()]);
        }

        $requestedBranch = $request->query('branch_id');
        if (is_string($requestedBranch) && $requestedBranch !== '' && $requestedBranch !== (string) $branchId) {
            return response()->json([
                'message' => 'Device not authorized for this branch.',
                'code' => 'BRANCH_MISMATCH',
            ], 403);
        }

        $data = [];
        foreach ($this->resolver->allForBranch((string) $branchId) as $source => $asset) {
            $variants = [];

            foreach ($this->store->defaultWidths() as $width) {
                $raster = $this->store->rasterFor($asset, $width);
                // null = ảnh gốc đã mất khỏi storage. TR-05: không quảng cáo bề
                // rộng này và ĐỪNG hỏng — quán vẫn phải in được phiếu.
                if ($raster === null) {
                    continue;
                }

                $variants[] = [
                    'max_width_dots' => (int) $raster->max_width_dots,
                    'width_dots' => (int) $raster->width_dots,
                    'height_dots' => (int) $raster->height_dots,
                    'content_hash' => (string) $raster->content_hash,
                    'byte_length' => (int) $raster->byte_length,
                ];
            }

            if ($variants === []) {
                continue;
            }

            $data[] = [
                'source' => $source,
                'scope' => (string) $asset->scope,
                'version' => (int) $asset->version,
                'effective_from' => $asset->effective_from?->toIso8601String(),
                'updated_at' => $asset->updated_at?->toIso8601String(),
                'variants' => $variants,
            ];
        }

        return response()->json([
            'data' => $data,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /** GET /workstation/print-images/{hash} */
    public function show(Request $request, string $hash): JsonResponse
    {
        $device = $request->attributes->get('device');
        $branchId = $device?->branch_id;

        if (! $branchId) {
            return response()->json(['message' => 'Device has no branch.', 'code' => 'NO_BRANCH'], 403);
        }

        // Tra theo hash là chưa đủ để cho phép đọc: hash là địa chỉ toàn cục, nên
        // một thiết bị đoán/nhặt được hash của brand khác sẽ đọc được logo của họ.
        // Nên xác nhận hash NẰM TRONG tập ảnh mà chính chi nhánh này có hiệu lực.
        $allowed = [];
        foreach ($this->resolver->allForBranch((string) $branchId) as $asset) {
            $allowed[] = (string) $asset->id;
        }

        $raster = PrintImageRaster::query()
            ->where('content_hash', $hash)
            ->whereIn('asset_id', $allowed)
            ->first();

        if ($raster === null) {
            return response()->json(['message' => 'Image not found.', 'code' => 'IMAGE_NOT_FOUND'], 404);
        }

        return response()
            ->json([
                'data' => [
                    'content_hash' => (string) $raster->content_hash,
                    'max_width_dots' => (int) $raster->max_width_dots,
                    'width_dots' => (int) $raster->width_dots,
                    'height_dots' => (int) $raster->height_dots,
                    'byte_length' => (int) $raster->byte_length,
                    // base64 của byte đã đóng gói MSB-first — đúng bố cục mà
                    // `escpos.Raster` nhận, không phải đóng gói lại ở phía Go.
                    'data' => (string) $raster->data,
                ],
            ])
            ->header('Cache-Control', 'public, max-age=31536000, immutable')
            ->header('ETag', '"'.$hash.'"');
    }
}
