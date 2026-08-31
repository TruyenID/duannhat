<?php

namespace App\Http\Controllers\Api\V1\Tms;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceResource;
use App\Models\Device;
use App\Models\Table;
use App\Models\Zone;
use App\Omnify\Enums\TableStatusEnum;
use App\Services\Shop\TableStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TmsController extends Controller
{
    /**
     * GET /tms/me — device info + branch info.
     */
    public function me(Request $request): DeviceResource
    {
        $device = $this->getDevice($request);
        $device->load('branch');

        return new DeviceResource($device);
    }

    /**
     * GET /tms/zones — zones for this device's branch.
     */
    public function zones(Request $request): JsonResponse
    {
        $device = $this->getDevice($request);

        $zones = Zone::where('branch_id', $device->branch_id)
            ->where('is_active', true)
            ->select(['id', 'name', 'branch_id'])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $zones]);
    }

    /**
     * GET /tms/tables — tables for this device's branch with current status.
     */
    public function tables(Request $request): JsonResponse
    {
        $device = $this->getDevice($request);

        $tables = Table::where('branch_id', $device->branch_id)
            ->where('is_active', true)
            ->with('zone:id,name')
            ->select(['id', 'code', 'name', 'seat_count', 'status', 'paid_at', 'call_requested_at', 'zone_id', 'qr_token'])
            ->orderBy('code')
            ->get();

        return response()->json(['data' => $tables]);
    }

    /**
     * POST /tms/tables/{table}/status — change table status.
     */
    public function changeTableStatus(Request $request, string $table): JsonResponse
    {
        $device = $this->getDevice($request);

        $table = Table::where('branch_id', $device->branch_id)
            ->where('is_active', true)
            ->findOrFail($table);

        $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', TableStatusEnum::values())],
        ]);

        $newStatus = TableStatusEnum::from($request->input('status'));
        $currentStatus = $table->status;

        $this->validateStatusTransition($currentStatus, $newStatus);

        // #901 — a table still bound to an open order must not be freed:
        // Free-ing it orphans the order mid-service. This also covers the
        // Occupied → Cleaning → Free path, since the guard is on the target
        // status, not the edge. Close or move the order first.
        if ($newStatus === TableStatusEnum::Free && $table->current_order_id !== null) {
            throw ValidationException::withMessages([
                'status' => __('The table still has an open order. Close or move the order before freeing the table.'),
            ]);
        }

        // #1668 — this used to be two loose writes: `$table->update(...)` then
        // `$table->statusChanges()->create(...)`. A failure between them changed
        // the table and lost the history row, and `from_status` is only readable
        // BEFORE the write, so nothing could reconstruct it afterwards.
        //
        // `TableStatusService` already owned the correct shape — one transaction,
        // `lockForUpdate` (BR-T03), and the journal written through Ordering's
        // published port (#962) instead of straight into `table_status_changes`.
        // TMS was simply the one caller that bypassed it.
        //
        // The response still loads `zone:id,name` rather than the service's own
        // richer `fresh(...)`: this endpoint's payload is a contract with tms-app.
        app(TableStatusService::class)->changeStatus(
            $table,
            $newStatus->value,
            (string) $device->id,
        );

        return response()->json([
            'data' => $table->fresh(['zone:id,name']),
        ]);
    }

    /**
     * Validate that a table status transition is allowed.
     */
    private function validateStatusTransition(TableStatusEnum $from, TableStatusEnum $to): void
    {
        $allowed = [
            TableStatusEnum::Free->value => [
                TableStatusEnum::Occupied->value,
                TableStatusEnum::Reserved->value,
                TableStatusEnum::OutOfService->value,
            ],
            TableStatusEnum::Occupied->value => [
                TableStatusEnum::Cleaning->value,
                TableStatusEnum::Free->value,
            ],
            TableStatusEnum::Reserved->value => [
                TableStatusEnum::Occupied->value,
                TableStatusEnum::Free->value,
            ],
            TableStatusEnum::Cleaning->value => [
                TableStatusEnum::Free->value,
            ],
            TableStatusEnum::OutOfService->value => [
                TableStatusEnum::Free->value,
            ],
        ];

        $transitions = $allowed[$from->value] ?? [];

        if (! in_array($to->value, $transitions)) {
            throw ValidationException::withMessages([
                'status' => __("Cannot transition from ':from' to ':to'.", [
                    'from' => $from->label(),
                    'to' => $to->label(),
                ]),
            ]);
        }
    }

    /**
     * DELETE /tms/tables/{table}/call — clear the "staff called" flag.
     *
     * Customers raise a call via `POST /customer/tables/{qrToken}/call-staff`,
     * which sets `tables.call_requested_at = now()`. TMS staff acknowledge by
     * calling this endpoint; we reset the column to NULL. Returning 204 so the
     * TMS client can simply invalidate its cache without parsing a body.
     */
    public function clearCall(Request $request, string $table): JsonResponse
    {
        $device = $this->getDevice($request);

        $row = Table::where('branch_id', $device->branch_id)
            ->where('is_active', true)
            ->findOrFail($table);

        if ($row->call_requested_at !== null) {
            $row->update(['call_requested_at' => null]);
        }

        return response()->json(null, 204);
    }

    private function getDevice(Request $request): Device
    {
        return $request->attributes->get('device');
    }
}
