<?php

namespace App\Http\Controllers\Api\V1\Workstation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workstation\WorkstationPeripheralDeviceStoreRequest;
use App\Http\Requests\Workstation\WorkstationPeripheralDeviceUpdateRequest;
use App\Http\Resources\PeripheralDeviceResource;
use App\Models\PeripheralDevice;
use App\Services\PeripheralDevice\PeripheralDeviceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /api/v1/workstation/peripheral-devices
 *
 * The workstation's own view of the peripheral registry for its paired branch:
 * a replica feed for sync-down (`index`) plus write access (`store`/`update`/
 * `destroy`) so an on-counter operator can register/edit a P400 or 釣銭機
 * without going through admin-web. Branch/organization are always taken from
 * the authed device token — a workstation can only manage its own branch.
 */
class PeripheralDeviceReplicaController extends Controller
{
    public function __construct(private readonly PeripheralDeviceService $service) {}

    public function index(Request $request): JsonResponse
    {
        $device = $request->attributes->get('device');
        $branchId = $device?->branch_id;
        $organizationId = $device?->organization_id;

        if (! $organizationId || ! $branchId) {
            return response()->json(['data' => []], 200);
        }

        $peripherals = PeripheralDevice::query()
            ->where('organization_id', $organizationId)
            ->where('branch_id', $branchId)
            ->orderBy('type')
            ->orderBy('name')
            ->get([
                'id', 'name', 'type', 'is_active', 'metadata',
                'registered_by_device_id', 'branch_id', 'organization_id',
                'created_at', 'updated_at',
            ]);

        return response()->json([
            'data' => PeripheralDeviceResource::collection($peripherals)->resolve($request),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * POST /api/v1/workstation/peripheral-devices — register a peripheral on the
     * workstation's own branch.
     */
    public function store(WorkstationPeripheralDeviceStoreRequest $request): JsonResponse
    {
        $device = $request->attributes->get('device');
        if (! $device?->branch_id || ! $device?->organization_id) {
            return response()->json(['message' => 'Device is not bound to a branch.'], Response::HTTP_CONFLICT);
        }

        [$peripheral, $wasCreated] = $this->service->upsertForBranch(array_merge($this->validatedWithFullMetadata($request), [
            'branch_id' => $device->branch_id,
            'organization_id' => $device->organization_id,
            'registered_by_device_id' => $device->id,
        ]));

        return (new PeripheralDeviceResource($peripheral))
            ->response()
            ->setStatusCode($wasCreated ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    /**
     * PUT /api/v1/workstation/peripheral-devices/{peripheral_device} — edit one
     * of the workstation branch's peripherals.
     */
    public function update(WorkstationPeripheralDeviceUpdateRequest $request, string $peripheralDevice): JsonResponse
    {
        $peripheral = $this->resolveForDevice($request, $peripheralDevice);

        $updated = $this->service->update($peripheral, $this->validatedWithFullMetadata($request));

        return (new PeripheralDeviceResource($updated))->response();
    }

    /**
     * DELETE /api/v1/workstation/peripheral-devices/{peripheral_device}.
     */
    public function destroy(Request $request, string $peripheralDevice): JsonResponse
    {
        $peripheral = $this->resolveForDevice($request, $peripheralDevice);

        $this->service->delete($peripheral);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * validated() plucks only the keys that have rules, which would strip
     * free-form metadata (e.g. a Glory cash changer's `model`). Keep the full
     * metadata array — host/port were already validated by their nested rules.
     *
     * @return array<string, mixed>
     */
    private function validatedWithFullMetadata(FormRequest $request): array
    {
        $data = $request->validated();
        if ($request->has('metadata')) {
            $data['metadata'] = $request->input('metadata');
        }

        return $data;
    }

    /**
     * Load a peripheral, enforcing that it belongs to the authed device's branch
     * and organization — a workstation may never touch another branch's rows
     * (404 rather than 403 so ids don't leak across branches).
     */
    private function resolveForDevice(Request $request, string $id): PeripheralDevice
    {
        $device = $request->attributes->get('device');

        return PeripheralDevice::query()
            ->where('organization_id', $device?->organization_id)
            ->where('branch_id', $device?->branch_id)
            ->findOrFail($id);
    }
}
