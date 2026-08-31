<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Returns details for the brand resolved by ResolveBrandFromSlug middleware.
 *
 * Frontend uses this to validate {brandSlug} on every HQ page load. The
 * middleware does the slug → brand lookup + 404/403 enforcement, so the
 * controller body is just "format and return". This is the cheap, single-row
 * alternative to fetching the entire /me/context just to check membership.
 */
class BrandInfoController extends Controller
{
    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}',
        summary: 'Get the resolved HQ brand',
        description: 'Returns details for the brand identified by {brandSlug}. The brand is loaded by ResolveBrandFromSlug middleware, which returns 404 if the slug does not exist and 403 if the brand belongs to a different organization.',
        tags: ['HQ'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Resolved brand',
                content: new OA\JsonContent(properties: [
                    new OA\Property(
                        property: 'data',
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'slug', type: 'string'),
                            new OA\Property(property: 'name', type: 'string'),
                            new OA\Property(property: 'description', type: 'string', nullable: true),
                            new OA\Property(property: 'logo_url', type: 'string', nullable: true),
                            new OA\Property(property: 'primary_color', type: 'string', nullable: true, example: '#009444'),
                            new OA\Property(property: 'secondary_color', type: 'string', nullable: true, example: '#FFC20E'),
                            new OA\Property(property: 'accent_color', type: 'string', nullable: true, example: '#00B856'),
                            new OA\Property(property: 'text_color', type: 'string', nullable: true, example: '#171614'),
                        ]
                    ),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Brand belongs to a different organization'),
            new OA\Response(response: 404, description: 'Brand not found or inactive'),
        ]
    )]
    public function show(Request $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');

        return response()->json([
            'data' => [
                'id' => $brand->id,
                'slug' => $brand->slug,
                'name' => $brand->name,
                'description' => $brand->description,
                'logo_url' => $brand->logo_url,
                // Per-tenant brand colors consumed by the frontend
                // <TenantThemeProvider> at runtime. See schemas/Sso/Brand.yaml
                // for the omnify column definitions.
                'primary_color' => $brand->primary_color,
                'secondary_color' => $brand->secondary_color,
                'accent_color' => $brand->accent_color,
                'text_color' => $brand->text_color,
            ],
        ]);
    }
}
