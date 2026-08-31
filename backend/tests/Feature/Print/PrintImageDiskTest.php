<?php

declare(strict_types=1);

use App\Models\PrintImageRaster;
use App\Services\Print\Enums\PrintTemplateScope;
use App\Services\Print\PrintImageStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * #2136 — ảnh in phải NÊU TÊN disk, và lệch disk phải KÊU.
 *
 * Trước bản này `PrintImageStore` gọi `Storage::put()`/`Storage::get()` trần,
 * tức bám `filesystems.default`. Vì `print_image_assets` không có cột `disk`,
 * nơi byte thực sự nằm chỉ là giá trị biến môi trường tại thời điểm ghi.
 *
 * Cái làm nó nguy hiểm không phải bản thân sự lệch, mà là {@see PrintImageStore::rasterFor()}
 * **cố ý im lặng** khi không đọc được byte gốc (TR-05: thiếu ảnh thì phiếu vẫn
 * phải in, quán mất logo không được ngừng bán hàng). Nên một lần dọn `.env` sẽ
 * làm logo lặng lẽ biến khỏi mọi phiếu in, không ngoại lệ, không log — đúng kiểu
 * câm đã tốn 2 ngày ở #2101.
 *
 * Ba bài dưới đây tách bạch hai thứ TỪNG bị gộp làm một:
 *
 *  - **bị xoá thật** → im lặng, trả `null`. TR-05 giữ nguyên.
 *  - **nằm ở disk khác** → vẫn đọc được (không mất dữ liệu) NHƯNG ghi cảnh báo.
 */
beforeEach(function () {
    // Dựng đúng hình dạng của PROD hôm nay: `FILESYSTEM_DISK=public` (cách chữa
    // cháy cho #2101), còn ảnh in thì phải nằm ở disk PRIVATE. Hai disk bắt buộc
    // khác nhau, nếu không mọi bài ở đây đo một cây cầu không bắc qua sông nào.
    config()->set('filesystems.default', 'public');
    config()->set('filesystems.print_images', 'local');

    Storage::fake('local');
    Storage::fake('public');

    $this->store = app(PrintImageStore::class);
    $this->owner = ['organization_id' => null, 'brand_id' => null, 'branch_id' => null];
});

/** PNG đặc để GD giải mã được. */
function diskTestPng(int $w = 120, int $h = 60): string
{
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, (int) imagecolorallocate($img, 10, 10, 10));
    ob_start();
    imagepng($img);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

it('GHI vào disk PRIVATE đã cấu hình, KHÔNG vào disk mặc định của framework', function () {
    // Byte gốc không bao giờ đến tay client (máy trạm nhận bitmap base64 trong
    // `print_image_rasters.data`), nên đích đúng là disk private. Bài này ghim
    // luôn cả điều đó: một bản "sửa" đẩy ảnh in sang disk công khai cho giống
    // đích upload sẽ làm nó đỏ.
    $asset = $this->store->store(diskTestPng(), 'brand_logo', PrintTemplateScope::Brand, $this->owner);

    Storage::disk('local')->assertExists($asset->original_path);
    Storage::disk('public')->assertMissing($asset->original_path);
});

it('KHÔNG dò ngược disk cũ — byte chỉ ở đó thì coi như không có (#2598)', function () {
    // Bài này TỪNG khẳng định ngược lại: đọc cho được byte trên disk cũ rồi ghi
    // cảnh báo. Đó là hợp đồng đúng của #2136 khi còn dữ liệu ghi trước nó.
    //
    // Điều kiện gỡ do chính #2598 đặt ra là MỘT phép đo trên production, và nó
    // đã chạy 2026-08-12: `print_image_assets: 0` — không asset nào, chưa bao
    // giờ; `print_templates: 0` — cả tính năng chưa từng được dùng. Không hàng
    // DB nào ⇒ không đường đọc nào với tới disk cũ, và ảnh upload từ nay vào
    // thẳng disk canonical nên không sinh thêm ca đó.
    //
    // Nay hành vi là: đích rỗng ⇒ `null`, và TR-05 xử tiếp (phiếu vẫn in).
    // KHÔNG mò sang disk khác — một đường đọc âm thầm sang chỗ khác chính là
    // thứ #2101 đã trả giá.
    $bytes = diskTestPng();
    $asset = $this->store->store($bytes, 'brand_logo', PrintTemplateScope::Brand, $this->owner);

    // Đúng hình dạng dữ liệu tiền-#2136: hàng có trong DB, byte nằm trên disk
    // mặc định cũ, đích cấu hình rỗng.
    Storage::disk('public')->put($asset->original_path, $bytes);
    Storage::disk('local')->delete($asset->original_path);
    PrintImageRaster::where('asset_id', $asset->id)->delete();

    expect($this->store->rasterFor($asset->fresh(), 384))->toBeNull(
        'vẫn dò được sang disk cũ — fallback đã bị gỡ ở #2598, đừng dựng lại',
    );

    // Và byte vẫn nằm nguyên trên disk cũ: gỡ fallback là thôi ĐỌC, không phải xoá.
    Storage::disk('public')->assertExists($asset->original_path);
});

it('ảnh bị XOÁ THẬT vẫn im lặng trả null — TR-05 không bị bài trên làm hỏng', function () {
    // Phân biệt quan trọng: bài trên bắt lệch cấu hình phải kêu. Nếu nó được cài
    // bằng cách "cứ thiếu byte là kêu" thì mọi quán từng xoá logo sẽ ngập cảnh
    // báo, và cảnh báo ngập là cảnh báo không ai đọc.
    $asset = $this->store->store(diskTestPng(), 'brand_logo', PrintTemplateScope::Brand, $this->owner);

    Storage::disk('public')->delete($asset->original_path);
    Storage::disk('local')->delete($asset->original_path);
    PrintImageRaster::where('asset_id', $asset->id)->delete();

    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e;
    });

    expect($this->store->rasterFor($asset->fresh(), 384))->toBeNull();

    $warnings = array_filter($logged, fn ($m) => $m->level === 'warning');
    expect($warnings)->toBeEmpty('ảnh xoá thật mà vẫn kêu — cảnh báo sẽ ngập và mất tác dụng');
});

// #2514 — bài test của lệnh `print-images:migrate-disk` đã đi cùng lệnh (xoá ở
// #2507/#2512 kèm bằng chứng prod: print_image_assets = 0, không còn đối tượng).
// Ba bài trên test hành vi disk ĐANG SỐNG nên ở lại.
