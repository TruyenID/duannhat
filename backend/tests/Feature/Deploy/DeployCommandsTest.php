<?php

/**
 * #2463 — the deploy steps that used to be PHP inside `deploy-xserver.yml`.
 *
 * They ran as `artisan tinker --execute` on the production database on every
 * push to `main`: not lintable, not reviewable as code, and — the point of this
 * file — not testable. Two real service incidents on 2026-08-11 came off that
 * path. These tests are what the move bought.
 */

use App\Models\Branch;
use App\Models\Brand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses()->group('deploy');

/*
|--------------------------------------------------------------------------
| deploy:reconcile-omnify-migrations
|--------------------------------------------------------------------------
*/

it('records a create-table migration whose table already exists', function () {
    // The situation it exists for, reproduced: omnify renumbers its files, so
    // production holds the TABLE while `migrations` still names the old file.
    // `migrate --force` would then try to create it again and abort the deploy.
    //
    // Forget one real omnify create-table row — the table itself stays — and
    // the command must put it back.
    $forgotten = collect(glob(database_path('migrations/omnify/*.php')) ?: [])
        ->map(fn (string $file) => basename($file, '.php'))
        ->first(fn (string $name) => preg_match('/create_(.+)_table/', $name, $m)
            && Schema::hasTable($m[1])
            && DB::table('migrations')->where('migration', $name)->exists());

    expect($forgotten)->not->toBeNull('no recorded omnify create-table migration to exercise');

    DB::table('migrations')->where('migration', $forgotten)->delete();

    $this->artisan('deploy:reconcile-omnify-migrations')
        ->expectsOutputToContain('Reconciled: '.$forgotten)
        ->assertSuccessful();

    expect(DB::table('migrations')->where('migration', $forgotten)->exists())->toBeTrue();
});

it('never records a migration whose table is absent', function () {
    // The dangerous direction. Recording a migration that has NOT run means
    // the schema change is skipped forever, silently.
    $before = DB::table('migrations')->count();

    // Every omnify create-table migration in a freshly-migrated test database
    // is already recorded, so a correct run reconciles nothing at all.
    $this->artisan('deploy:reconcile-omnify-migrations')->assertSuccessful();

    expect(DB::table('migrations')->count())->toBe($before);
});

it('writes nothing on a dry run', function () {
    $before = DB::table('migrations')->get()->toArray();

    $this->artisan('deploy:reconcile-omnify-migrations', ['--dry-run' => true])->assertSuccessful();

    expect(DB::table('migrations')->get()->toArray())->toEqual($before);
});

/*
|--------------------------------------------------------------------------
| deploy:verify-uploads-disk (#2184)
|--------------------------------------------------------------------------
*/

it('passes when filesystems.uploads names a configured disk', function () {
    config(['filesystems.uploads' => 'local']);

    $this->artisan('deploy:verify-uploads-disk')->assertSuccessful();
});

it('fails when UPLOADS_DISK is present but blank', function () {
    // `UPLOADS_DISK=` with no value. `Storage::disk("")` throws, so every
    // upload 500s — and nothing else in the deploy notices.
    config(['filesystems.uploads' => '']);

    $this->artisan('deploy:verify-uploads-disk');
})->throws(RuntimeException::class, 'filesystems.uploads is EMPTY');

it('fails when filesystems.uploads names a disk that does not exist', function () {
    config(['filesystems.uploads' => 'nope']);

    $this->artisan('deploy:verify-uploads-disk');
})->throws(RuntimeException::class, 'does not exist in filesystems.disks');

/*
|--------------------------------------------------------------------------
| deploy:export-authz-manifest
|--------------------------------------------------------------------------
*/

it('exports a manifest Platform can require', function () {
    $path = tempnam(sys_get_temp_dir(), 'authz').'.php';

    $this->artisan('deploy:export-authz-manifest', ['path' => $path])->assertSuccessful();

    $exported = require $path;

    expect($exported)->toBeArray()
        ->and($exported['permissions'] ?? [])->not->toBeEmpty()
        // Byte-for-byte the same catalog, not a re-derivation.
        ->and($exported)->toEqual(require config_path('authz.php'));

    File::delete($path);
});

it('refuses to export an empty permission catalog', function () {
    // Syncing an empty manifest is NOT a no-op on the Platform side: it
    // publishes "Tempo has no permissions" and the next login resolves every
    // user down to nothing. The inline version checked this AFTER the sync had
    // already run — one step too late to help.
    $emptyConfig = tempnam(sys_get_temp_dir(), 'authz').'.php';
    File::put($emptyConfig, '<?php return ["permissions" => []];');

    $target = tempnam(sys_get_temp_dir(), 'out').'.php';
    File::delete($target);

    try {
        app()->useConfigPath(dirname($emptyConfig));
        File::move($emptyConfig, dirname($emptyConfig).'/authz.php');

        $this->artisan('deploy:export-authz-manifest', ['path' => $target]);
    } finally {
        File::delete(dirname($emptyConfig).'/authz.php');
    }

    expect(File::exists($target))->toBeFalse();
})->throws(RuntimeException::class, 'permission catalog is EMPTY');

/*
|--------------------------------------------------------------------------
| deploy:verify-production-seed
|--------------------------------------------------------------------------
*/

it('fails on an empty database rather than reporting a healthy seed', function () {
    // The whole job of this gate. A seed that "succeeded" while leaving the
    // catalog empty must not reach a shop as a menu with nothing on it.
    $this->artisan('deploy:verify-production-seed');
})->throws(RuntimeException::class);

it('đường DEPLOY không đếm catalog — chỉ `--after-restore` mới đếm (#2574)', function () {
    // Điểm mấu chốt của #2574, và là bài duy nhất phân biệt được hai chế độ.
    //
    // Dựng đúng hình dạng của production ở một lượt deploy thường: brand có,
    // catalog thì KHÔNG do lượt này dựng (ở đây là rỗng, vì `CatalogSnapshotSeeder`
    // không chạy khi catalog đã có sẵn — và test không có sẵn gì cả).
    Brand::factory()->create(['slug' => 'betoya']);

    // Trần: PHẢI QUA. Lượt deploy không restore gì thì không có gì để đếm, và
    // ba lượt deploy production hỏng ngày 2026-08-12 đều chết ở đúng chỗ này.
    $this->artisan('deploy:verify-production-seed')->assertSuccessful();

    // Cùng DB đó với cờ: PHẢI ĐỎ. Nếu không thì cờ chỉ là đồ trang trí và bốn
    // sàn đã chết im lặng thay vì được dời chỗ.
    expect(fn () => $this->artisan('deploy:verify-production-seed', ['--after-restore' => true]))
        ->toThrow(RuntimeException::class);
});

it('coi số chi nhánh là SÀN, không phải đẳng thức (#2561)', function (int $branches, bool $passesBranchGate) {
    // Mở/đóng một quán là thao tác hợp lệ ở Platform, và Tempo soi gương ĐÚNG
    // khi con số đổi theo. Đẳng thức `=== 17` biến việc đó thành deploy đỏ —
    // đỏ NỬA CHỪNG, vì bước này chạy sau migrate/seed nên phần đồng bộ authz
    // sang Platform không chạy (#2542 đã trả giá đúng khuôn này với `zones`).
    //
    // Bài này chỉ hỏi MỘT câu: cổng chi nhánh có cho đi qua không. Các khẳng
    // định sau nó (products/files/menu) chắc chắn vẫn đỏ vì DB test không có
    // catalog — nên "qua được" được đo bằng việc thông điệp lỗi KHÔNG còn nói
    // về chi nhánh nữa. Rẻ hơn nhiều so với dựng cả bản snapshot chỉ để đổi
    // một con số.
    Brand::factory()->create(['slug' => 'betoya']);
    Branch::factory()->count($branches)->create();

    try {
        // `--after-restore` từ #2574: sàn đếm không còn chạy trên đường deploy.
        $this->artisan('deploy:verify-production-seed', ['--after-restore' => true]);
        $message = '';
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }

    expect(str_contains($message, 'Platform branches'))->toBe(! $passesBranchGate, $message);
})->with([
    'đúng bằng snapshot hôm nay' => [17, true],
    'mở thêm một quán' => [18, true],
    'đóng bớt một quán' => [16, true],
    'đóng gần hết — restore hỏng mới ra hình này' => [3, false],
]);

it('counts only LIVE rows, never soft-deleted ones', function () {
    // `DB::table()` returns soft-deleted rows too. Counting without the
    // `deleted_at` filter once turned "18 missing tables" into "0" during an
    // investigation, so the filter is pinned rather than trusted.
    $source = file_get_contents(app_path('Console/Commands/Deploy/VerifyProductionSeedCommand.php'));

    expect($source)->toContain("'menu_products'");

    // Hai bộ lọc live-row: count `menu_products`, cộng join tìm dòng menu còn
    // sống trỏ vào product đã xoá. Join viết cột có tiền tố (`mp.deleted_at`)
    // nên regex khớp cả hai lối viết — một assertion chỉ biết dạng trần sẽ đếm
    // thiếu và đọc như "mất bộ lọc" thay vì "viết khác đi".
    //
    // Trước #2542 con số này là 4: hai cái kia là sàn `zones`/`tables` của
    // Ningyocho, đã gỡ vì chúng ra điều kiện trên dữ liệu QUÁN SỞ HỮU (quán xoá
    // một zone ⇒ deploy đỏ vĩnh viễn). Hạ 4 → 2 ở đây là ghi nhận việc gỡ đó,
    // KHÔNG phải nới rào: bộ lọc soft-delete của các count còn lại vẫn bị ghim
    // nguyên, và đó là thứ bài test này sinh ra để canh.
    expect(preg_match_all("/whereNull\('(?:[a-z_]+\.)?deleted_at'\)/", $source))->toBe(2);
});

it('KHÔNG khẳng định gì trên dữ liệu quán sở hữu (#2542)', function () {
    // Rào chống việc dựng lại: zone/bàn/cài đặt đơn hàng là thứ quán tự sửa
    // trong admin-web. Một khẳng định trên chúng biến thao tác thường ngày của
    // quán thành deploy đỏ — và vì gate này chạy SAU migrate/seed, deploy đỏ ở
    // đây nghĩa là nửa trước đã ghi còn đồng bộ authz sang Platform thì không.
    //
    // Đọc phần MÃ, bỏ comment: removal record ở trên CÓ nhắc `zones`/`tables`
    // theo đúng thiết kế (#2188), và một rào tính cả comment sẽ đỏ vì chính
    // dòng chữ giải thích vì sao nó tồn tại — khuôn #1921/#2511 đã trả giá.
    $code = implode('', array_map(
        static fn (array|string $t): string => is_array($t)
            ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1])
            : $t,
        token_get_all(file_get_contents(app_path('Console/Commands/Deploy/VerifyProductionSeedCommand.php'))),
    ));

    // `str_contains` + `toBeFalse`, KHÔNG phải `->not->toContain($needle, $msg)`:
    // `toContain` của Pest nhận NHIỀU needle, nên tham số thứ hai trở thành
    // needle thứ hai và phủ định khi đó luôn đúng — bản đầu của bài này XANH
    // ngay cả khi tôi cố tình nhét `DB::table('zones')` trở lại (đã thử).
    // `UploadDiskIsPubliclyServableTest` đã trả giá đúng khuôn này ở U3.
    foreach (['zones', 'tables', 'shop_order_settings', 'menu_schedules'] as $shopOwned) {
        expect(str_contains($code, "'{$shopOwned}'"))->toBeFalse(
            "Gate deploy đang truy vấn `{$shopOwned}` — bảng QUÁN SỞ HỮU. Xem #2542: ".
            'không ghi đã đành, nhưng cũng không được ra điều kiện trên dữ liệu của quán, '.
            'vì thao tác thường ngày của họ sẽ thành deploy đỏ nửa chừng.',
        );
    }
});
