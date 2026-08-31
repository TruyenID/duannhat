<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Printing;

use App\Http\Controllers\Controller;
use App\Services\Printing\CloudPrntJobRenderer;
use App\Services\Printing\CloudPrntService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * plan-052 M4 / plan-053 T5.4 (#1171) — the Star CloudPRNT endpoint.
 *
 * ── This route is called by a PRINTER, and that shapes everything ─────────
 *
 * It is not in the SSO ring and not in the device-token ring. A thermal
 * printer cannot run an OAuth flow, cannot be paired through
 * `POST /devices/pair`, and cannot renew a bearer token; the whole protocol
 * assumes a long-lived secret in the URL. So it lives beside the other
 * machine-called public endpoints (mail and payment webhooks) rather than
 * inside `/api/v1/{shops,pos,workstation}/…`, where every sibling assumes a
 * resolved user or device and where a future `->middleware('auth:…')` on the
 * group would silently take the shop's printers offline.
 *
 * The secret is `printers.print_token` (P-16: per-printer, ≥32 bytes,
 * rotatable, revoke = 401 at the very next poll). {@see CloudPrntService::authenticate()}
 * additionally requires the printer to be active AND still on the `cloudprnt`
 * transport, so moving a machine back to `ws_lan` revokes it as a side effect.
 *
 * ── The verbs are Star's ──────────────────────────────────────────────────
 *
 *   POST   poll        → `{jobReady, mediaTypes, jobToken, deleteMethod}`
 *   GET    fetch bytes → `application/vnd.star.starprnt`
 *   DELETE confirm     → `?code=200%20OK` (or a failure code)
 *
 * Note `plans/plan-052/DESIGN.md` §2 sketches GET as the poll and POST as the
 * confirm. That is inverted with respect to the actual protocol and would not
 * have talked to a real machine; the spec wins.
 */
class CloudPrntController extends Controller
{
    public function __construct(
        private readonly CloudPrntService $service,
        private readonly CloudPrntJobRenderer $renderer,
    ) {}

    /** The printer polls, and reports its own status while it is here. */
    public function poll(Request $request, string $printerToken): JsonResponse
    {
        $printer = $this->service->authenticate($printerToken);

        if ($printer === null) {
            return response()->json(['jobReady' => false], 401);
        }

        /** @var array<string, mixed> $body */
        $body = $request->isJson() ? (array) $request->json()->all() : [];

        return response()->json($this->service->poll($printer, $body));
    }

    /**
     * The printer downloads the bytes.
     *
     * Rendering happens HERE rather than at poll time on purpose: a poll runs
     * every few seconds per machine and rendering a slip on each one would burn
     * the shop's database budget describing a job nobody has asked for yet.
     * The cost of the choice is that a payload Cloud cannot draw is only
     * discovered now — so the job is FAILED before this method answers, which
     * is what stops the printer re-fetching the same broken job for ever.
     */
    public function fetch(Request $request, string $printerToken): Response
    {
        $printer = $this->service->authenticate($printerToken);

        if ($printer === null) {
            return response('', 401);
        }

        $requestedType = $request->query('type');

        if (! CloudPrntJobRenderer::servesMediaType(is_string($requestedType) ? $requestedType : null)) {
            // Star 510 — "incompatible media type". The printer asked for a
            // command set this server does not produce; handing it StarPRNT
            // bytes anyway would print garbage on the paper.
            return response('', 404);
        }

        $token = $request->query('token');
        $job = $this->service->claim($printer, is_string($token) ? $token : null);

        if ($job === null) {
            return response('', 404);
        }

        try {
            $bytes = $this->renderer->render($job, $printer);
        } catch (RuntimeException $e) {
            $this->service->failUnrenderable($job, $e->getMessage());

            return response('', 404);
        }

        return response($bytes, 200, [
            'Content-Type' => CloudPrntJobRenderer::MEDIA_TYPE,
            'Content-Length' => (string) strlen($bytes),
        ]);
    }

    /**
     * The printer confirms the outcome. `code` is a Star printer status code —
     * `200 OK` on success, `420 Cover open` and friends otherwise.
     *
     * Always 200, including for a job that no longer exists or has already been
     * confirmed (P-02). Answering anything else teaches the machine to retry a
     * confirmation that already landed.
     */
    public function confirm(Request $request, string $printerToken): Response
    {
        $printer = $this->service->authenticate($printerToken);

        if ($printer === null) {
            return response('', 401);
        }

        $token = $request->query('token');
        $code = $request->query('code');

        $this->service->confirm(
            $printer,
            is_string($token) ? $token : null,
            is_string($code) ? $code : '',
        );

        return response('', 200);
    }
}
