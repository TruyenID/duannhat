<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class LocaleController extends Controller
{
    private const SUPPORTED_LOCALES = ['ja', 'en', 'vi'];

    #[OA\Post(
        path: '/api/v1/locale',
        summary: 'Update user locale',
        description: 'Sets the locale for the authenticated user. Persists to user profile + cookie.',
        tags: ['User Context'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['locale'],
                properties: [
                    new OA\Property(property: 'locale', type: 'string', enum: ['ja', 'en', 'vi']),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Locale updated',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'locale', type: 'string'),
                    new OA\Property(property: 'message', type: 'string'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Invalid locale'),
        ]
    )]
    public function updateLocale(Request $request): JsonResponse
    {
        $request->validate([
            'locale' => ['required', 'string', 'in:'.implode(',', self::SUPPORTED_LOCALES)],
        ]);

        $locale = $request->input('locale');

        app()->setLocale($locale);

        if ($user = $request->user()) {
            $user->update(['locale' => $locale]);
        }

        return response()->json([
            'locale' => $locale,
            'message' => 'Locale updated.',
        ])->withCookie(cookie(
            name: 'app_locale',
            value: $locale,
            minutes: 60 * 24 * 365,
            httpOnly: false,
            sameSite: 'lax',
        ));
    }

    #[OA\Post(
        path: '/api/v1/timezone',
        summary: 'Update user timezone',
        description: 'Sets the timezone for the authenticated user. Persists to user profile + cookie.',
        tags: ['User Context'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['timezone'],
                properties: [
                    new OA\Property(property: 'timezone', type: 'string', example: 'Asia/Tokyo', description: 'Valid IANA timezone identifier'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Timezone updated',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'timezone', type: 'string'),
                    new OA\Property(property: 'message', type: 'string'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Invalid timezone'),
        ]
    )]
    public function updateTimezone(Request $request): JsonResponse
    {
        $request->validate([
            'timezone' => ['required', 'string', 'timezone'],
        ]);

        $timezone = $request->input('timezone');

        if ($user = $request->user()) {
            $user->update(['timezone' => $timezone]);
        }

        return response()->json([
            'timezone' => $timezone,
            'message' => 'Timezone updated.',
        ])->cookie('timezone', $timezone, 60 * 24 * 365);
    }
}
