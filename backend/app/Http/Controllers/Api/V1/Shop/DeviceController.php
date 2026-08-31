<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\DeviceStoreRequest;
use App\Http\Requests\DeviceUpdateRequest;
use App\Http\Resources\DeviceResource;
use App\Models\Branch;
use App\Models\Device;
use App\Services\Device\DeviceService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DeviceController extends Controller
{
    use AuthorizesRequests;
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
            'branch_id' => $this->resolvedShopId($request),
            'search' => $request->input('search'),
            'status' => $request->input('status'),
            'type' => $request->input('type'),
            'with_trashed' => $request->boolean('with_trashed', false),
            'sort' => $request->input('sort', '-created_at'),
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return DeviceResource::collection($devices);
    }

    public function store(DeviceStoreRequest $request): JsonResponse
    {
        $this->authorize('create', Device::class);

        $shop = $this->resolvedShop($request);

        $data = $request->validated();
        $data['organization_id'] = $this->getOrganizationId();
        $data['branch_id'] = $shop->id;

        $device = $this->service->create($data);

        return (new DeviceResource($device))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request): DeviceResource
    {
        $device = $this->resolveDevice($request);
        $this->authorize('view', $device);

        return new DeviceResource(
            $this->service->findById($device->id)
        );
    }

    public function update(DeviceUpdateRequest $request): DeviceResource
    {
        $device = $this->resolveDevice($request);
        $this->authorize('update', $device);

        return new DeviceResource(
            $this->service->update($device, $request->validated())
        );
    }

    public function destroy(Request $request): JsonResponse
    {
        $device = $this->resolveDevice($request);
        $this->authorize('delete', $device);

        $this->service->delete($device);

        return response()->json(null, 204);
    }

    public function restore(Request $request): DeviceResource
    {
        $shop = $this->resolvedShop($request);
        $id = (string) $request->route('device');

        $device = Device::withTrashed()
            ->where('organization_id', $this->getOrganizationId())
            ->where('branch_id', $shop->id)
            ->findOrFail($id);

        $this->authorize('restore', $device);

        return new DeviceResource($this->service->restore($device));
    }

    // =========================================================================
    //  Extra Actions
    // =========================================================================

    public function regeneratePairingCode(Request $request): DeviceResource
    {
        $device = $this->resolveDevice($request);
        $this->authorize('update', $device);

        return new DeviceResource(
            $this->service->regeneratePairingCode($device)
        );
    }

    public function revoke(Request $request): DeviceResource
    {
        $device = $this->resolveDevice($request);
        $this->authorize('update', $device);

        return new DeviceResource($this->service->revoke($device));
    }

    // =========================================================================
    //  Helpers
    // =========================================================================

    private function resolvedShop(Request $request): Branch
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');

        return $shop;
    }

    private function resolvedShopId(Request $request): string
    {
        return $request->attributes->get('shop_id');
    }

    private function resolveDevice(Request $request): Device
    {
        $shop = $this->resolvedShop($request);
        $id = (string) $request->route('device');

        return Device::where('organization_id', $this->getOrganizationId())
            ->where('branch_id', $shop->id)
            ->findOrFail($id);
    }
}
