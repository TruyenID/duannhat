<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public read-only proxy that streams an image object from the UPLOAD disk
 * (`config('filesystems.uploads')`) on the SAME origin as the API.
 *
 * WHY: on staging the object store returns URLs like
 * `http://localhost:5490/tempo/<path>` (MinIO on the server's localhost). A
 * NATIVE client (kiosk) has no server to proxy through and cannot reach the
 * server's localhost — it only reaches the backend via the API tunnel, so
 * serving the same bytes here lets it load product/logo images at
 * `${API}/api/v1/media/<path>`.
 *
 * Prod-safe: with a real S3/CDN `AWS_URL` the object URLs never point at
 * localhost, so clients never rewrite to this route and it stays dormant.
 *
 * SECURITY (issue #896): this route is public + unauthenticated, so it MUST
 * NOT be usable to read arbitrary bucket objects (cross-tenant private files)
 * or to deliver active content (stored-XSS via html/svg). It only serves an
 * object when EITHER:
 *   1. a `files` row proves the object is a public product image — it lives on
 *      the upload disk, is `permanent` (attached, not a stray temp upload),
 *      and belongs to a public `collection` (see COLLECTION_ALLOWLIST); OR
 *   2. the path sits under an explicit public prefix (see
 *      PUBLIC_PREFIX_ALLOWLIST) for seeded/runtime brand assets that carry no
 *      `files` row (shop logos/banners).
 * In both cases the served Content-Type is forced to a raster image MIME from
 * an allowlist (SVG is excluded — it can carry script), `nosniff` blocks
 * content-type sniffing, and the body is served `inline`. Anything else 404s.
 */
class MediaProxyController extends Controller
{
    /**
     * Raster image MIME types this proxy will serve. `image/svg+xml` is
     * intentionally excluded — SVG can embed <script> and is an XSS vector.
     *
     * @var list<string>
     */
    private const IMAGE_MIME_ALLOWLIST = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/avif',
    ];

    /**
     * Path prefixes for public objects that carry no `files` row (shop
     * logos/banners written directly to `branches/…` by HQ upload + seeders).
     * Matched case-sensitively (S3 keys are case-sensitive).
     *
     * @var list<string>
     */
    private const PUBLIC_PREFIX_ALLOWLIST = ['branches/'];

    /**
     * `files.collection` values that are public product imagery. Anything
     * else (e.g. the generic `default` upload collection) stays private even
     * when permanent + image, so a logged-in user cannot make an arbitrary
     * upload world-readable through this route.
     *
     * @var list<string>
     */
    private const COLLECTION_ALLOWLIST = ['gallery'];

    /**
     * Extension → MIME map for the prefix branch (objects with no `files`
     * row). Deliberately not `Storage::mimeType()` — that re-sniffs bytes and
     * could resolve to svg/html. Only raster extensions are mapped; a `.svg`
     * request simply has no mapping and 404s.
     *
     * @var array<string, string>
     */
    private const EXT_TO_MIME_MAP = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'avif' => 'image/avif',
    ];

    /**
     * Disk this proxy streams FROM — the same one the upload paths write TO
     * (#2175). Read from config, never a hard-coded name.
     *
     * Đường GHI đã chuyển sang `config('filesystems.uploads')` ở #2163
     * (`ShopController::branchImageDisk()`, `FileController@upload`,
     * `CustomerReviewController`). Đường ĐỌC vẫn ghim `'s3'`, nên hai đầu chỉ
     * trùng nhau khi đích ghi TÌNH CỜ cũng là s3 — và prod thì không: nó chạy
     * `UPLOADS_DISK` mặc định `public`. Object ghi lên `public`, proxy tìm trên
     * `s3` ⇒ 404 câm, đúng hình dạng đã tốn hai ngày ở #2101.
     *
     * Tên disk là **cấu hình theo môi trường**: ghi cứng nó đúng cho một môi
     * trường và sai cho mọi môi trường còn lại.
     */
    private function uploadsDisk(): string
    {
        return (string) config('filesystems.uploads');
    }

    public function __invoke(Request $request, string $path): StreamedResponse
    {
        // Defence in depth: reject obvious traversal / absolute paths up front
        // so a crafted request never even reaches the driver. Laravel decodes
        // the route param once, so `%2e%2e` is already `..` here.
        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, '..')) {
            abort(404);
        }

        // (1) Resolve the MIME from the authoritative source, or 404 if the
        // object is not one we are allowed to serve.
        $mime = $this->resolveServableMime($path);
        if ($mime === null) {
            abort(404);
        }

        // (2) MIME allowlist (raster only — never svg/html). Fail closed with
        // 404 (not 415) so the route never confirms a private object exists.
        if (! in_array(strtolower($mime), self::IMAGE_MIME_ALLOWLIST, true)) {
            abort(404);
        }

        // (3) Confirm the object actually exists. The upload disks are
        // configured with `throw => false`, so a missing key would otherwise
        // stream an empty 200 body via `fpassthru(false)`.
        $disk = Storage::disk($this->uploadsDisk());
        if (! $disk->exists($path)) {
            abort(404);
        }

        // (4) Stream with hardened headers. Passing `Content-Type` overrides
        // the object's stored type (so an html/polyglot payload is served as a
        // benign image); `nosniff` stops the browser second-guessing it; the
        // `inline` disposition is safe for raster images. Do NOT also set a
        // `Content-Disposition` header key — the disposition arg builds it.
        return $disk->response($path, basename($path), [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ], 'inline');
    }

    /**
     * Return the MIME to serve `$path` with, or null if the object is not
     * eligible. A permanent `files` row in a public collection on the UPLOAD
     * disk wins; otherwise a path under a public prefix resolves by extension.
     *
     * The row's `disk` must equal the disk step (3) streams from: `files.disk`
     * is stored PER ROW, so a row written under a different disk describes
     * bytes at a key that may not exist — or may be a DIFFERENT object — on
     * the disk we are about to read. Matching both against
     * `config('filesystems.uploads')` keeps the authorisation and the read on
     * the same storage.
     */
    private function resolveServableMime(string $path): ?string
    {
        // File-row gate. Match by path in the DB (MySQL collation is
        // case-insensitive), then re-assert an exact case-sensitive match in
        // PHP so the authorised row always describes the exact key we stream.
        $row = File::query()
            ->where('path', $path)
            ->where('disk', $this->uploadsDisk())
            ->permanent()
            ->whereIn('collection', self::COLLECTION_ALLOWLIST)
            ->get(['path', 'mime_type'])
            ->first(fn (File $file): bool => $file->path === $path);

        if ($row !== null) {
            return $row->mime_type;
        }

        // Prefix gate for public objects with no `files` row (brand assets).
        foreach (self::PUBLIC_PREFIX_ALLOWLIST as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

                return self::EXT_TO_MIME_MAP[$ext] ?? null;
            }
        }

        return null;
    }
}
