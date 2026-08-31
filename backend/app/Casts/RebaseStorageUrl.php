<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Rebases stored storage URLs onto the CURRENT public URL of the UPLOAD disk
 * (`config('filesystems.uploads')`) on read — survives ANY hostname change
 * (staging cloudflared rotation, switch to ngrok, migration to a production
 * CDN, local vs staging swap).
 *
 * Why this exists:
 *  - Branch.logo / Branch.img_branches are seeded with full URLs (host +
 *    base path + object path). When the public hostname changes — for
 *    ANY reason — every stored URL silently breaks until a reseed.
 *  - Re-seeding is operationally expensive; this cast normalises on read.
 *
 * WHICH DISK (#2175). The base used to be read from `filesystems.disks.s3.url`
 * regardless of where the bytes were actually written. Since #2163 the write
 * path (`ShopController::branchImageDisk()`) resolves
 * `config('filesystems.uploads')`, and prod runs that at its default `public`
 * — so the cast was rebasing onto a host that holds none of the objects. It
 * did not 404 anything only because the two mismatches cancelled out: with
 * `AWS_URL` empty the cast bailed early, and with it set the de-based path
 * (`storage/branches/…`) no longer matched STORAGE_KEY_PREFIXES. Both are
 * accidents, not a design — and both mean the safety net was simply OFF for
 * every image written after #2163.
 *
 * Reading the base from the SAME config key the write path reads also removes
 * the collision the old shape allowed: an `AWS_URL` whose path segment was
 * literally `storage` (e.g. `https://minio.other/storage`) stripped the
 * `storage` segment of a `public`-disk URL and rebased it onto a host that
 * never held the object.
 *
 * Behaviour:
 *  - Stored value is empty / null / not a string → pass through.
 *  - Stored value is not a full http(s) URL (relative path, data: URL,
 *    anything else) → pass through; the caller intended it as-is.
 *  - The upload disk publishes no `url` → can't rebase, pass through.
 *  - Stored host matches the live host → no-op (already fresh).
 *  - Stored host DIFFERS from the live host → strip stored scheme+host AND
 *    the base path (matched against the live URL's path segment, e.g.
 *    `/tempo` for s3 or `/storage` for the public disk), prepend the live
 *    URL. Works for arbitrary host changes — trycloudflare ↔ ngrok ↔ CDN ↔
 *    localhost.
 *  - Write path is a no-op so seeders (and any code that writes
 *    fully-qualified URLs) keep working unchanged.
 *
 * Not a substitute for the proper fix (store the object key, generate URLs
 * at serialization time). It's a pragmatic shield until the schema
 * migration to `*_path` columns lands.
 */
class RebaseStorageUrl implements CastsAttributes
{
    /**
     * Object-key prefixes that identify a value as OUR stored object (vs. an
     * external CDN URL seeded directly). Only URLs whose de-based path
     * begins with one of these are rebased onto the live storage host.
     *
     * @var list<string>
     */
    private const STORAGE_KEY_PREFIXES = ['branches/'];

    /**
     * Public URL root of the disk the upload paths write to, or `''` when the
     * disk publishes no URL (a private `local` disk) and there is therefore
     * nothing to rebase onto.
     */
    private function liveBase(): string
    {
        $disk = (string) config('filesystems.uploads');

        if ($disk === '') {
            return '';
        }

        return rtrim((string) config("filesystems.disks.{$disk}.url", ''), '/');
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        // Pass through anything that isn't a full http(s) URL — relative
        // paths, data: URIs, etc. are intentionally stored that way.
        if (! preg_match('|^(https?)://([^/]+)(.*)$|i', $value, $stored)) {
            return $value;
        }
        $storedHost = strtolower($stored[2]);
        $storedPath = (string) $stored[3];

        $base = $this->liveBase();
        if ($base === '') {
            return $value;
        }

        if (! preg_match('|^(https?)://([^/]+)(.*)$|i', $base, $current)) {
            // The disk's url is malformed (no scheme://host) — better to
            // surface the raw stored value than to silently corrupt it.
            return $value;
        }
        $currentHost = strtolower($current[2]);
        $currentBasePath = trim((string) $current[3], '/');

        // Already pointing at the live host → no-op.
        if ($storedHost === $currentHost) {
            return $value;
        }

        // Strip the stored base path if it matches the current one (e.g.
        // stored `/tempo/branches/foo.png` with current base `https://Y/tempo`
        // → strip `/tempo` to avoid doubling it).
        $remaining = ltrim($storedPath, '/');
        if ($currentBasePath !== '') {
            if (str_starts_with($remaining, $currentBasePath.'/')) {
                $remaining = substr($remaining, strlen($currentBasePath) + 1);
            } elseif ($remaining === $currentBasePath) {
                $remaining = '';
            }
        }

        // Only rebase URLs that point at OUR object storage. A stored value may
        // instead be a third-party CDN URL seeded directly — e.g. an Unsplash
        // demo banner `https://images.unsplash.com/photo-…`. Those have a host
        // that differs from the live host too, but they are NOT our objects, so
        // rebasing them onto our storage yields a 404 (the object doesn't exist
        // there) and the image silently breaks. Our Branch logo/banner objects
        // all live under the `branches/` key prefix; anything else is external
        // and passes through untouched.
        foreach (self::STORAGE_KEY_PREFIXES as $prefix) {
            if (str_starts_with($remaining, $prefix)) {
                return $base.'/'.$remaining;
            }
        }

        return $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value;
    }
}
