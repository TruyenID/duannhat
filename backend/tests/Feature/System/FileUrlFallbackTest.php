<?php

use App\Models\File;

it('returns no URL instead of crashing when imported S3 metadata has no configured storage', function () {
    config([
        'filesystems.disks.s3.region' => null,
        'filesystems.disks.s3.bucket' => null,
    ]);

    $file = new File([
        'disk' => 's3',
        'path' => 'gallery-fixtures/pho-bo.jpg',
    ]);

    expect($file->getUrl())->toBeNull();
});

/*
 * #2047 — the `local` driver returns a host-less `/storage/...` path. Served to
 * customer-web it resolves against `menu.vietorigin.jp` instead of the backend
 * and 404s, while rows stored under an absolute-URL disk load fine — so the
 * breakage looks random in production and never reproduces locally.
 */
it('returns an absolute URL for a local-disk file so it resolves off the backend, not the page origin', function () {
    config(['app.url' => 'https://tempo.godx.jp']);

    $file = new File([
        'disk' => 'local',
        'path' => 'uploads/temp/019fcffa/G1r211bB.jpg',
    ]);

    expect($file->getUrl())->toBe('https://tempo.godx.jp/storage/uploads/temp/019fcffa/G1r211bB.jpg');
});

it('does not double up the slash when app.url carries a trailing one', function () {
    config(['app.url' => 'https://tempo.godx.jp/']);

    $file = new File([
        'disk' => 'local',
        'path' => 'uploads/temp/019fcffa/G1r211bB.jpg',
    ]);

    expect($file->getUrl())->toBe('https://tempo.godx.jp/storage/uploads/temp/019fcffa/G1r211bB.jpg');
});

it('leaves an already-absolute disk URL untouched', function () {
    config([
        'filesystems.disks.s3.region' => 'ap-northeast-1',
        'filesystems.disks.s3.bucket' => 'tempo',
        'filesystems.disks.s3.url' => 'https://cdn.example.jp/tempo',
        'app.url' => 'https://tempo.godx.jp',
    ]);

    $file = new File([
        'disk' => 's3',
        'path' => 'uploads/temp/019fcffa/G1r211bB.jpg',
    ]);

    expect($file->getUrl())->toBe('https://cdn.example.jp/tempo/uploads/temp/019fcffa/G1r211bB.jpg');
});
