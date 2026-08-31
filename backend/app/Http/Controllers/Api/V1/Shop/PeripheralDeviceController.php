<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\PeripheralDeviceStoreRequest;
use App\Http\Requests\PeripheralDeviceUpdateRequest;
use App\Http\Resources\PeripheralDeviceResource;
use App\Models\Branch;
use App\Models\PeripheralDevice;
use App\Services\PeripheralDevice\PeripheralDeviceService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PeripheralDeviceController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly PeripheralDeviceService $service,
    ) {}

    // =========================================================================
    //  CRUD
    // =========================================================================

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PeripheralDevice::class);

        $devices = $this->service->list([
            'organization_id' => $this->getOrganizationId(),
            'branch_id' => $this->resolvedShopId($request),
            'search' => $request->input('search'),
            'with_trashed' => $request->boolean('with_trashed', false),
            'type' => $request->input('type'),
            'sort' => $request->input('sort', '-created_at'),
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return PeripheralDeviceResource::collection($devices);
    }

    public function store(PeripheralDeviceStoreRequest $request): JsonResponse
    {
        $this->authorize('create', PeripheralDevice::class);

        $shop = $this->resolvedShop($request);

        $data = $request->validated();
        // Keep the full free-form metadata (host/port already validated) so extra
        // keys like a Glory cash changer's `model` aren't stripped by validated().
        if ($request->has('metadata')) {
            $data['metadata'] = $request->input('metadata');
        }
        $data['organization_id'] = $this->getOrganizationId();
        $data['branch_id'] = $shop->id;

        $device = $this->service->create($data);

        return (new PeripheralDeviceResource($device))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request): PeripheralDeviceResource
    {
        $device = $this->resolvePeripheralDevice($request);
        $this->authorize('view', $device);

        return new PeripheralDeviceResource(
            $this->service->findById($device->id)
        );
    }

    public function update(PeripheralDeviceUpdateRequest $request): PeripheralDeviceResource
    {
        $device = $this->resolvePeripheralDevice($request);
        $this->authorize('update', $device);

        $data = $request->validated();
        if ($request->has('metadata')) {
            $data['metadata'] = $request->input('metadata');
        }

        return new PeripheralDeviceResource(
            $this->service->update($device, $data)
        );
    }

    public function destroy(Request $request): JsonResponse
    {
        $device = $this->resolvePeripheralDevice($request);
        $this->authorize('delete', $device);

        $this->service->delete($device);

        return response()->json(null, 204);
    }

    public function restore(Request $request): PeripheralDeviceResource
    {
        $shop = $this->resolvedShop($request);
        $id = (string) $request->route('peripheral_device');

        $device = PeripheralDevice::withTrashed()
            ->where('organization_id', $this->getOrganizationId())
            ->where('branch_id', $shop->id)
            ->findOrFail($id);

        $this->authorize('restore', $device);

        return new PeripheralDeviceResource($this->service->restore($device));
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

    private function resolvePeripheralDevice(Request $request): PeripheralDevice
    {
        $shop = $this->resolvedShop($request);
        $id = (string) $request->route('peripheral_device');

        return PeripheralDevice::query()
            ->where('organization_id', $this->getOrganizationId())
            ->where('branch_id', $shop->id)
            ->findOrFail($id);
    }
}
