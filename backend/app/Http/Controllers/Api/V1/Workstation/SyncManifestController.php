<?php

namespace App\Http\Controllers\Api\V1\Workstation;

use App\Http\Controllers\Controller;
use App\Services\Workstation\SyncManifestService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/workstation/sync-manifest (#1175 phase 2).
 *
 * One conditional GET per 5s tick instead of N unconditional full pulls. The
 * workstation sends If-None-Match with the ETag it last saw; when nothing
 * changed (the overwhelmingly common case) the answer is a cached, empty 304.
 * On a 200 the client re-pulls ONLY the feeds whose opaque version moved,
 * stamping each feed's version AFTER its pull succeeds.
 *
 * Backward compatible by construction: none of the existing pull endpoints
 * changed — an old workstation that never calls this keeps full-pulling.
 */
class SyncManifestController extends Controller
{
    public function __construct(private readonly SyncManifestService $manifests) {}

    #[OA\Get(
        path: '/api/v1/workstation/sync-manifest',
        summary: 'Per-feed sync version manifest for the workstation pull tick',
        description: 'Returns an opaque version per replica feed plus an aggregate manifest_version (also sent as ETag). Send If-None-Match with the previous ETag to get a cheap empty 304 when nothing changed. Feed keys are a frozen contract.',
        tags: ['Workstation'],
        security: [['device_token' => []]],
        parameters: [
            new OA\Parameter(name: 'If-None-Match', in: 'header', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Manifest payload {data: {manifest_version, feeds}} + ETag header'),
            new OA\Response(response: 304, description: 'Nothing changed since the presented ETag (empty body)'),
            new OA\Response(response: 401, description: 'Missing/invalid device token'),
        ],
    )]
    public function index(Request $request): Response
    {
        $device = $request->attributes->get('device');

        // #2712 — this ALREADY answers an idle tick without rebuilding:
        // `manifestFor()` is `Cache::remember`, so a warm memo costs one cache
        // read and `build()` never runs. Reading the entry here first and
        // falling back to `manifestFor()` was measured to be identical on a hit
        // and one extra cache read on a miss, so the 304 path is left alone.
        // What made idle ticks expensive was TTL_SECONDS < the 5s tick, which
        // meant there was never a warm memo to hit.
        $manifest = $this->manifests->manifestFor($device);
        $etag = '"'.$manifest['manifest_version'].'"';

        if ($this->ifNoneMatchHits((string) $request->header('If-None-Match', ''), $etag)) {
            return response('', 304)->header('ETag', $etag);
        }

        return response()->json(['data' => $manifest])->header('ETag', $etag);
    }

    /**
     * RFC 9110 §13.1.2 lite: match any listed entity-tag (weak-prefix and
     * bare-value tolerant so a hand-rolled Go client comparing either form
     * still gets its 304), plus the `*` wildcard.
     */
    private function ifNoneMatchHits(string $ifNoneMatch, string $etag): bool
    {
        if (trim($ifNoneMatch) === '') {
            return false;
        }

        $bare = trim($etag, '"');
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);
            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }

            if ($candidate === '*' || $candidate === $etag || trim($candidate, '"') === $bare) {
                return true;
            }
        }

        return false;
    }
}
