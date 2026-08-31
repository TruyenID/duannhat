<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\PrintImageAsset;
use App\Services\Print\BlockCatalog;
use App\Services\Print\Enums\PrintTemplateScope;
use App\Services\Print\PrintImageStore;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * #1957 mảnh B — HQ tải ảnh in lên cho brand.
 *
 * ## Vì sao tải lên và publish là HAI thao tác
 *
 * Giống hệt template. `upload` tạo bản NHÁP; chỉ `publish` mới đẩy nó xuống máy
 * quán. Gộp làm một thì mọi lần kéo nhầm tệp sẽ in ngay ở quán trước khi ai kịp
 * nhìn — và không có nút hoàn tác nào, vì bản in đã ra khỏi máy.
 *
 * ## Không có `destroy`
 *
 * Một ảnh đã publish phải còn render được mãi để bản in lại của phiếu cũ là
 * trung thực (TR-28/TR-39). Đường ra là publish phiên bản MỚI.
 *
 * ## Lỗi của người dùng phải là 422, không phải 500
 *
 * `PrintImageStore` ném `InvalidArgumentException` cho tệp không giải mã được và
 * `RuntimeException` cho ảnh vượt trần. Cả hai là **lỗi của tệp được chọn**, nên
 * chúng phải quay lại thành thông báo đọc được ở màn tải lên. Để chúng nổi lên
 * thành 500 nghĩa là người vận hành thấy "lỗi hệ thống" trong khi việc cần làm
 * là chọn tệp khác.
 */
class PrintImageController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly PrintImageStore $store,
        private readonly BlockCatalog $catalog,
    ) {}

    /** GET /hq/{brand}/print-images */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PrintImageAsset::class);
        $brand = $this->brand($request);

        $data = [];

        foreach ($this->imageSources() as $source) {
            $asset = $this->store->currentAsset($source, PrintTemplateScope::Brand, [
                'organization_id' => $this->organizationId($brand),
                'brand_id' => $brand->id,
            ]);

            $data[] = [
                'source' => $source,
                // `null` là trạng thái HỢP LỆ: brand chưa tải logo nào. Trả về một
                // hàng có `asset: null` thay vì bỏ qua, để giao diện liệt kê đủ
                // các ô có thể tải lên mà không phải tự biết allow-list.
                'asset' => $asset === null ? null : $this->present($asset),
            ];
        }

        return response()->json(['data' => $data]);
    }

    /** POST /hq/{brand}/print-images/{source} */
    public function upload(Request $request, string $source): JsonResponse
    {
        $this->authorize('manageBrand', PrintImageAsset::class);
        $brand = $this->brand($request);
        $this->assertKnownSource($source);

        $rules = $this->catalog->imageRules();

        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:'.implode(',', (array) ($rules['formats'] ?? ['png'])),
                // Trần ở tầng HTTP tính bằng KB trên tệp GỐC, khác trần
                // `MAX_RASTER_BYTES` vốn tính trên bitmap đã raster. Cần cả hai:
                // một PNG nén tốt 500 KB có thể nở thành bitmap khổng lồ, còn một
                // tệp 50 MB thì phải chặn trước khi GD đọc nó vào bộ nhớ.
                'max:10240',
            ],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $file = $request->file('file');

        try {
            $asset = $this->store->store(
                (string) file_get_contents($file->getRealPath()),
                $source,
                PrintTemplateScope::Brand,
                [
                    'organization_id' => $this->organizationId($brand),
                    'brand_id' => $brand->id,
                ],
                [
                    'mime' => (string) $file->getMimeType(),
                    'filename' => $file->getClientOriginalName(),
                    'notes' => $request->input('notes'),
                    'created_by' => $request->user()?->id,
                ],
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'PRINT_IMAGE_REJECTED',
            ], 422);
        }

        return response()->json(['data' => $this->present($asset)], 201);
    }

    /** POST /hq/{brand}/print-images/{source}/publish */
    public function publish(Request $request, string $source): JsonResponse
    {
        $this->authorize('manageBrand', PrintImageAsset::class);
        $brand = $this->brand($request);
        $this->assertKnownSource($source);

        $data = $request->validate([
            // Giờ treo tường của CHI NHÁNH, không phải instant — cùng ngữ nghĩa
            // với `print_templates.effective_from`. Xem `PrintImageResolver`.
            'effective_from' => ['sometimes', 'nullable', 'date_format:Y-m-d H:i:s'],
        ]);

        $asset = $this->store->currentAsset($source, PrintTemplateScope::Brand, [
            'organization_id' => $this->organizationId($brand),
            'brand_id' => $brand->id,
        ]);

        if ($asset === null) {
            return response()->json([
                'message' => "No image uploaded for [{$source}].",
                'code' => 'PRINT_IMAGE_NOT_FOUND',
            ], 404);
        }

        $published = $this->store->publish($asset, $request->user()?->id, $data['effective_from'] ?? null);

        return response()->json(['data' => $this->present($published)]);
    }

    /** @return array<string, mixed> */
    private function present(PrintImageAsset $asset): array
    {
        $variants = [];

        foreach ($this->store->defaultWidths() as $width) {
            $raster = $this->store->rasterFor($asset, $width);
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

        return [
            'id' => (string) $asset->id,
            'source' => (string) $asset->source,
            'scope' => (string) $asset->scope,
            'version' => (int) $asset->version,
            'status' => (string) $asset->status,
            'original_filename' => $asset->original_filename,
            'original_bytes' => (int) $asset->original_bytes,
            'original_hash' => (string) $asset->original_hash,
            'effective_from' => $asset->effective_from?->toIso8601String(),
            'published_at' => $asset->published_at?->toIso8601String(),
            'updated_at' => $asset->updated_at?->toIso8601String(),
            'variants' => $variants,
        ];
    }

    /** @return list<string> */
    private function imageSources(): array
    {
        return array_map('strval', (array) ($this->catalog->imageRules()['sources'] ?? []));
    }

    private function assertKnownSource(string $source): void
    {
        // TR-06 tương ứng cho ảnh: `source` lạ là 422, không phải 500 hay một
        // hàng lặng lẽ được tạo dưới một định danh không ai đọc.
        if (! in_array($source, $this->imageSources(), true)) {
            abort(response()->json([
                'message' => "Unknown print image source [{$source}].",
                'code' => 'PRINT_IMAGE_SOURCE_UNKNOWN',
            ], 422));
        }
    }

    /**
     * `brands` KHÔNG có `organization_id` — nó mang `console_organization_id`,
     * định danh do Platform cấp. Cùng đường vòng mà `TemplateVersionService` đi.
     *
     * Sai chỗ này im lặng: `$brand->organization_id` cho ra `null`, và `null` là
     * giá trị HỢP LỆ của cột (phạm vi cấp brand không bắt buộc có org), nên hàng
     * vẫn ghi được — chỉ là không thuộc về tổ chức nào và không lọc ra được.
     */
    private function organizationId(Brand $brand): ?string
    {
        return DB::table('organizations')
            ->where('console_organization_id', $brand->console_organization_id)
            ->value('id');
    }

    private function brand(Request $request): Brand
    {
        $brand = $request->attributes->get('brand');
        if (! $brand instanceof Brand) {
            abort(400, 'Brand context not resolved.');
        }

        return $brand;
    }
}
