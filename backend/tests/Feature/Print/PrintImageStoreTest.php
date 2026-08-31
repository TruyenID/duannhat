<?php

declare(strict_types=1);

use App\Models\PrintImageAsset;
use App\Models\PrintImageRaster;
use App\Services\Print\Enums\PrintTemplateScope;
use App\Services\Print\PrintImageStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * #1957 mảnh B — cổng cho `PrintImageStore`.
 *
 * Mỗi test ở đây tương ứng một bất biến đã ghi trong thiết kế của issue. Cái
 * đáng canh nhất KHÔNG phải "lưu được ảnh" mà là **tính bền của hash**: hash
 * raster đi trong `sync-manifest`, nên một lần lưu sinh hash mới cho cùng một
 * ảnh sẽ khiến mọi máy quán kéo lại bitmap sau mỗi lượt bấm "lưu" ở HQ — hỏng
 * theo kiểu không ai nhìn thấy trong log, chỉ thấy hoá đơn in chậm đi.
 */
beforeEach(function () {
    Storage::fake();
    $this->store = app(PrintImageStore::class);
    $this->owner = ['organization_id' => null, 'brand_id' => null, 'branch_id' => null];
});

/** PNG đặc, có kích thước thật, để GD giải mã được. */
function pngOfSize(int $w, int $h, int $grey = 0): string
{
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, (int) imagecolorallocate($img, $grey, $grey, $grey));
    ob_start();
    imagepng($img);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

it('lưu ảnh và raster sẵn đúng hai khổ giấy in được', function () {
    $asset = $this->store->store(pngOfSize(200, 100), 'brand_logo', PrintTemplateScope::Brand, $this->owner);

    $widths = PrintImageRaster::where('asset_id', $asset->id)->pluck('max_width_dots')->sort()->values()->all();

    expect($widths)->toBe([384, 576])
        ->and($asset->status)->toBe('draft')
        ->and($asset->version)->toBe(1)
        ->and($asset->original_hash)->toHaveLength(64);
});

it('tải lại ĐÚNG ảnh cũ không sinh phiên bản mới — feed đồng bộ phải đứng yên', function () {
    $bytes = pngOfSize(200, 100);

    $first = $this->store->store($bytes, 'brand_logo', PrintTemplateScope::Brand, $this->owner);
    $second = $this->store->store($bytes, 'brand_logo', PrintTemplateScope::Brand, $this->owner);

    expect($second->id)->toBe($first->id)
        ->and(PrintImageAsset::count())->toBe(1)
        ->and(PrintImageRaster::count())->toBe(2);
});

it('ảnh KHÁC thì lên phiên bản mới', function () {
    $this->store->store(pngOfSize(200, 100, 0), 'brand_logo', PrintTemplateScope::Brand, $this->owner);
    $v2 = $this->store->store(pngOfSize(200, 100, 40), 'brand_logo', PrintTemplateScope::Brand, $this->owner);

    expect($v2->version)->toBe(2)->and(PrintImageAsset::count())->toBe(2);
});

it('cùng ảnh cho ra cùng content_hash — ngưỡng cố định, không dither', function () {
    $bytes = pngOfSize(200, 100, 90);

    $a = $this->store->store($bytes, 'brand_logo', PrintTemplateScope::Brand, $this->owner);
    // Phạm vi khác ⇒ hàng asset khác, nhưng bitmap phải giống hệt.
    $b = $this->store->store($bytes, 'branch_logo', PrintTemplateScope::Shop, $this->owner);

    $hashA = PrintImageRaster::where('asset_id', $a->id)->where('max_width_dots', 576)->value('content_hash');
    $hashB = PrintImageRaster::where('asset_id', $b->id)->where('max_width_dots', 576)->value('content_hash');

    expect($hashA)->toBe($hashB);
});

it('hash tính trên byte THÔ chứ không trên base64', function () {
    $asset = $this->store->store(pngOfSize(120, 60), 'brand_logo', PrintTemplateScope::Brand, $this->owner);
    $raster = PrintImageRaster::where('asset_id', $asset->id)->firstOrFail();

    $raw = base64_decode($raster->data, true);

    expect($raster->content_hash)->toBe(hash('sha256', $raw))
        ->and($raster->content_hash)->not->toBe(hash('sha256', $raster->data))
        ->and($raster->byte_length)->toBe(strlen($raw));
});

it('TR-22 — bề rộng vượt khổ in được bị kẹp, không bị từ chối', function () {
    $asset = $this->store->store(pngOfSize(2000, 100), 'brand_logo', PrintTemplateScope::Brand, $this->owner);
    $raster = $this->store->rasterFor($asset, 9999);

    expect($raster)->not->toBeNull()
        ->and($raster->max_width_dots)->toBe(576)
        ->and($raster->width_dots)->toBe(576);
});

it('KHÔNG phóng to ảnh hẹp hơn trần — kéo giãn chỉ làm nhoè và tốn giấy', function () {
    $asset = $this->store->store(pngOfSize(100, 50), 'brand_logo', PrintTemplateScope::Brand, $this->owner);
    $raster = PrintImageRaster::where('asset_id', $asset->id)->where('max_width_dots', 576)->firstOrFail();

    expect($raster->width_dots)->toBe(100);
});

it('TR-21 — `source` ngoài allow-list bị từ chối', function () {
    expect(fn () => $this->store->store(pngOfSize(50, 50), 'https://evil.example/x.png', PrintTemplateScope::Brand, $this->owner))
        ->toThrow(InvalidArgumentException::class, 'allow-list');

    expect(fn () => $this->store->store(pngOfSize(50, 50), 'promo_banner', PrintTemplateScope::Brand, $this->owner))
        ->toThrow(InvalidArgumentException::class, 'allow-list');
});

it('ảnh không giải mã được bị từ chối và KHÔNG để lại hàng mồ côi', function () {
    expect(fn () => $this->store->store('không phải ảnh', 'brand_logo', PrintTemplateScope::Brand, $this->owner))
        ->toThrow(InvalidArgumentException::class);

    // Đây mới là điều đáng canh: hỏng giữa chừng không được để lại asset trống
    // hay tệp rác — người sau sẽ đọc nó như một logo hợp lệ in ra khoảng trắng.
    expect(PrintImageAsset::count())->toBe(0)
        ->and(PrintImageRaster::count())->toBe(0)
        ->and(Storage::allFiles())->toBe([]);
});

it('raster theo yêu cầu ở bề rộng lẻ, và lần hai dùng lại hàng cũ', function () {
    $asset = $this->store->store(pngOfSize(400, 200), 'brand_logo', PrintTemplateScope::Brand, $this->owner);

    $first = $this->store->rasterFor($asset, 300);
    $second = $this->store->rasterFor($asset, 300);

    expect($first->max_width_dots)->toBe(300)
        ->and($second->id)->toBe($first->id)
        ->and(PrintImageRaster::where('asset_id', $asset->id)->count())->toBe(3);
});

it('TR-05 — mất ảnh gốc thì trả null, KHÔNG ném: quán vẫn phải in được', function () {
    $asset = $this->store->store(pngOfSize(200, 100), 'brand_logo', PrintTemplateScope::Brand, $this->owner);
    Storage::delete($asset->original_path);

    // 300 chưa được raster sẵn ⇒ buộc phải đọc ảnh gốc, và ảnh gốc đã mất.
    expect($this->store->rasterFor($asset, 300))->toBeNull();
});

it('publish đưa bản nháp vào hiệu lực', function () {
    $asset = $this->store->store(pngOfSize(120, 60), 'brand_logo', PrintTemplateScope::Brand, $this->owner);

    $published = $this->store->publish($asset, null, '2026-09-01 00:00:00');

    expect($published->status)->toBe('published')
        ->and($published->published_at)->not->toBeNull()
        ->and((string) $published->effective_from)->toContain('2026-09-01');
});

it('trần byte chặn một bitmap khổng lồ trước khi nó chạm DB', function () {
    // 576 dots ngang × 40.000 hàng ≈ 2.88 MB thô — trên trần 2 MB.
    expect(fn () => $this->store->store(pngOfSize(576, 40_000), 'brand_logo', PrintTemplateScope::Brand, $this->owner))
        ->toThrow(RuntimeException::class, 'ceiling');

    expect(PrintImageAsset::count())->toBe(0)->and(Storage::allFiles())->toBe([]);
});

it('phạm vi tách biệt nhau — brand và shop đánh số phiên bản riêng', function () {
    $a = $this->store->store(pngOfSize(100, 50, 0), 'brand_logo', PrintTemplateScope::Brand, $this->owner);
    $b = $this->store->store(pngOfSize(100, 50, 0), 'brand_logo', PrintTemplateScope::Shop, $this->owner);

    // Cùng byte, khác scope ⇒ KHÔNG được coi là cùng một bản.
    expect($a->id)->not->toBe($b->id)
        ->and($a->version)->toBe(1)
        ->and($b->version)->toBe(1);
});

// Bất biến "allow-list ảnh ⊆ allow-list chung" sống ở
// `SourceAllowListShapeTest` cùng các luật hình dạng khác của danh sách (#1957
// mảnh D). Canh nó ở hai chỗ là hai chỗ phải sửa khi nó đổi, và chỗ thứ hai sẽ
// là chỗ bị quên.

it('tệp mồ côi TỰ LÀNH — đường dẫn theo nội dung, lượt sau ghi đúng chỗ cũ', function () {
    // `Storage::put` nằm TRONG transaction, mà transaction của DB không hoàn tác
    // hệ tệp. Nên một lượt insert hỏng để lại tệp trên đĩa.
    //
    // Điều đó KHÔNG thành rác vì đường dẫn là `{brand}/{source}-{hash}`: lượt
    // thử lại ghi đúng cùng đường dẫn, cùng byte. Ghi lại vì tính chất này là
    // thứ khiến "không dọn dẹp" trở thành lựa chọn đúng chứ không phải cẩu thả.
    $bytes = pngOfSize(150, 80);
    $asset = $this->store->store($bytes, 'brand_logo', PrintTemplateScope::Brand, $this->owner);

    $files = Storage::allFiles();
    expect($files)->toHaveCount(1);

    // Xoá HÀNG nhưng giữ tệp — mô phỏng đúng trạng thái sau một insert hỏng.
    PrintImageRaster::query()->delete();
    PrintImageAsset::query()->delete();

    $this->store->store($bytes, 'brand_logo', PrintTemplateScope::Brand, $this->owner);

    expect(Storage::allFiles())->toBe($files, 'lượt thử lại ghi ra một tệp thứ hai');
});

it('bề rộng ≤ 0 bị từ chối, không lặng lẽ rơi về mặc định', function () {
    $asset = $this->store->store(pngOfSize(120, 60), 'brand_logo', PrintTemplateScope::Brand, $this->owner);

    // Rơi về một mặc định sẽ in ra logo sai kích thước mà không báo gì — tệ hơn
    // là hỏng, vì nó ra giấy và không ai biết vì sao trông lệch.
    expect(fn () => $this->store->rasterFor($asset, 0))
        ->toThrow(InvalidArgumentException::class, 'positive');
    expect(fn () => $this->store->rasterFor($asset, -576))
        ->toThrow(InvalidArgumentException::class, 'positive');
});

it('upload RỖNG bị từ chối ngay, không tạo hàng nào', function () {
    // Tệp 0 byte là chuyện có thật (upload đứt, đĩa đầy phía client). Bắt ở đây
    // thay vì để GD trả về "không giải mã được" — thông báo rõ hơn cho người
    // đang đứng ở màn tải lên.
    expect(fn () => $this->store->store('', 'brand_logo', PrintTemplateScope::Brand, $this->owner))
        ->toThrow(InvalidArgumentException::class, 'empty upload');

    expect(PrintImageAsset::count())->toBe(0)->and(Storage::allFiles())->toBe([]);
});
