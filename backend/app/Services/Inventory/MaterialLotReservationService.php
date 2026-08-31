<?php

namespace App\Services\Inventory;

use App\Models\MaterialLot;
use App\Models\MaterialLotReservation;
use App\Omnify\Enums\MaterialLotStatusEnum;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaterialLotReservationService
{
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = MaterialLotReservation::query()
            ->with(['materialLot:id,lot_code,material_id', 'reservedBy:id,name']);

        if (! empty($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }
        $query->when($filters['material_lot_id'] ?? null, fn ($q, $id) => $q->where('material_lot_id', $id));
        $query->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s));
        $query->when($filters['material_batch_id'] ?? null, fn ($q, $id) => $q->where('material_batch_id', $id));

        return $query->orderByDesc('created_at')->paginate($filters['per_page'] ?? 25);
    }

    public function findById(string $id): MaterialLotReservation
    {
        return MaterialLotReservation::with(['materialLot:id,lot_code,material_id', 'reservedBy:id,name'])
            ->findOrFail($id);
    }

    public function reserve(array $data): MaterialLotReservation
    {
        return DB::transaction(function () use ($data) {
            $lot = MaterialLot::lockForUpdate()->findOrFail($data['material_lot_id']);

            if ($lot->status !== MaterialLotStatusEnum::Active->value && $lot->status?->value !== MaterialLotStatusEnum::Active->value) {
                throw ValidationException::withMessages([
                    'material_lot_id' => 'Only active lots can be reserved.',
                ]);
            }

            $requestedQty = (float) $data['qty_reserved'];

            if ($requestedQty <= 0) {
                throw ValidationException::withMessages([
                    'qty_reserved' => 'Reservation quantity must be greater than zero.',
                ]);
            }

            // plan-040 H13: over-reservation guard. There is no DB constraint that
            // can express "Σ(active reservations) ≤ qty_on_hand", so it is enforced
            // here under the lot's lockForUpdate — two concurrent reserve() calls on
            // the same lot serialize on that lock, so the active-reservation SUM each
            // reads is consistent and the combined total can never exceed on-hand.
            $availableQty = $this->computeAvailableQty($lot);

            if ($requestedQty > $availableQty) {
                throw ValidationException::withMessages([
                    'qty_reserved' => "Insufficient available quantity. Available: {$availableQty}, requested: {$requestedQty}.",
                ]);
            }

            return MaterialLotReservation::create([
                'material_lot_id' => $lot->id,
                'qty_reserved' => $requestedQty,
                'reserved_by_id' => $data['reserved_by_id'],
                'expected_consume_at' => $data['expected_consume_at'] ?? null,
                'material_batch_id' => $data['material_batch_id'] ?? null,
                'status' => 'active',
                'reason' => $data['reason'] ?? null,
                'organization_id' => $lot->organization_id,
            ]);
        });
    }

    public function release(MaterialLotReservation $reservation): MaterialLotReservation
    {
        return DB::transaction(function () use ($reservation) {
            $locked = MaterialLotReservation::lockForUpdate()->findOrFail($reservation->id);

            if ($locked->status === 'cancelled') {
                return $locked;
            }

            if ($locked->status !== 'active') {
                throw ValidationException::withMessages([
                    'status' => 'Only active reservations can be released.',
                ]);
            }

            $locked->update(['status' => 'cancelled']);

            return $locked->fresh();
        });
    }

    public function consume(MaterialLotReservation $reservation): MaterialLotReservation
    {
        return DB::transaction(function () use ($reservation) {
            $locked = MaterialLotReservation::lockForUpdate()->findOrFail($reservation->id);

            if ($locked->status !== 'active') {
                throw ValidationException::withMessages([
                    'status' => 'Only active reservations can be consumed.',
                ]);
            }

            $locked->update(['status' => 'consumed']);

            return $locked->fresh();
        });
    }

    public function renew(MaterialLotReservation $reservation, ?string $newExpectedConsumeAt = null): MaterialLotReservation
    {
        return DB::transaction(function () use ($reservation, $newExpectedConsumeAt) {
            // plan-040 W8: lock the LOT first, THEN the reservation row, to match
            // the global `lot → reservation` lock order used everywhere else
            // (consumeForLot, releaseAllForLot, recall initiate). Inverting it
            // (reservation-then-lot, the previous order here) could deadlock against
            // a concurrent sale that locks lot-then-reservation on the same lot.
            $lot = MaterialLot::lockForUpdate()->findOrFail($reservation->material_lot_id);
            $locked = MaterialLotReservation::lockForUpdate()->findOrFail($reservation->id);

            if (! in_array($locked->status, ['active', 'expired'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Only active or expired reservations can be renewed.',
                ]);
            }

            // plan-040 NEW-LOT-2: re-check availability before reactivating an
            // expired reservation. While it sat expired its quantity was freed
            // back into the FEFO pool, so other reservations may have claimed it.
            // Reactivating without a re-check could push Σ(active) past qty_on_hand
            // and strand the lot. An already-active reservation is a no-op renew
            // (just extends the date), so it is exempt from the availability math.
            if ($locked->status === 'expired') {
                $availableQty = $this->computeAvailableQty($lot);
                $requestedQty = (float) $locked->qty_reserved;

                if ($requestedQty > $availableQty) {
                    throw ValidationException::withMessages([
                        'qty_reserved' => "Cannot renew: insufficient available quantity. Available: {$availableQty}, reservation: {$requestedQty}.",
                    ]);
                }
            }

            $locked->update([
                'status' => 'active',
                'expected_consume_at' => $newExpectedConsumeAt ?? now()->addDays(7),
            ]);

            return $locked->fresh();
        });
    }

    /**
     * plan-040 H3a: draw down active reservations on a lot when that lot is
     * actually consumed by the real consumption path. Without this, `consume()`
     * existed but was never called — reservations stayed `active` forever and
     * permanently subtracted from FEFO availability even after the goods left.
     *
     * Reservations on the lot are consumed oldest-first (by created_at) up to
     * `$qtyConsumed`. A reservation fully covered by the consumption flips to
     * `consumed`; a partially covered one has its `qty_reserved` decremented and
     * stays `active`. The caller already holds the lot row lock inside the
     * consumption transaction, so the reservation rows are locked here too to
     * serialize against concurrent reserve()/consume().
     */
    public function consumeForLot(string $materialLotId, float $qtyConsumed): void
    {
        if ($qtyConsumed <= 0) {
            return;
        }

        $remaining = $qtyConsumed;

        $reservations = MaterialLotReservation::where('material_lot_id', $materialLotId)
            ->where('status', 'active')
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($reservations as $reservation) {
            if ($remaining <= 0) {
                break;
            }

            $reservedQty = (float) $reservation->qty_reserved;
            $take = min($reservedQty, $remaining);

            if ($take >= $reservedQty) {
                $reservation->update(['status' => 'consumed']);
            } else {
                $reservation->update(['qty_reserved' => $reservedQty - $take]);
            }

            $remaining -= $take;
        }
    }

    /**
     * plan-040 NEW-LOT-3 / M10: cancel every active reservation on a lot when
     * the lot leaves the active pool (disposed, or quarantined by a recall).
     * Leaving them active would strand them as orphans on a dead lot and keep
     * subtracting from a FEFO availability that no longer exists. Returns the
     * number of reservations cancelled.
     */
    public function releaseAllForLot(string $materialLotId): int
    {
        return DB::transaction(function () use ($materialLotId) {
            $reservations = MaterialLotReservation::where('material_lot_id', $materialLotId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->get();

            foreach ($reservations as $reservation) {
                $reservation->update(['status' => 'cancelled']);
            }

            return $reservations->count();
        });
    }

    public function expireStale(): int
    {
        $ttlCutoff = now()->subDays(7);

        // plan-040 H3b/M16: reservations with expected_consume_at = NULL must
        // still expire — fall back to created_at + TTL as the expiry basis.
        // Previously the whereNotNull guard meant null-date reservations never
        // expired and permanently subtracted from FEFO availability.
        return MaterialLotReservation::where('status', 'active')
            ->where(function ($query) use ($ttlCutoff) {
                $query->where(function ($q) use ($ttlCutoff) {
                    $q->whereNotNull('expected_consume_at')
                        ->where('expected_consume_at', '<', $ttlCutoff);
                })->orWhere(function ($q) use ($ttlCutoff) {
                    $q->whereNull('expected_consume_at')
                        ->where('created_at', '<', $ttlCutoff);
                });
            })
            ->update(['status' => 'expired']);
    }

    public function computeAvailableQty(MaterialLot $lot): float
    {
        $totalReserved = MaterialLotReservation::where('material_lot_id', $lot->id)
            ->where('status', 'active')
            ->sum('qty_reserved');

        return max(0, (float) $lot->qty_on_hand - (float) $totalReserved);
    }
}
