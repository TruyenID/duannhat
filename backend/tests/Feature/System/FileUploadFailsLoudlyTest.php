<?php

declare(strict_types=1);

use App\Models\File;
use App\Models\User;
use App\Omnify\Enums\FileStatusEnum;
use App\Services\FileUploadService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * #2149 — `FileUploadService` phải KÊU khi lưu trữ hỏng.
 *
 * Cả ba disk khai `'throw' => false, 'report' => false`, nên thao tác hỏng
 * **không ném và không ghi log**. Ba chỗ trong file này từng vứt kết quả đi:
 *
 *  - `uploadTemp()` — `store()` trả `false`, giá trị đó rơi thẳng vào
 *    `File::create(['path' => …])`. `files.path` là NOT NULL nên `false` bind
 *    thành `''` và INSERT **thành công**: một hàng trỏ vào hư không, sống lâu
 *    hơn cả request hỏng. Đây là phễu upload dùng chung của gần như mọi ảnh.
 *  - `attachToModel()` — số hàng khớp bị vứt; 0 hàng không phải exception nên
 *    transaction bao ngoài commit bình thường.
 *  - `delete()` — kết quả xoá bị vứt; hàng DB biến mất, byte ở lại VĨNH VIỄN.
 *
 * Cùng họ với #2101 / #2136 / #2146. Điểm chung không phải "chọn sai disk" mà là
 * **một thao tác hỏng mà không ai nghe thấy**.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);
});

/**
 * Disk giả hỏng ở một thao tác cụ thể.
 *
 * Mock contract thay vì anonymous class implement cả `Filesystem`: interface đó
 * có hơn hai chục method và một stub lệch chữ ký sẽ làm bài test đỏ vì lý do
 * KHÔNG liên quan tới thứ đang đo.
 */
function stubDiskFailing(string $method): void
{
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive($method)->andReturn(false);
    $disk->shouldReceive('putFileAs')->andReturn(false);
    $disk->shouldReceive('url')->andReturn('https://never-reached.invalid/x');
    $disk->shouldReceive('delete')->andReturn($method === 'delete' ? false : true);
    $disk->shouldReceive('move')->andReturn($method === 'move' ? false : true);

    Storage::shouldReceive('disk')->andReturn($disk);
}

function loggedMessages(): array
{
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e;
    });

    return $logged;
}

it('uploadTemp NÉM khi ghi hỏng — không được tạo hàng files nào', function () {
    Storage::fake('local');
    // `store()` đi qua `putFileAs`; trả `false` là đúng hành vi disk hỏng.
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('putFileAs')->andReturn(false);
    Storage::shouldReceive('disk')->andReturn($disk);

    $before = File::count();

    expect(fn () => app(FileUploadService::class)->uploadTemp(
        UploadedFile::fake()->image('x.png'),
        $this->orgId,
        'default',
        'local',
    ))->toThrow(RuntimeException::class);

    expect(File::count())->toBe(
        $before,
        'ghi hỏng mà vẫn tạo hàng files — hàng đó trỏ vào hư không và sống mãi',
    );
});

it('uploadTemp GHI LOG khi ghi hỏng — im lặng chính là lỗi', function () {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('putFileAs')->andReturn(false);
    Storage::shouldReceive('disk')->andReturn($disk);

    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e;
    });

    try {
        app(FileUploadService::class)->uploadTemp(
            UploadedFile::fake()->image('x.png'),
            $this->orgId,
            'default',
            'local',
        );
    } catch (RuntimeException) {
        // mong đợi
    }

    $errors = array_filter(
        $logged,
        fn (MessageLogged $e) => $e->level === 'error' && str_contains($e->message, 'file upload'),
    );

    expect($errors)->not->toBeEmpty('ghi hỏng mà log trống — đúng chế độ câm của #2101');
});

it('uploadTemp THÀNH CÔNG vẫn tạo hàng bình thường — không siết nhầm', function () {
    // Mặt kia của bánh cóc: một guard quá tay chặn cả lần ghi tốt, và triệu
    // chứng lúc đó (không upload được gì) khó truy hơn hẳn triệu chứng cũ.
    Storage::fake('local');

    $file = app(FileUploadService::class)->uploadTemp(
        UploadedFile::fake()->image('x.png'),
        $this->orgId,
        'default',
        'local',
    );

    expect($file->path)->not->toBe('')
        ->and($file->status)->toBe(FileStatusEnum::Temporary);
});

it('attachToModel TRẢ VỀ số gắn được, và CẢNH BÁO khi thiếu', function () {
    Storage::fake('local');

    $model = User::factory()->create(['console_organization_id' => $this->orgId]);

    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e;
    });

    // Ba id không tồn tại: đúng trạng thái "id hết hạn / sai tổ chức" mà đường
    // đánh giá không hề chặn (request chỉ validate `uuid`).
    $attached = app(FileUploadService::class)->attachToModel(
        $model,
        [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()],
        'review',
    );

    expect($attached)->toBe(0, 'phải trả về số hàng thật, không phải void');

    $warnings = array_filter(
        $logged,
        fn (MessageLogged $e) => $e->level === 'warning' && str_contains($e->message, 'file attach'),
    );

    expect($warnings)->not->toBeEmpty(
        'yêu cầu 3 file, gắn được 0, mà không kêu — bản ghi cha vẫn trả 201 và mất sạch ảnh',
    );
});

it('delete GIỮ hàng DB khi xoá byte hỏng — mồ côi vĩnh viễn còn tệ hơn', function () {
    Storage::fake('local');

    $file = File::factory()->create([
        'organization_id' => $this->orgId,
        'disk' => 'local',
        'path' => 'uploads/temp/x.png',
    ]);

    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('delete')->andReturn(false);
    Storage::shouldReceive('disk')->andReturn($disk);

    expect(fn () => app(FileUploadService::class)->delete($file))
        ->toThrow(RuntimeException::class);

    expect(File::whereKey($file->id)->exists())->toBeTrue(
        'byte chưa xoá được mà hàng DB đã mất — không còn bản ghi nào để lần dọn sau tìm ra',
    );
});
