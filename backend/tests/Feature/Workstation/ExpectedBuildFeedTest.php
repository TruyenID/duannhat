<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Organization;
use App\Services\Workstation\SyncManifestService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/*
 * #1806 S4 — feed "bản phát hành mong đợi".
 *
 * Bất biến quan trọng nhất là FAIL-SAFE: không cấu hình gì thì feed vẫn trả 200
 * với `version: null`, và máy trạm im lặng. Một quán mất đường tới HQ vẫn phải
 * bán được hàng — biến "không biết mình có cũ không" thành "không bán được" là
 * tự tạo sự cố tệ hơn thứ đang cảnh báo.
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->wsToken = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $branch->id,
    ]);

    $this->fetch = fn () => $this
        ->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/expected-build');
});

/**
 * Stage a workstation download manifest in a temp file and point the catalog at
 * it.
 *
 * #2428 — this used to overwrite `public/downloads/workstation/manifest.json`,
 * a TRACKED file, and restore it in `finally`. A run killed mid-test (Ctrl-C,
 * OOM, PHP fatal) left a fake manifest in the working tree — and several agent
 * sessions share this repo, so the next person saw a change nobody made.
 *
 * @param  array<string, mixed>  $manifest
 */
function withWorkstationManifest(array $manifest, callable $callback): void
{
    $path = tempnam(sys_get_temp_dir(), 'ws-manifest-').'.json';
    File::put($path, json_encode($manifest, JSON_THROW_ON_ERROR));
    config()->set('workstation.downloads.manifest_path', $path);

    try {
        $callback();
    } finally {
        File::delete($path);
    }
}

it('#1806 S4 KHÔNG cấu hình gì thì im lặng, không lỗi', function () {
    config()->set('workstation.expected_build', ['version' => null]);

    $res = ($this->fetch)()->assertOk();

    expect($res->json('data.version'))->toBeNull()
        // severity/reason cũng phải null: một mức nghiêm trọng không gắn với
        // phiên bản nào là dữ liệu rác mà phía Go sẽ phải tự đoán cách xử lý.
        ->and($res->json('data.severity'))->toBeNull()
        ->and($res->json('data.reason'))->toBeNull()
        ->and($res->json('data.package'))->toBeNull();
});

it('#1806 S4 chuỗi rỗng cũng là KHÔNG cấu hình', function () {
    // `.env` để trống cho ra chuỗi rỗng chứ không phải null. Không xử lý thì
    // máy trạm so sánh phiên bản của nó với "" và kêu vĩnh viễn.
    config()->set('workstation.expected_build', ['version' => '  ', 'severity' => 'critical']);

    $res = ($this->fetch)()->assertOk();

    expect($res->json('data.version'))->toBeNull()
        ->and($res->json('data.package'))->toBeNull();
});

it('#1806 S4 trả version + severity + lý do', function () {
    config()->set('workstation.expected_build', [
        'version' => 'v0.3.0',
        'severity' => 'critical',
        'reason' => 'vá lỗi làm lệch tiền thối',
    ]);

    $res = ($this->fetch)()->assertOk();

    expect($res->json('data.version'))->toBe('v0.3.0')
        ->and($res->json('data.severity'))->toBe('critical')
        ->and($res->json('data.reason'))->toBe('vá lỗi làm lệch tiền thối');
});

it('#1806 S4 severity lạ hạ về info, KHÔNG ném lỗi', function () {
    // Một lỗi gõ trong cấu hình không được phép làm câm cả feed — nhưng cũng
    // không được tự nâng thành `critical` và dạy người vận hành bỏ qua màu đỏ.
    config()->set('workstation.expected_build', [
        'version' => 'v0.3.0',
        'severity' => 'CATASTROPHIC',
    ]);

    expect(($this->fetch)()->assertOk()->json('data.severity'))->toBe('info');
});

it('#1806 S4 không có token thì không vào được', function () {
    $this->getJson('/api/v1/workstation/expected-build')->assertStatus(401);
});

it('#2635 auto_apply mặc định false — severity critical KHÔNG thay được cờ', function () {
    // "Bắt buộc" là cờ RIÊNG, không phải một mức severity. Nếu ai đó gộp hai
    // trục này, mọi bản `critical` sẽ thành một lần khởi động lại lúc 2h sáng —
    // rộng hơn nhiều điều được chốt. Bản critical KHÔNG bật cờ phải trả false.
    config()->set('workstation.expected_build', [
        'version' => 'v0.3.0',
        'severity' => 'critical',
        'reason' => 'vá lỗi tiền',
    ]);

    $res = ($this->fetch)()->assertOk();

    expect($res->json('data.severity'))->toBe('critical')
        ->and($res->json('data.auto_apply'))->toBeFalse();
});

it('#2635 auto_apply bật đích danh thì feed mang true', function () {
    config()->set('workstation.expected_build', [
        'version' => 'v0.3.0',
        'severity' => 'info',
        'auto_apply' => true,
    ]);

    expect(($this->fetch)()->assertOk()->json('data.auto_apply'))->toBeTrue();
});

it('#2635 auto_apply từ .env là chuỗi — "true"/"1" bật, giá trị lạ hạ về false', function () {
    // env() cho ra chuỗi; một lỗi gõ trong cấu hình phải rơi về chiều an toàn
    // (KHÔNG tự cài), không được thành một lần khởi động lại không ai duyệt.
    foreach (['true' => true, '1' => true, 'false' => false, '' => false, 'yes-please' => false] as $raw => $want) {
        config()->set('workstation.expected_build', [
            'version' => 'v0.3.0',
            'auto_apply' => (string) $raw,
        ]);

        expect(($this->fetch)()->assertOk()->json('data.auto_apply'))
            ->toBe($want, "auto_apply='{$raw}' phải ra ".var_export($want, true));
    }
});

it('#2635 không có version thì auto_apply là false, kể cả khi cờ bật', function () {
    // Không có bản nào để cài thì không có gì được phép tự cài — một cờ true
    // mồ côi là dữ liệu rác mà phía Go sẽ phải tự đoán cách xử lý.
    config()->set('workstation.expected_build', [
        'version' => null,
        'auto_apply' => true,
    ]);

    expect(($this->fetch)()->assertOk()->json('data.auto_apply'))->toBeFalse();
});

it('attaches package platforms with absolute urls when version is in the download catalog', function () {
    $sha = str_repeat('a', 64);

    withWorkstationManifest([
        'latest' => 'v0.3.0',
        'updated_at' => '2026-08-10T03:00:00Z',
        'versions' => [
            [
                'version' => 'v0.3.0',
                'released_at' => '2026-08-10T03:00:00Z',
                'commit' => 'abc123def456',
                'archived' => false,
                'platforms' => [
                    [
                        'id' => 'linux-amd64',
                        'filename' => 'ws-server-linux-amd64',
                        'size' => 12345678,
                        'sha256' => $sha,
                    ],
                    [
                        'id' => 'darwin-arm64',
                        'filename' => 'ws-server-darwin-arm64',
                        'size' => 99,
                        'sha256' => str_repeat('c', 64),
                    ],
                ],
            ],
        ],
    ], function () use ($sha) {
        config()->set('workstation.expected_build', [
            'version' => 'v0.3.0',
            'severity' => 'warning',
            'reason' => 'bản HQ mong đợi',
        ]);

        $res = ($this->fetch)()->assertOk();

        expect($res->json('data.package.version'))->toBe('v0.3.0')
            ->and($res->json('data.package.platforms'))->toHaveCount(2)
            ->and($res->json('data.package.platforms.0'))->toMatchArray([
                'id' => 'linux-amd64',
                'url' => url('/api/v1/workstation/downloads/v0.3.0/ws-server-linux-amd64'),
                'sha256' => $sha,
                'size' => 12345678,
            ])
            ->and($res->json('data.package.platforms.1.id'))->toBe('darwin-arm64')
            ->and($res->json('data.package.platforms.1.url'))
            ->toBe(url('/api/v1/workstation/downloads/v0.3.0/ws-server-darwin-arm64'));
    });
});

it('returns package null when expected version is missing from the download catalog', function () {
    withWorkstationManifest([
        'latest' => 'v0.3.0',
        'updated_at' => '2026-08-10T03:00:00Z',
        'versions' => [
            [
                'version' => 'v0.3.0',
                'released_at' => '2026-08-10T03:00:00Z',
                'commit' => 'abc123def456',
                'archived' => false,
                'platforms' => [
                    [
                        'id' => 'linux-amd64',
                        'filename' => 'ws-server-linux-amd64',
                        'size' => 1,
                        'sha256' => str_repeat('a', 64),
                    ],
                ],
            ],
        ],
    ], function () {
        config()->set('workstation.expected_build', [
            'version' => 'v9.9.9',
            'severity' => 'info',
        ]);

        $res = ($this->fetch)()->assertOk();

        // Version warning vẫn có — chỉ thiếu artifact tải assisted.
        expect($res->json('data.version'))->toBe('v9.9.9')
            ->and($res->json('data.package'))->toBeNull();
    });
});

it('resolves package from archived entries with the REAL file path — archive never moves files', function () {
    $sha = str_repeat('b', 64);

    withWorkstationManifest([
        'latest' => 'v0.3.0',
        'updated_at' => '2026-08-10T03:00:00Z',
        'versions' => [
            [
                'version' => 'v0.2.0',
                'released_at' => '2026-07-01T00:00:00Z',
                'commit' => 'deadbeef0000',
                'archived' => true,
                'platforms' => [
                    [
                        'id' => 'linux-amd64',
                        'filename' => 'ws-server-linux-amd64',
                        'size' => 100,
                        'sha256' => $sha,
                    ],
                ],
            ],
        ],
    ], function () use ($sha) {
        config()->set('workstation.expected_build', [
            'version' => 'v0.2.0',
            'severity' => 'info',
        ]);

        $res = ($this->fetch)()->assertOk();

        expect($res->json('data.package'))->toMatchArray([
            'version' => 'v0.2.0',
            'platforms' => [
                [
                    'id' => 'linux-amd64',
                    // Gắn cờ archived chỉ đổi manifest; binary vẫn nằm nguyên chỗ
                    // cũ, và URL tải đi qua /api/* — con đường duy nhất chắc chắn
                    // tới Laravel trên host hai CDN (#2453). Hai đời URL trước
                    // (/archive/, rồi file tĩnh trên APP_URL) đều 404 trên
                    // production — Tsukiji 2026-08-18, hai lần trong một tối.
                    'url' => url('/api/v1/workstation/downloads/v0.2.0/ws-server-linux-amd64'),
                    'sha256' => $sha,
                    'size' => 100,
                ],
            ],
        ]);
    });
});

it('builds assisted-download urls through /api — the only path that reaches Laravel', function () {
    $sha = str_repeat('c', 64);

    withWorkstationManifest([
        'latest' => 'v0.9.0',
        'updated_at' => '2026-08-18T12:00:00Z',
        'versions' => [
            [
                'version' => 'v0.9.0',
                'released_at' => '2026-08-18T11:00:00Z',
                'commit' => 'cafebabe0000',
                'archived' => false,
                'platforms' => [
                    ['id' => 'linux-amd64', 'filename' => 'ws-server-linux-amd64', 'size' => 7, 'sha256' => $sha],
                ],
            ],
        ],
    ], function () {
        config()->set('workstation.expected_build', ['version' => 'v0.9.0', 'severity' => 'info']);

        $res = ($this->fetch)()->assertOk();

        // APP_URL production (tempo.godx.jp) route / qua CloudFront — file tĩnh
        // 404 (#2453, đo 2026-08-18). /api/* là đường duy nhất chắc chắn tới
        // Laravel, nên URL tải phải nằm trong đó.
        expect($res->json('data.package.platforms.0.url'))
            ->toBe(url('/api/v1/workstation/downloads/v0.9.0/ws-server-linux-amd64'));
    });
});

it('moves the sync-manifest expected_build version when the PACKAGE changes — config untouched', function () {
    $manifestFor = fn (string $sha) => [
        'latest' => 'v0.9.0',
        'updated_at' => '2026-08-18T12:00:00Z',
        'versions' => [
            [
                'version' => 'v0.9.0',
                'released_at' => '2026-08-18T11:00:00Z',
                'commit' => 'cafebabe0000',
                'archived' => false,
                'platforms' => [
                    ['id' => 'linux-amd64', 'filename' => 'ws-server-linux-amd64', 'size' => 7, 'sha256' => $sha],
                ],
            ],
        ],
    ];

    config()->set('workstation.expected_build', ['version' => 'v0.9.0', 'severity' => 'info']);

    $manifest = fn () => $this
        ->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/sync-manifest');

    $before = null;
    withWorkstationManifest($manifestFor(str_repeat('a', 64)), function () use ($manifest, &$before) {
        $before = $manifest()->assertOk()->json('data.feeds.expected_build');
    });

    // Đổi GÓI (sha khác — cùng lớp với đổi URL scheme), config đứng yên. Trước
    // fix, hash chỉ nhìn config nên version không nhích: máy trạm 304 mãi và
    // giữ URL tải chết trong cache — đo 2026-08-18, ba đời URL một tối, phải
    // xoá cursor bằng tay từng máy.
    $this->travel(SyncManifestService::TTL_SECONDS + 1)->seconds();
    withWorkstationManifest($manifestFor(str_repeat('b', 64)), function () use ($manifest, $before) {
        expect($manifest()->assertOk()->json('data.feeds.expected_build'))->not->toBe($before);
    });
});
