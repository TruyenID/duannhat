<?php

namespace App\Http\Controllers\Api\V1\Workstation;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Brand;
use App\Services\Customer\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Device-authed twin of POST /api/v1/pos/customers/find-or-create.
 *
 * The workstation forwards a customer the cashier created while LAN-only
 * (phone + optional name/email) so Cloud dedupes by phone within the device's
 * org+branch and returns the canonical id. Auth context — organization,
 * branch, brand — is resolved from the paired device, NOT a user session or a
 * shop slug (matching CustomerReplicaController / OrderController).
 *
 * Why a /workstation twin of the existing /pos route: cloudPost only
 * re-stamps the fresh device token on /api/v1/workstation/* paths, so a
 * baked-at-enqueue token stays valid here instead of going stale and 401-ing
 * the sync loop forever.
 *
 * Unlike the Shop/POS CustomerController this deliberately does NOT call
 * authorize(): device auth has no User, so the user-based CustomerPolicy
 * cannot run — every workstation write endpoint scopes by device instead.
 *
 * Naturally idempotent by phone: a replay returns the existing row (200), so
 * the workstation's Idempotency-Key header needs no extra cache layer.
 */
class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerService $service,
    ) {}

    #[OA\Post(
        path: '/api/v1/workstation/customers/find-or-create',
        summary: 'Find a customer by exact phone, or create one (device-authed)',
        description: 'Workstation sync-UP twin of the POS find-or-create. Dedupes by phone within the device\'s org+branch: returns the existing customer (200) or creates one with the supplied phone/name/email (201). first_name falls back to "Khách" when omitted.',
        tags: ['Workstation'],
        security: [['device_token' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['phone'],
            properties: [
                new OA\Property(property: 'phone', type: 'string', minLength: 1, maxLength: 20),
                new OA\Property(property: 'first_name', type: 'string', maxLength: 100, nullable: true),
                new OA\Property(property: 'last_name', type: 'string', maxLength: 100, nullable: true),
                new OA\Property(property: 'email', type: 'string', format: 'email', maxLength: 255, nullable: true),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Existing customer returned', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/Customer'),
                new OA\Property(property: 'created', type: 'boolean', example: false),
            ])),
            new OA\Response(response: 201, description: 'New customer created', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/Customer'),
                new OA\Property(property: 'created', type: 'boolean', example: true),
            ])),
            new OA\Response(response: 401, description: 'Missing/invalid device token'),
            new OA\Response(response: 422, description: 'Validation failed, or device has no active branch'),
        ]
    )]
    public function findOrCreate(Request $request): JsonResponse
    {
        $device = $request->attributes->get('device');
        $device->loadMissing('branch');
        $branch = $device->branch;

        if (! $branch) {
            abort(422, 'Device is not associated with an active branch.');
        }

        $data = $request->validate([
            'phone' => ['required', 'string', 'min:1', 'max:20'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        $brandId = Brand::where('console_brand_id', $branch->console_brand_id)->value('id');

        [$customer, $wasCreated] = $this->service->findOrCreateByPhone(
            trim($data['phone']),
            [
                'organization_id' => $device->organization_id,
                'branch_id' => $device->branch_id,
                'brand_id' => $brandId,
                'first_name' => $data['first_name'] ?? null,
                'last_name' => $data['last_name'] ?? null,
                'email' => $data['email'] ?? null,
            ],
        );

        return (new CustomerResource($customer))
            ->additional(['created' => $wasCreated])
            ->response()
            ->setStatusCode($wasCreated ? 201 : 200);
    }
}
