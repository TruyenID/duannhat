<?php

use Illuminate\Support\Str;

/*
 * #1508 — an `Api/V1/*` namespace that CARRIES OpenAPI attributes must be
 * scanned by some l5-swagger documentation, otherwise every one of those
 * attributes is a no-op.
 *
 * Why this exists: POS was absent from `config/l5-swagger.php` for its whole
 * life, so `storage/api-docs/*.json` held ZERO `/api/v1/pos/*` paths — while
 * `Api/V1/Pos/InvoiceController` had carried OA attributes since plan-038.
 * Nothing ever failed. The attributes were written, reviewed, merged, and
 * dropped on the floor. Two of the three did not even have `responses:`, which
 * the generator flat-out refuses — and that stayed hidden too, because the
 * generator never read the file.
 *
 * So the property under test is NOT "the docs are complete" (they are not, and
 * that is a separate matter). It is: **no namespace writes annotations into a
 * void**. A namespace with zero attributes is merely undocumented, which is a
 * coverage question, not a lie.
 */

/**
 * Namespaces that carry OA attributes today and are STILL not scanned.
 *
 * Every entry is a real defect of the same shape as the POS one — the count is
 * how many operations are currently dropped. Recorded rather than exempted so
 * the number is visible and can only move deliberately. Tracked in #1510.
 *
 * @var array<string, int>
 */
const SWAGGER_UNSCANNED_BASELINE = [
    // #1510 — RỖNG. Mọi namespace `Api/V1` có attribute OpenAPI đều đã được ít
    // nhất một bucket trong `config/l5-swagger.php` quét.
    //
    // Rỗng KHÔNG có nghĩa là "API đã có tài liệu đầy đủ" — nó chỉ có nghĩa là
    // không còn attribute nào được viết vào hư không. Route CHƯA chú thích vẫn
    // là việc tồn, và con số đó rào này không đo (xem ghi chú 45 route
    // workstation bên dưới).
    //
    // Bốn mục vừa gỡ, kèm nơi chúng đi tới:
    //   Device (3)        → bucket `workstation` VÀ `kds` — ghép nối là bước
    //                       đầu của cả hai quy trình, nên trùng lặp là đúng.
    //   Kds (8)           → bucket `kds` mới, cùng khuôn `workstation` (#1499).
    //   Notifications (1) → bucket `customer`: `EmailUnsubscribeController` là
    //                       đường dẫn KHÁCH bấm trong email.
    //   Webhooks (1)      → bucket `webhooks` mới, chiều VÀO từ nhà cung cấp.
    // #1499 — `Workstation` (17) ĐÃ RA KHỎI danh sách: bucket `workstation`
    // trong `config/l5-swagger.php` nay quét nó, và cả 17 operation đều vào
    // `storage/api-docs/workstation-api-docs.json`.
    //
    // Đo lúc gỡ: 62 route dưới `/api/v1/workstation/*`, 17 trong số đó có
    // attribute operation. Nói cách khác bucket này công bố 100% những gì đã
    // được chú thích, còn 45 route chưa chú thích là việc TỒN — đừng đọc con số
    // 17 thành "workstation đã có tài liệu đầy đủ".
];

/** Count OA operation attributes (`#[OA\Get(`, `#[OA\Post(`, …) under a namespace. */
function swaggerOperationCount(string $dir): int
{
    $total = 0;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $total += preg_match_all('/#\[OA\\\\(Get|Post|Put|Patch|Delete)\(/', (string) file_get_contents($file->getPathname()));
    }

    return $total;
}

it('never lets an annotated Api/V1 namespace go unscanned', function () {
    $config = require base_path('config/l5-swagger.php');

    $scanned = collect($config['documentations'])
        ->flatMap(fn (array $doc) => $doc['paths']['annotations'] ?? [])
        ->map(fn (string $path) => str_replace(base_path().'/', '', $path))
        ->all();

    $unscanned = [];
    foreach (glob(app_path('Http/Controllers/Api/V1/*'), GLOB_ONLYDIR) as $dir) {
        $namespace = basename($dir);
        $operations = swaggerOperationCount($dir);
        if ($operations === 0) {
            continue; // undocumented, not lying — out of scope here
        }

        $needle = 'app/Http/Controllers/Api/V1/'.$namespace;
        $covered = collect($scanned)->contains(
            fn (string $p) => $p === $needle || Str::startsWith($p, $needle.'/')
        );
        if (! $covered) {
            $unscanned[$namespace] = $operations;
        }
    }

    ksort($unscanned);
    $baseline = SWAGGER_UNSCANNED_BASELINE;
    ksort($baseline);

    expect($unscanned)->toBe(
        $baseline,
        "The set of annotated-but-unscanned Api/V1 namespaces changed.\n".
        "- A NEW namespace here means its OA attributes are no-ops: add base_path('app/Http/Controllers/Api/V1/<Ns>') ".
        "to a documentation's `annotations` in config/l5-swagger.php.\n".
        "- A namespace GONE (or a smaller count) means you fixed one — tighten SWAGGER_UNSCANNED_BASELINE.\n".
        '- A BIGGER count means new annotations were just written into the void.'
    );
});

it('has POS paths in the committed shop doc, not only in the config', function () {
    // The config half can be right while the committed artifact is stale —
    // that gap is precisely how this stayed invisible. Assert the JSON too.
    $doc = file_get_contents(storage_path('api-docs/shop-api-docs.json'));

    expect($doc)->toContain('/api/v1/pos/');
});
