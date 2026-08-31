<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Table;
use App\Services\Customer\CustomerMenuService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class CustomerMenuController extends Controller
{
    public function __construct(
        private CustomerMenuService $menuService,
    ) {}

    #[OA\Get(
        path: '/api/v1/customer/tables/{qrToken}/menu',
        summary: 'Get the active menu for the table that owns the given QR token',
        description: 'Public endpoint used by the QR-scan flow. Resolves the table by `qr_token`, derives its branch, and returns the menu currently active for that branch (filtered by server-side time-of-day rules — no client-supplied time accepted).',
        tags: ['Customer Menu'],
        security: [],
        parameters: [
            new OA\Parameter(
                name: 'qrToken',
                in: 'path',
                required: true,
                description: 'Per-table QR token printed on the physical QR code.',
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Active menu for the table\'s branch.',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/CustomerMenu'),
                ]),
            ),
            new OA\Response(response: 404, description: 'Table not found, inactive, or no active menu for the branch.'),
        ],
    )]
    public function show(Request $request, string $qrToken): JsonResponse
    {
        $table = Table::where('qr_token', $qrToken)
            ->where('is_active', true)
            ->first();

        if (! $table) {
            return response()->json(['message' => 'Table not found.'], 404);
        }

        $brandId = $request->query('brand');
        // Dine-in flow (QR at a table) → only Dine-in (+ Both) menus. (#463)
        $menu = $this->menuService->getMenuForBranch($table->branch_id, $brandId, 'DineIn');

        if (! $menu) {
            return $this->menuUnavailableResponse($table->branch_id, $brandId, 'DineIn');
        }

        return response()->json(['data' => $menu]);
    }

    #[OA\Get(
        path: '/api/v1/customer/branches/{branchSlug}/menu',
        summary: 'Get the active menu for a branch resolved by slug',
        description: 'Public endpoint used by the takeaway/delivery flow where there is no QR token. Looks up the branch by `slug`, then returns the menu currently active for that branch (filtered by server-side time-of-day rules — no client-supplied time accepted).',
        tags: ['Customer Menu'],
        security: [],
        parameters: [
            new OA\Parameter(
                name: 'branchSlug',
                in: 'path',
                required: true,
                description: 'Branch slug (URL-safe identifier).',
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Active menu for the branch.',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/CustomerMenu'),
                ]),
            ),
            new OA\Response(response: 404, description: 'Branch not found, inactive, or no active menu for the branch.'),
        ],
    )]
    public function showByBranch(Request $request, string $branchSlug): JsonResponse
    {
        $branch = Branch::where('slug', $branchSlug)
            ->where('is_active', true)
            ->first();

        if (! $branch) {
            return response()->json(['message' => 'Branch not found.'], 404);
        }

        $brandId = $request->query('brand');
        // Takeaway/delivery flow (no QR table) → only Takeaway (+ Both) menus. (#463)
        $menu = $this->menuService->getMenuForBranch($branch->id, $brandId, 'Takeaway');

        if (! $menu) {
            return $this->menuUnavailableResponse($branch->id, $brandId, 'Takeaway');
        }

        return response()->json(['data' => $menu]);
    }

    private function menuUnavailableResponse(string $branchId, ?string $brandId, string $serviceType): JsonResponse
    {
        $availability = $this->menuService->getNextOpeningForBranch($branchId, $brandId, $serviceType);

        if ($availability) {
            return response()->json([
                'message' => 'Online ordering is currently outside service hours.',
                'code' => 'menu_outside_service_hours',
                'availability' => $availability,
            ], 404);
        }

        return response()->json([
            'message' => 'No menu is currently available for online ordering.',
            'code' => 'menu_unavailable',
        ], 404);
    }
}
