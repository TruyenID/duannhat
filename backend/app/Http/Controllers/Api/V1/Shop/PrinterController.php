<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\PrinterStoreRequest;
use App\Http\Requests\PrinterUpdateRequest;
use App\Http\Resources\PrinterResource;
use App\Models\Branch;
use App\Models\Printer;
use App\Omnify\Enums\PrinterRoleEnum;
use App\Services\Device\PrinterService;
use App\Services\Printing\Enums\PrintTransport;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Shop-scoped ESC/POS printer configuration.
 *
 * Cloud owns the CONFIG (name / roles / LAN address); the workstation owns the
 * ACT of printing. The workstation pulls this list, caches it locally, and
 * keeps printing even when Cloud is unreachable — see
 * plan-cloud-first-workstation (đã xoá #2188 — xem issue #2210, git history).
 */
class PrinterController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly PrinterService $service,
    ) {}

    // =========================================================================
    //  CRUD
    // =========================================================================

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Printer::class);

        $printers = $this->service->list([
            'organization_id' => $this->getOrganizationId(),
            'branch_id' => $this->resolvedShopId($request),
            'search' => $request->input('search'),
            'role' => $request->input('role'),
            'connection_type' => $request->input('connection_type'),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
            'with_trashed' => $request->boolean('with_trashed', false),
            'sort' => $request->input('sort', '-created_at'),
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return PrinterResource::collection($printers)->additional([
            'meta' => [
                'available_roles' => PrinterRoleEnum::values(),
            ],
        ]);
    }

    public function store(PrinterStoreRequest $request): JsonResponse
    {
        $this->authorize('create', Printer::class);

        $shop = $this->resolvedShop($request);

        $data = $request->validated();
        $data['organization_id'] = $this->getOrganizationId();
        $data['branch_id'] = $shop->id;

        $printer = $this->service->create($data);

        return (new PrinterResource($printer))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request): PrinterResource
    {
        $printer = $this->resolvePrinter($request);
        $this->authorize('view', $printer);

        return new PrinterResource(
            $this->service->findById($printer->id)
        );
    }

    public function update(PrinterUpdateRequest $request): PrinterResource
    {
        $printer = $this->resolvePrinter($request);
        $this->authorize('update', $printer);

        return new PrinterResource(
            $this->service->update($printer, $request->validated())
        );
    }

    /**
     * Mint a fresh `print_token` for a CloudPRNT printer and reveal it once.
     *
     * plan-053 T5.4 follow-up (#1171). Without this a shop is stuck: the token
     * is shown exactly once, at the write that minted it (P-16), and nothing
     * could ever show it again. So a machine that dies, gets swapped, or has its
     * taped-on card lost has no path back — the printer polls with a token
     * nobody can read, and the only recovery is deleting the printer row and
     * losing its history.
     *
     * ## Rotation is IMMEDIATE and that is the point
     *
     * `CloudPrntService` resolves a printer by exact token match, so the old
     * value 401s from the next poll onward. That is the behaviour a rotation is
     * *for*: the reasons to rotate are a stolen card or a machine leaving the
     * building, and a grace window would keep serving whoever took it. It costs
     * one in-flight job at most — a slip already fetched under the old token
     * cannot be confirmed, so it ages out through the normal `delivering` sweep
     * rather than being marked printed on a guess.
     *
     * ## 422 on a non-CloudPRNT printer, rather than minting anyway
     *
     * A `ws_lan` printer has no token because nothing authenticates to Cloud on
     * its behalf. Minting one would hand the operator a credential that grants
     * nothing, and the natural reading of a returned token is that it works.
     */
    public function rotatePrintToken(Request $request): PrinterResource|JsonResponse
    {
        $printer = $this->resolvePrinter($request);

        // Same ability as editing the printer: rotating is a configuration act
        // on a row the caller may already rewrite wholesale. A bespoke ability
        // here would be a second answer to a question `update` already settles.
        $this->authorize('update', $printer);

        if ($printer->transport !== PrintTransport::CloudPrnt) {
            return response()->json([
                'message' => 'Only a CloudPRNT printer has a print token to rotate.',
                'errors' => [
                    'transport' => [sprintf(
                        'This printer uses `%s`, which authenticates nothing against Cloud.',
                        $printer->transport?->value ?? 'ws_lan',
                    )],
                ],
            ], 422);
        }

        // Clearing it is what arms the model's `saving` hook — it mints only
        // when the column is empty, so assigning null here is the whole
        // rotation. Re-implementing the mint at this layer would give the
        // secret two birthplaces to drift apart.
        $printer->print_token = null;
        $printer->save();

        return new PrinterResource($printer);
    }

    public function destroy(Request $request): JsonResponse
    {
        $printer = $this->resolvePrinter($request);
        $this->authorize('delete', $printer);

        $this->service->delete($printer);

        return response()->json(null, 204);
    }

    public function restore(Request $request): PrinterResource
    {
        $shop = $this->resolvedShop($request);
        $id = (string) $request->route('printer');

        $printer = Printer::withTrashed()
            ->where('organization_id', $this->getOrganizationId())
            ->where('branch_id', $shop->id)
            ->findOrFail($id);

        $this->authorize('restore', $printer);

        return new PrinterResource($this->service->restore($printer));
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

    private function resolvePrinter(Request $request): Printer
    {
        $shop = $this->resolvedShop($request);
        $id = (string) $request->route('printer');

        return Printer::where('organization_id', $this->getOrganizationId())
            ->where('branch_id', $shop->id)
            ->findOrFail($id);
    }
}
