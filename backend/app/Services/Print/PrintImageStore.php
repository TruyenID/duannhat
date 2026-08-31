<?php

declare(strict_types=1);

namespace App\Services\Print;

use App\Models\PrintImageAsset;
use App\Models\PrintImageRaster;
use App\Services\Print\Enums\PrintTemplateScope;
use App\Services\Print\Renderer\ImageRasteriser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

/**
 * #1957 mảnh B — nhận ảnh tải lên, raster hoá, và giữ nó ở dạng máy trạm in được.
 *
 * ## Ranh giới của lớp này
 *
 * Nó KHÔNG biết gì về HTTP, về quyền, hay về feed đồng bộ. Nó biết đúng một
 * việc: biến một mớ byte thành các hàng `print_image_assets` + `print_image_rasters`
 * sao cho mọi bất biến của mảnh B luôn đúng. Đặt nó ở đây (chứ không trong
 * controller) để lát đồng bộ và lát admin sau này dùng chung một đường ghi — hai
 * đường ghi song song là cách một bảng có hash nội dung mất tính nhất quán.
 *
 * ## Địa chỉ theo NỘI DUNG, và vì sao nó là cái đắt giá nhất ở đây
 *
 * Tải lên đúng ảnh cũ thì `original_hash` trùng và `store()` trả về chính bản
 * đang có — không phiên bản mới, không hash mới, máy trạm không phải kéo lại gì.
 * Nghe như tối ưu, thực ra là điều kiện ĐÚNG ĐẮN: hash của raster đi trong
 * `sync-manifest` (#1175), nên nếu cùng một ảnh sinh hash mới mỗi lần lưu thì
 * feed sẽ không bao giờ đứng yên và mọi máy quán kéo lại bitmap sau mỗi lượt bấm
 * "lưu" của người ở HQ.
 *
 * Đây cũng là lý do `ImageRasteriser` dùng ngưỡng cố định chứ không dither: cùng
 * ảnh phải cho cùng byte, mãi mãi.
 *
 * ## Bất biến được ép ở đây
 *
 * - **TR-21** — không bao giờ nhận URL. Đầu vào là byte + một định danh `source`
 *   nằm trong allow-list `print_blocks.image.sources`. Mở rộng sau này (quảng
 *   cáo, coupon) thêm định danh vào CÙNG allow-list đó.
 * - **TR-22** — bề rộng bị kẹp theo khổ giấy in được trước khi raster.
 * - **Trần byte** — xem {@see self::MAX_RASTER_BYTES}.
 */
final class PrintImageStore
{
    /**
     * Trần cho một bitmap đã đóng gói, tính trên byte THÔ (chưa base64).
     *
     * 2 MB ở khổ 80mm là một dải giấy cao khoảng 29.000 hàng — dài hơn mọi logo
     * thật vài bậc độ lớn. Trần này không để tiết kiệm chỗ; nó để một ảnh sai
     * (ảnh chụp toàn khung, PDF đổi đuôi thành .png) bị TỪ CHỐI ngay lúc tải lên
     * thay vì lúc máy in nhả ra hai mét giấy trắng ở quán.
     *
     * Cột `data` là MEDIUMTEXT (16 MB) nên trần này nằm gọn bên trong, kể cả sau
     * khi base64 nở 4/3. Thứ tự đó là cố ý: cột phải rộng hơn trần, không bao giờ
     * ngược lại — MySQL ngoài strict mode cắt cụt trong im lặng.
     */
    public const MAX_RASTER_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private readonly ImageRasteriser $rasteriser,
        private readonly BlockCatalog $catalog,
    ) {}

    /**
     * Disk giữ ảnh GỐC — nêu TÊN, không bao giờ để rơi vào disk mặc định.
     *
     * Trước #2136 lớp này gọi `Storage::put()`/`Storage::get()` trần, tức bám
     * `filesystems.default`. Vì `print_image_assets` KHÔNG có cột `disk`, nơi
     * byte thật sự nằm khi đó chỉ là giá trị biến môi trường tại thời điểm ghi.
     *
     * Hệ quả: đổi `FILESYSTEM_DISK` một lần là mồ côi mọi ảnh đã ghi. Bán kính
     * hỏng HẸP nhưng câm — hai bề rộng in được (384/576) đã raster sẵn và nằm
     * trong DB nên vẫn in bình thường; thứ mất là {@see self::rasterFor()} cho
     * một bề rộng CHƯA dựng sẵn, và nó trả `null` im lặng theo TR-05. Template
     * khai bề rộng lẻ sẽ lặng lẽ mất khối ảnh, không log, không lỗi.
     *
     * Đích là disk PRIVATE, và đó là chủ ý: byte gốc không bao giờ đến tay
     * client (máy trạm nhận bitmap base64 trong `print_image_rasters.data`; không
     * chỗ nào dựng URL từ `original_path`). Đừng gộp nó vào `filesystems.uploads`
     * — làm vậy là nới quyền đọc cho dữ liệu chưa từng cần công khai.
     */
    private function printImagesDisk(): string
    {
        return (string) config('filesystems.print_images', 'local');
    }

    /**
     * Đọc byte gốc, và KÊU khi tìm thấy chúng ở disk cũ.
     *
     * Byte ghi trước #2136 nằm trên `filesystems.default` của lúc ghi. Chúng vẫn
     * phải đọc được — mất logo trên phiếu in là sự cố vận hành thật — nên đây là
     * đường đọc kép, không phải một phép chuyển đổi.
     *
     * Chỗ khác biệt so với bản cũ: trường hợp "byte nằm ở disk khác" giờ **ghi
     * log cảnh báo**, thay vì lẫn vào nhánh im lặng của TR-05. TR-05 nói ảnh bị
     * XOÁ thì cứ in tiếp; nó không nói lệch cấu hình thì cứ im. Bài học đắt nhất
     * của #2101 là chính chỗ câm ấy.
     */
    private function readOriginal(PrintImageAsset $asset): ?string
    {
        $disk = $this->printImagesDisk();
        $bytes = Storage::disk($disk)->get($asset->original_path);

        if ($bytes !== null && $bytes !== '') {
            return $bytes;
        }

        // ĐÃ GỠ #2598 — vòng dò byte trên disk TRƯỚC #2136 (`filesystems.default`
        // + `public` + `local`). Đừng dựng lại.
        //
        // Nó tồn tại vì mất ảnh trên phiếu in là mất IM LẶNG: phiếu vẫn ra, chỉ
        // thiếu ảnh. Điều kiện gỡ do chính issue đặt ra là MỘT phép đo trên
        // production, và phép đo đó đã chạy 2026-08-12:
        //
        //     print_image_assets : 0   ← không asset nào, chưa bao giờ
        //     print_templates    : 0   ← cả tính năng chưa từng được dùng
        //
        // Không hàng DB nào ⇒ không đường đọc nào với tới disk cũ (store tra
        // theo asset, và `PrintImageAsset` không dùng SoftDeletes nên không có
        // tập hàng ẩn). Byte mồ côi trên disk cũ là thứ không ai tra ra được.
        //
        // Ảnh upload từ nay vào thẳng `filesystems.print_images`, nên không bao
        // giờ sinh thêm ca "chỉ nằm trên disk cũ".
        //
        // Thiếu byte ⇒ trả `null`, và TR-05 xử tiếp: phiếu vẫn in, không dò
        // ngược, không im lặng đọc từ chỗ khác.
        return null;
    }

    /**
     * Bề rộng mà một ảnh mới được raster sẵn.
     *
     * Hai khổ giấy in được, không phải mọi giá trị `max_width_dots` có thể có.
     * Một template khai bề rộng lẻ (ví dụ 300) sẽ được raster theo yêu cầu qua
     * {@see self::rasterFor()} — raster sẵn mọi bề rộng là vô hạn, còn raster
     * sẵn con số hay dùng nhất thì rẻ và phủ gần hết.
     *
     * @return list<int>
     */
    public function defaultWidths(): array
    {
        $rules = $this->catalog->imageRules();

        return array_values(array_unique(array_filter([
            (int) ($rules['printable_dots_58mm'] ?? 384),
            (int) ($rules['printable_dots_80mm'] ?? 576),
        ])));
    }

    /**
     * Lưu một ảnh tải lên cho một phạm vi, raster sẵn các bề rộng mặc định.
     *
     * @param  string  $bytes  nội dung tệp
     * @param  string  $source  định danh trong allow-list (`brand_logo`…)
     * @param  array{organization_id?: ?string, brand_id?: ?string, branch_id?: ?string}  $owner
     * @param  array{mime?: string, filename?: ?string, notes?: ?string, created_by?: ?string}  $meta
     */
    public function store(
        string $bytes,
        string $source,
        PrintTemplateScope $scope,
        array $owner,
        array $meta = [],
    ): PrintImageAsset {
        $this->assertAllowedSource($source);

        if ($bytes === '') {
            throw new InvalidArgumentException('print image: empty upload');
        }

        $hash = hash('sha256', $bytes);

        // Cùng byte cho cùng phạm vi ⇒ trả lại bản đang có. Xem docblock lớp:
        // đây là điều kiện để feed đồng bộ đứng yên, không phải tối ưu.
        $existing = $this->currentAsset($source, $scope, $owner);
        if ($existing !== null && $existing->original_hash === $hash) {
            return $existing;
        }

        // Raster TRƯỚC khi ghi bất cứ gì: một ảnh không giải mã được, hay một ảnh
        // vượt trần, phải hỏng mà KHÔNG để lại hàng mồ côi nào và không để lại
        // tệp rác trong object storage.
        $rasters = [];
        foreach ($this->defaultWidths() as $width) {
            $rasters[$width] = $this->rasteriseClamped($bytes, $width);
        }

        $path = sprintf(
            'print-images/%s/%s-%s',
            $owner['brand_id'] ?? $owner['organization_id'] ?? 'system',
            $source,
            $hash,
        );

        return DB::transaction(function () use ($bytes, $hash, $source, $scope, $owner, $meta, $rasters, $path) {
            Storage::disk($this->printImagesDisk())->put($path, $bytes);

            $asset = new PrintImageAsset;
            $asset->organization_id = $owner['organization_id'] ?? null;
            $asset->brand_id = $owner['brand_id'] ?? null;
            $asset->branch_id = $owner['branch_id'] ?? null;
            $asset->source = $source;
            $asset->scope = $scope->value;
            $asset->version = $this->nextVersion($source, $scope, $owner);
            $asset->status = 'draft';
            $asset->original_path = $path;
            $asset->original_mime = $meta['mime'] ?? 'application/octet-stream';
            $asset->original_filename = $meta['filename'] ?? null;
            $asset->original_bytes = strlen($bytes);
            $asset->original_hash = $hash;
            $asset->notes = $meta['notes'] ?? null;
            $asset->created_by_id = $meta['created_by'] ?? null;
            $asset->save();

            foreach ($rasters as $width => $raster) {
                $this->persistRaster($asset, $width, $raster);
            }

            return $asset;
        });
    }

    /**
     * Lấy bitmap ở một bề rộng, raster theo yêu cầu nếu chưa có.
     *
     * Trả `null` khi không có ảnh nào cho phạm vi này — KHÔNG ném. Đó là TR-05:
     * thiếu byte thì phiếu vẫn in, chỉ thiếu khối. Một máy chưa từng online cũng
     * phải in được, và một quán bị xoá mất logo không được ngừng bán hàng.
     */
    public function rasterFor(PrintImageAsset $asset, int $maxWidthDots): ?PrintImageRaster
    {
        $width = $this->clampWidth($maxWidthDots);

        $found = PrintImageRaster::query()
            ->where('asset_id', $asset->id)
            ->where('max_width_dots', $width)
            ->first();

        if ($found !== null) {
            return $found;
        }

        $bytes = $this->readOriginal($asset);
        if ($bytes === null || $bytes === '') {
            // Ảnh gốc biến mất khỏi storage. Không ném — xem TR-05 ở trên. Lát
            // đồng bộ sẽ đơn giản là không quảng cáo bề rộng này.
            return null;
        }

        return $this->persistRaster($asset, $width, $this->rasteriseClamped($bytes, $width));
    }

    /**
     * Bản đang có hiệu lực cho một phạm vi, hoặc null.
     *
     * @param  array{organization_id?: ?string, brand_id?: ?string, branch_id?: ?string}  $owner
     */
    public function currentAsset(string $source, PrintTemplateScope $scope, array $owner): ?PrintImageAsset
    {
        return $this->scopeQuery($source, $scope, $owner)
            ->orderByDesc('version')
            ->first();
    }

    /** Đưa một bản nháp vào hiệu lực. */
    public function publish(PrintImageAsset $asset, ?string $publishedBy = null, ?string $effectiveFrom = null): PrintImageAsset
    {
        $asset->status = 'published';
        $asset->published_by_id = $publishedBy;
        $asset->published_at = now();
        $asset->effective_from = $effectiveFrom;
        $asset->save();

        return $asset;
    }

    /**
     * Raster hoá với bề rộng đã kẹp và trần byte đã ép.
     *
     * @return array{width: int, height: int, data: string}
     */
    private function rasteriseClamped(string $bytes, int $maxWidthDots): array
    {
        $raster = $this->rasteriser->rasterise($bytes, $this->clampWidth($maxWidthDots));

        if (strlen($raster['data']) > self::MAX_RASTER_BYTES) {
            throw new RuntimeException(sprintf(
                'print image: raster is %d bytes, over the %d byte ceiling',
                strlen($raster['data']),
                self::MAX_RASTER_BYTES,
            ));
        }

        return $raster;
    }

    /** TR-22 — không bao giờ vượt bề rộng in được của khổ rộng nhất. */
    private function clampWidth(int $maxWidthDots): int
    {
        if ($maxWidthDots <= 0) {
            throw new InvalidArgumentException('print image: max width must be positive');
        }

        $rules = $this->catalog->imageRules();

        return min($maxWidthDots, (int) ($rules['printable_dots_80mm'] ?? 576));
    }

    /** @param  array{width: int, height: int, data: string}  $raster */
    private function persistRaster(PrintImageAsset $asset, int $width, array $raster): PrintImageRaster
    {
        $row = new PrintImageRaster;
        $row->asset_id = $asset->id;
        $row->max_width_dots = $width;
        $row->width_dots = $raster['width'];
        $row->height_dots = $raster['height'];
        $row->data = base64_encode($raster['data']);
        // Hash trên byte THÔ, không phải trên base64. Base64 chỉ là cách vận
        // chuyển; hash phải nói về bitmap để hai phía so được với nhau.
        $row->content_hash = hash('sha256', $raster['data']);
        $row->byte_length = strlen($raster['data']);
        $row->save();

        return $row;
    }

    private function assertAllowedSource(string $source): void
    {
        $allowed = (array) ($this->catalog->imageRules()['sources'] ?? []);

        if (! in_array($source, $allowed, true)) {
            // TR-21 sống ở đây. Danh sách đóng là toàn bộ lý do definition không
            // cần mang URL — nới nó ra bằng một chuỗi tuỳ ý là gỡ chính cái chốt.
            throw new InvalidArgumentException("print image: source '{$source}' is not in the allow-list");
        }
    }

    /** @param  array{organization_id?: ?string, brand_id?: ?string, branch_id?: ?string}  $owner */
    private function nextVersion(string $source, PrintTemplateScope $scope, array $owner): int
    {
        return (int) $this->scopeQuery($source, $scope, $owner)->max('version') + 1;
    }

    /**
     * @param  array{organization_id?: ?string, brand_id?: ?string, branch_id?: ?string}  $owner
     * @return Builder<PrintImageAsset>
     */
    private function scopeQuery(string $source, PrintTemplateScope $scope, array $owner)
    {
        $query = PrintImageAsset::query()
            ->where('source', $source)
            ->where('scope', $scope->value);

        // `where(col, null)` sinh `= NULL` (không bao giờ đúng), nên phải tách
        // nhánh. Bỏ sót chỗ này thì mọi phạm vi cấp brand đều trượt khớp và mỗi
        // lần tải lên lại đẻ version 1 mới.
        foreach (['organization_id', 'brand_id', 'branch_id'] as $column) {
            $value = $owner[$column] ?? null;
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }

        return $query;
    }
}
