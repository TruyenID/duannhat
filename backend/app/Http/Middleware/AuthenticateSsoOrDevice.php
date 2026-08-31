<?php

namespace App\Http\Middleware;

use App\Omnify\Enums\DeviceStatusEnum;
use App\Omnify\Enums\DeviceTypeEnum;
use App\Services\Device\DeviceService;
use Closure;
use Dxs\Auth\Http\Middleware\AuthenticateSso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compound bearer-token auth for the /api/v1/pos/* namespace.
 *
 * Accepts either a Platform SSO access token OR a POS device
 * token (pos-web pairing) on the same routes. This is the piece that lets
 * pos-web fall back from workstation LAN → cloud with the device token it
 * already holds, without duplicating the whole POS controller layer.
 *
 * Device path sets three attributes so downstream code can tell it's a
 * device request without introducing a new guard:
 *   - `device`                → the resolved Device model
 *   - `_device_bypass_gate`   → scoped flag Gate::before() reads to grant
 *                               only the explicit POS-device ability allowlist
 *   - user resolver           → `$request->user()` returns the Device so
 *                               controllers that read `->id`/`->name` for
 *                               audit columns keep working
 *
 * SSO fallback delegates to the official Platform middleware.
 */
class AuthenticateSsoOrDevice
{
    public function __construct(
        private readonly DeviceService $service,
        private readonly AuthenticateSso $authenticateSso,
    ) {}

    /**
     * @param  string  ...$types  Allowed device types (defaults to 'pos')
     */
    public function handle(Request $request, Closure $next, string ...$types): Response
    {
        $token = $request->bearerToken();

        // Try device auth first when a bearer token is present — a single indexed query on
        // `device_token`), and if the token doesn't match any device we
        // fall through to the SSO check without an early return.
        if ($token) {
            $device = $this->service->findByToken($token);

            if ($device && $device->status === DeviceStatusEnum::Active) {
                $allowedTypes = ! empty($types) ? $types : ['pos'];
                $allowedTypes = array_map(
                    fn (string $t) => DeviceTypeEnum::tryFrom($t),
                    $allowedTypes,
                );

                if (! in_array($device->type, $allowedTypes, true)) {
                    $this->logReject($request, true, $device, 'device_type_not_allowed');

                    return response()->json([
                        'message' => 'Device type not allowed for this endpoint.',
                        'code' => 'DEVICE_TYPE_NOT_ALLOWED',
                    ], 403);
                }

                $request->attributes->set('device', $device);
                $request->setUserResolver(fn () => $device);
                // Scoped flag — only requests that came through THIS
                // middleware may enter the explicit device ability allowlist.
                // `device.auth` (kiosk/kds/handy/etc.) never sets it, so those
                // namespaces stay isolated.
                $request->attributes->set('_device_bypass_gate', true);
                // Same optional `X-App-Version` refresh as AuthenticateDevice
                // (#2142) — this is the OTHER door a device token can come
                // through, and a version indicator that only watches one door
                // undercounts by exactly the traffic that used the other.
                $this->service->heartbeat($device, $request->header('X-App-Version'));

                return $next($request);
            }
        }

        $response = $this->authenticateSso->handle($request, function (Request $request) use ($next): Response {
            $request->attributes->set('ssoUser', $request->user());

            return $next($request);
        });

        if ($response->getStatusCode() === 401 && $request->user() === null) {
            $reason = $token ? 'invalid_token' : 'token_required';
            $this->logReject($request, $token !== null, null, $reason);

            return response()->json([
                'message' => $token ? 'Invalid token or unauthenticated.' : 'Token required.',
                'code' => $token ? 'INVALID_TOKEN' : 'TOKEN_REQUIRED',
            ], 401);
        }

        return $response;
    }

    /**
     * Emit a structured log line whenever this middleware rejects a request.
     * Kept internal to the auth middleware — the `pos_auth` channel lets ops
     * tail rejections without ingesting every request (see plan
     * toasty-wiggling-eagle: was needed to trace a PATCH-only 401 on Shift
     * Close save-draft).
     */
    private function logReject(Request $request, bool $tokenPresent, $device, string $reason): void
    {
        Log::channel('pos_auth')->warning('pos_auth_reject', [
            'reason' => $reason,
            'method' => $request->method(),
            'path' => $request->path(),
            'token_present' => $tokenPresent,
            'device_matched' => (bool) $device,
            'device_id' => $device?->id,
            'device_type' => $device?->type instanceof \BackedEnum ? $device->type->value : $device?->type,
            'device_status' => $device?->status instanceof \BackedEnum ? $device->status->value : $device?->status,
            'shop_slug' => $request->header('X-Shop-Slug'),
        ]);
    }
}
