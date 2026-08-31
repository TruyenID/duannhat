<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Models\Branch;
use App\Models\PeripheralDevice;
use App\Services\Payment\Terminal\StripeTerminalService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * #1088 — Stripe Terminal reader registry (shop scope, SSO).
 *
 * Registers smart readers by the code shown on the device screen and lists
 * them with live Stripe status. Reader rows live as PeripheralDevice
 * payment_terminal entries (metadata.provider = stripe_terminal) so the
 * existing peripheral tooling sees them; charge/cancel is on the POS surface.
 */
class StripeTerminalReaderController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly StripeTerminalService $terminal,
    ) {}

    private function resolvedShop(Request $request): Branch
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');

        return $shop;
    }

    public function index(Request $request): JsonResponse
    {
        StripeTerminalService::abortUnlessEnabled();
        $this->authorize('viewAny', PeripheralDevice::class);

        $shop = $this->resolvedShop($request);

        return response()->json(['data' => [
            'readers' => $this->terminal->listReaders($shop, $request->boolean('live', true)),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        StripeTerminalService::abortUnlessEnabled();
        $this->authorize('create', PeripheralDevice::class);

        $validated = $request->validate([
            'registration_code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:100'],
        ]);

        $shop = $this->resolvedShop($request);

        $row = $this->terminal->registerReader($shop, $validated['registration_code'], $validated['name']);

        return response()->json(['data' => [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'stripe_reader_id' => (string) data_get($row->metadata, 'stripe_reader_id'),
            'device_type' => (string) data_get($row->metadata, 'device_type'),
            'stripe_location_id' => (string) data_get($row->metadata, 'stripe_location_id'),
        ]], 201);
    }
}
