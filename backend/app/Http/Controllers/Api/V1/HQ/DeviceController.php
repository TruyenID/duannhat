<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasBulkOperations;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\DeviceStoreRequest;
use App\Http\Requests\DeviceUpdateRequest;
use App\Http\Resources\DeviceResource;
use App\Models\Device;
use App\Models\DeviceSigningKey;
use App\Services\Device\DeviceService;
use App\Services\Device\DeviceSigningKeyService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DeviceController extends Controller
{
    use AuthorizesRequests;
    use HasBulkOperations;
    use HasOrganizationContext;

    public function __construct(
        private readonly DeviceService $service,
    ) {}

    // =========================================================================
    //  CRUD
    // =========================================================================

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Device::class);

        $devices = $this->service->list([
            'organization_id' => $this->getOrganizationId(),
            'search' => $request->input('search'),
            'status' => $request->input('status'),
            'type' => $request->input('type'),
            'branch_id' => $request->input('branch_id'),
            'with_trashed' => $request->boolean('with_trashed', false),
            'sort' => $request->input('sort', '-created_at'),
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return DeviceResource::collection($devices);
    }

    public function store(DeviceStoreRequest $request): JsonResponse
    {
        $this->authorize('create', Device::class);

        $data = $request->validated();
        $data['organization_id'] = $this->getOrganizationId();

        $device = $this->service->create($data);

        return (new DeviceResource($device))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request): DeviceResource
    {
        $device = $this->resolveDevice($request);
        $this->authorizeOrganization($device);
        $this->authorize('view', $device);

        return new DeviceResource(
            $this->service->findById($device->id)
        );
    }

    public function update(DeviceUpdateRequest $request): DeviceResource
    {
        $device = $this->resolveDevice($request);
        $this->authorizeOrganization($device);
        $this->authorize('update', $device);

        $device = $this->service->update($device, $request->validated());

        return new DeviceResource($device);
    }

    public function destroy(Request $request): JsonResponse
    {
        $device = $this->resolveDevice($request);
        $this->authorizeOrganization($device);
        $this->authorize('delete', $device);

        $this->service->delete($device);

        return response()->json(null, 204);
    }

    public function restore(Request $request): DeviceResource
    {
        $id = $request->route('device');

        $device = Device::withTrashed()
            ->where('organization_id', $this->getOrganizationId())
            ->findOrFail($id);

        $this->authorize('restore', $device);

        $device = $this->service->restore($device);

        return new DeviceResource($device);
    }

    // =========================================================================
    //  Extra Actions
    // =========================================================================

    public function regeneratePairingCode(Request $request): DeviceResource
    {
        $device = Device::withTrashed()->findOrFail($request->route('device'));
        $this->authorizeOrganization($device);
        $this->authorize('update', $device);

        $device = $this->service->regeneratePairingCode($device);

        return new DeviceResource($device);
    }

    public function revoke(Request $request): DeviceResource
    {
        $device = $this->resolveDevice($request);
        $this->authorizeOrganization($device);
        $this->authorize('update', $device);

        $device = $this->service->revoke($device);

        return new DeviceResource($device);
    }

    // =========================================================================
    //  Signing keys (#1093 — offline-order evidence, 1/5)
    // =========================================================================

    /** List a device's Ed25519 signing keys, newest first (validity visible). */
    public function signingKeys(Request $request): JsonResponse
    {
        $device = $this->resolveDevice($request);
        $this->authorizeOrganization($device);
        $this->authorize('view', $device);

        $now = now();

        return response()->json([
            'data' => DeviceSigningKey::query()
                ->where('device_id', $device->id)
                ->orderByDesc('issued_at')
                ->get()
                ->map(fn (DeviceSigningKey $k): array => [
                    'id' => $k->id,
                    'public_key' => $k->public_key,
                    'issued_at' => $k->issued_at?->toISOString(),
                    'expires_at' => $k->expires_at?->toISOString(),
                    'revoked_at' => $k->revoked_at?->toISOString(),
                    'revoked_reason' => $k->revoked_reason,
                    'is_valid' => $k->revoked_at === null && $k->expires_at?->gt($now),
                ]),
        ]);
    }

    /**
     * Revoke ONE signing key (compromise response). Offline orders signed
     * with it will fail verification from this instant — fail-closed
     * (BR-DSK02). Idempotent.
     */
    public function revokeSigningKey(Request $request, DeviceSigningKeyService $keys): JsonResponse
    {
        $device = $this->resolveDevice($request);
        $this->authorizeOrganization($device);
        $this->authorize('update', $device);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $key = DeviceSigningKey::query()
            ->where('device_id', $device->id)
            ->findOrFail((string) $request->route('signingKey'));

        $key = $keys->revoke($key, $validated['reason']);

        return response()->json([
            'id' => $key->id,
            'revoked_at' => $key->revoked_at?->toISOString(),
            'revoked_reason' => $key->revoked_reason,
        ]);
    }

    // =========================================================================
    //  Lookup
    // =========================================================================

    public function lookup(): JsonResponse
    {
        $this->authorize('viewAny', Device::class);

        return response()->json([
            'data' => $this->service->lookup($this->getOrganizationId()),
        ]);
    }

    // =========================================================================
    //  Bulk Operations
    // =========================================================================

    protected function getModelClass(): string
    {
        return Device::class;
    }

    /**
     * Resolve the {device} route parameter to a Device model.
     */
    private function resolveDevice(Request $request): Device
    {
        $resolved = $request->route('device');

        return $resolved instanceof Device ? $resolved : Device::findOrFail($resolved);
    }
}
