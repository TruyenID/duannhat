<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/pos/me
 *
 * Returns the caller's identity (either the SSO user or the paired
 * device) plus the resolved shop from the X-Shop-Slug header. Pos-web
 * calls this on boot to verify the session and confirm the caller
 * still has access to the shop in the URL. Under device auth the
 * `user` block is null and the `device` block is populated instead —
 * pos-web branches its UI on which key is present.
 */
class PosMeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $device = $request->attributes->get('device');
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');

        return response()->json([
            'user' => $device ? null : [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale ?? null,
                'timezone' => $user->timezone ?? null,
            ],
            'device' => $device ? [
                'id' => $device->id,
                'name' => $device->name,
                'type' => $device->type->value,
                'branch_id' => $device->branch_id,
            ] : null,
            'shop' => [
                'id' => $shop->id,
                'slug' => $shop->slug,
                'name' => $shop->name,
                'console_brand_id' => $shop->console_brand_id,
            ],
        ]);
    }
}
