<?php

namespace App\Http\Controllers\Api\V1\Device;

use App\Http\Controllers\Controller;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * `/api/v1/devices/broadcasting/auth` (plan-027 T0.3).
 *
 * Pusher-protocol auth endpoint for Reverb private channels subscribed by
 * device-paired clients (kiosk, KDS). Laravel's default /broadcasting/auth
 * is gated by sso.auth middleware — only SSO User tokens pass. This endpoint
 * provides the device-token equivalent.
 *
 * Strategy: manually invoke the registered channel authorization callbacks
 * (via `Broadcast::getChannels()`) with the Device as the authenticated
 * principal, then sign the socket_id + channel_name with HMAC-SHA256 using
 * the Reverb app secret. This avoids SDK coupling that breaks in test
 * environments where the Pusher SDK driver may not be the active driver.
 */
class BroadcastAuthController extends Controller
{
    #[OA\Post(
        path: '/api/v1/devices/broadcasting/auth',
        summary: 'Device-authenticated broadcast channel auth',
        description: "Pusher-protocol auth endpoint for Reverb private channels subscribed by paired devices. Laravel's default /broadcasting/auth is sso.auth gated; this route provides the device-token equivalent. Channel authorization callbacks in routes/channels.php receive the resolved Device as the first argument.",
        tags: ['Devices'],
        security: [['deviceToken' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Signed auth payload'),
            new OA\Response(response: 401, description: 'Missing/invalid device token'),
            new OA\Response(response: 403, description: 'Device not authorized for requested channel'),
        ]
    )]
    public function __invoke(Request $request, BroadcastManager $manager): JsonResponse
    {
        $device = $request->attributes->get('device');

        $channelName = (string) $request->input('channel_name', '');
        $socketId = (string) $request->input('socket_id', '');

        if (empty($channelName) || empty($socketId)) {
            throw new AccessDeniedHttpException;
        }

        // Strip the private- prefix to get the bare channel name used in
        // channel callback registration (e.g. "device.{id}.notifications").
        $normalizedName = $this->normalizeChannelName($channelName);

        // Walk registered channel callbacks and invoke the matching one with
        // the Device model as the authenticated principal.
        // getChannels() lives on Broadcaster, not BroadcastManager — proxied via __call.
        // Stable across Laravel 10-13 but not declared on the public interface, so
        // keep an eye on this if Laravel internals change.
        foreach ($manager->getChannels() as $pattern => $callback) {
            if (! $this->channelNameMatchesPattern($normalizedName, $pattern)) {
                continue;
            }

            $parameters = $this->extractChannelParameters($pattern, $normalizedName);
            $handler = is_callable($callback) ? $callback : fn (...$args) => app($callback)->join(...$args);

            $result = $handler($device, ...$parameters);

            if ($result === false) {
                throw new AccessDeniedHttpException;
            }

            if ($result) {
                // success — sign and return
                // Generate the Pusher-protocol HMAC-SHA256 auth token.
                // string_to_sign = "{socket_id}:{channel_name}"
                // auth           = "{app_key}:{HMAC_SHA256(secret, string_to_sign)}"
                //
                // Key/secret come from the ACTIVE driver's connection — the app
                // the client actually dialed (the same connection whose key the
                // branch feed advertises via BroadcastPokeSettings). This used
                // to hardcode `connections.reverb` (plan-027, when Reverb was
                // the driver); production runs BROADCAST_CONNECTION=pusher, so
                // every device channel auth 500'd "Reverb app secret is not
                // configured" while PHP-side broadcasts worked (measured at
                // Tsukiji 2026-08-18, the workstation poke client).
                //
                // A connection with no key/secret (`null`, `log`) can't sign
                // anything — fall back to the reverb connection, which is what
                // those environments carry credentials for.
                $driver = (string) config('broadcasting.default');
                $conn = (array) config("broadcasting.connections.{$driver}", []);
                if ((string) ($conn['key'] ?? '') === '' || (string) ($conn['secret'] ?? '') === '') {
                    $conn = (array) config('broadcasting.connections.reverb', []);
                }
                $appSecret = (string) ($conn['secret'] ?? '');
                if ($appSecret === '') {
                    abort(500, 'Broadcast app secret is not configured.');
                }
                $appKey = (string) ($conn['key'] ?? '');
                if ($appKey === '') {
                    abort(500, 'Broadcast app key is not configured.');
                }

                $signature = hash_hmac('sha256', "{$socketId}:{$channelName}", $appSecret);

                return response()->json(['auth' => "{$appKey}:{$signature}"]);
            }

            // null/0/''/'0' → continue loop to next channel pattern
        }

        // No callback authorized the channel.
        throw new AccessDeniedHttpException;
    }

    /**
     * Strip the private-/presence-/private-encrypted- prefix from a channel name.
     */
    private function normalizeChannelName(string $channel): string
    {
        foreach (['private-encrypted-', 'private-', 'presence-'] as $prefix) {
            if (Str::startsWith($channel, $prefix)) {
                return Str::replaceFirst($prefix, '', $channel);
            }
        }

        return $channel;
    }

    /**
     * Check if a bare channel name matches a registered channel pattern.
     * Pattern uses {placeholder} syntax; dots are literal.
     */
    private function channelNameMatchesPattern(string $channel, string $pattern): bool
    {
        $escaped = str_replace('.', '\.', $pattern);
        $regex = '/^'.preg_replace('/\{(.*?)\}/', '([^\.]+)', $escaped).'$/';

        return (bool) preg_match($regex, $channel);
    }

    /**
     * Extract positional parameter values from the channel name using the pattern.
     *
     * @return array<int, string>
     */
    private function extractChannelParameters(string $pattern, string $channel): array
    {
        $escaped = str_replace('.', '\.', $pattern);
        $regex = '/^'.preg_replace('/\{(.*?)\}/', '(?<$1>[^\.]+)', $escaped).'$/';

        preg_match($regex, $channel, $matches);

        return array_values(array_filter(
            $matches,
            fn ($key) => ! is_numeric($key),
            ARRAY_FILTER_USE_KEY
        ));
    }
}
