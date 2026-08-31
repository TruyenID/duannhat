<?php

namespace App\Http\Middleware;

use App\Omnify\Enums\DeviceStatusEnum;
use App\Omnify\Enums\DeviceTypeEnum;
use App\Services\Device\DeviceService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateDevice
{
    public function __construct(
        private readonly DeviceService $service,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  string  ...$types  Allowed device types (e.g. 'tms', 'pos')
     */
    public function handle(Request $request, Closure $next, string ...$types): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['message' => 'Device token required.'], 401);
        }

        $device = $this->service->findByToken($token);

        if (! $device) {
            return response()->json(['message' => 'Invalid device token.'], 401);
        }

        if ($device->status !== DeviceStatusEnum::Active) {
            return response()->json(['message' => 'Device is not active.'], 401);
        }

        if (! empty($types)) {
            $allowedTypes = array_map(
                fn (string $t) => DeviceTypeEnum::tryFrom($t),
                $types
            );

            if (! in_array($device->type, $allowedTypes, true)) {
                return response()->json([
                    'message' => 'Device type not allowed for this endpoint.',
                    'code' => 'DEVICE_TYPE_NOT_ALLOWED',
                ], 403);
            }
        }

        $request->attributes->set('device', $device);

        // Fire-and-forget heartbeat. `X-App-Version` is optional by design: every
        // client shipped before #2142 omits it, and that must stay a supported
        // answer rather than a downgrade in service.
        $this->service->heartbeat($device, $request->header('X-App-Version'));

        return $next($request);
    }
}
