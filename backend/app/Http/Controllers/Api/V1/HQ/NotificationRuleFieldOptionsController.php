<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Notification;
use App\Services\Notification\ModelFieldIntrospectionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * `GET /api/v1/hq/{brandSlug}/notifications/rules/field-options` —
 * model introspection feed for the rule editor FieldPicker
 * (plan-023 M7 T7.9).
 *
 * Returns:
 *   {
 *     "data": {
 *       "model": "Recipe",
 *       "fields": [
 *         {"name":"approval_status","type":"string","ops_supported":[...]}
 *       ],
 *       "relations": [
 *         {"name":"brand","target_alias":"Brand","type":"belongsTo"}
 *       ]
 *     }
 *   }
 *
 * Whitelist: model alias MUST be registered in the morph map.
 * Asking for an unmapped class returns 404 instead of dumping
 * arbitrary class attributes.
 */
class NotificationRuleFieldOptionsController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly ModelFieldIntrospectionService $introspector) {}

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/notifications/rules/field-options',
        summary: 'Introspect an Eloquent model for the rule editor FieldPicker',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'model', in: 'query', required: true, description: 'Morph alias (e.g. Recipe, CustomerOrder, StockAlert)', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Fields + relations'),
            new OA\Response(response: 404, description: 'Model alias not in morph map'),
        ]
    )]
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        // Reuse the audit ability — viewing the field options does not
        // require write access. ManageRules would be too tight; viewAdmin
        // is what HQ admins already have.
        $this->authorize('viewAdmin', [Notification::class, $brand]);

        $alias = (string) $request->query('model', '');
        if ($alias === '') {
            return response()->json(['message' => 'model query param is required', 'error' => 'model_missing'], 422);
        }

        $payload = $this->introspector->describe($alias);
        if ($payload === null) {
            return response()->json(['message' => "Model alias [{$alias}] is not in the morph map", 'error' => 'model_unmapped'], 404);
        }

        return response()->json(['data' => $payload]);
    }
}
