<?php

namespace App\Services\Inventory;

use App\Models\AuditLog;
use App\Models\MaterialLot;
use App\Models\Recall;
use App\Models\RecallAffectedOrder;
use App\Modules\Notifications\Contracts\NotificationDispatcher;
use App\Modules\Notifications\Contracts\NotificationRequest;
use App\Omnify\Enums\MaterialLotStatusEnum;
use App\Services\Inventory\Contracts\CustomerNotifiableDirectory;
use App\Services\Order\Contracts\OrderCustomerContacts;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * RecallService — plan-017 Tier 1.A.
 *
 * Walks the genealogy_links graph from a root lot to compute downstream
 * blast radius (descendants), auto-quarantines reachable lots, snapshots
 * affected customer_orders into recall_affected_orders, and tracks
 * lifecycle (draft → active → completed | cancelled).
 *
 * Notification dispatch (notify) lands when plan-008 channel routing is
 * wired. For v1 the method records notified_at + channel without
 * actually firing — the in-app notification platform will pick up
 * pending RecallAffectedOrder rows whose notified_at is null when the
 * plan-008 integration ships.
 */
class RecallService
{
    public function __construct(
        private readonly NotificationDispatcher $notifications,
        private readonly MaterialLotReservationService $materialLotReservationService,
        private readonly GenealogyWalker $walker,
        private readonly OrderCustomerContacts $orderContacts,
        // #962 — `customers` (CustomerEngagement) trên đường tìm người nhận
        // thông báo thu hồi.
        private readonly CustomerNotifiableDirectory $customerNotifiables,
    ) {}

    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Recall::query()
            ->with(['rootLot:id,lot_code', 'brand:id,name']);

        if (! empty($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }
        $query->when($filters['brand_id'] ?? null, fn ($q, $id) => $q->where('brand_id', $id));
        $query->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s));
        $query->when($filters['search'] ?? null, function ($q, $search) {
            $q->where(function ($q) use ($search) {
                $q->where('recall_code', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%");
            });
        });

        $sort = $filters['sort'] ?? '-initiated_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $query->orderBy(ltrim($sort, '-'), $direction);

        return $query->paginate($filters['per_page'] ?? 25);
    }

    public function findById(string $id): Recall
    {
        return Recall::with([
            'rootLot:id,lot_code,material_id',
            'brand:id,name,slug',
        ])->findOrFail($id);
    }

    /**
     * Preview without writing. Returns the affected lot + order counts the
     * UI shows in the confirm modal before committing the recall.
     *
     * plan-040 H7/TG.2: when $organizationId is supplied the root lot is
     * scoped to the caller's org — a lot owned by another org returns 404
     * (ModelNotFoundException) rather than leaking a cross-tenant blast radius.
     *
     * plan-040 W3: when $brandId is also supplied the root lot is additionally
     * scoped to that brand — a brand_admin can only preview a recall on a lot
     * belonging to their own brand (cross-brand-same-org root → 404). This is
     * the INITIATOR authz and is independent of the org-only walk below.
     *
     * @return array{root_lot: MaterialLot, affected_lot_ids: array<int, string>, affected_order_ids: array<int, string>}
     */
    public function preview(string $rootLotId, ?string $organizationId = null, ?string $brandId = null): array
    {
        $rootLot = $this->resolveRootLot($rootLotId, $organizationId, $brandId);

        // plan-040 TG.1/W5: single shared walker (DAG-safe, reversal/manual
        // filter). The walk is ORG-ONLY (no brand scope) so a recall blast radius
        // follows the material across brands within the org — replaces the
        // divergent local BFS that previously also pulled reversal children (C7).
        $affectedLotIds = $this->walker->descendantLotIds((string) $rootLot->id);
        $affectedLotIds[] = (string) $rootLot->id;
        $affectedLotIds = array_values(array_unique($affectedLotIds));

        $affectedOrderIds = $this->walker->affectedOrderIds($affectedLotIds);

        return [
            'root_lot' => $rootLot,
            'affected_lot_ids' => $affectedLotIds,
            'affected_order_ids' => $affectedOrderIds,
        ];
    }

    /**
     * Initiate a recall. Atomically:
     *   1. Walks genealogy descendants of root_lot
     *   2. UPDATEs every reachable lot's status to quarantined
     *   3. Snapshots affected customer_orders into recall_affected_orders
     *   4. Creates Recall row with status=active
     */
    public function initiate(array $data): Recall
    {
        return DB::transaction(function () use ($data) {
            // plan-040 H7/TG.2 + W3: org-scoped (always) + brand-scoped (when a
            // brand context is supplied) + locked root. A cross-org OR cross-brand
            // root_lot_id 404s here — a brand_admin can only recall a lot belonging
            // to their own brand — instead of quarantining another tenant's lot.
            $rootLot = MaterialLot::query()
                ->whereKey($data['root_lot_id'])
                ->where('organization_id', $data['organization_id'])
                ->when($data['brand_id'] ?? null, fn ($q, $brandId) => $q->where('brand_id', $brandId))
                ->lockForUpdate()
                ->firstOrFail();

            // plan-040 NEW-CON-5/TG.4/W5: walk the reachable set ORG-ONLY (a recall
            // follows material across brands within the org) inside the transaction.
            $affectedLotIds = $this->walker->descendantLotIds((string) $rootLot->id);
            $affectedLotIds[] = (string) $rootLot->id;
            $affectedLotIds = array_values(array_unique($affectedLotIds));

            // plan-040 W4 (NEW-CON-5 re-walk-in-lock TOCTOU): the first walk ran
            // before we held locks on the descendants, so a concurrent batch could
            // have minted a new grandchild off a mid-tree lot (mid-tree mints don't
            // contend on the root lock). Lock the known set, RE-WALK under the lock,
            // and lock any newly-discovered lots; loop until a re-walk adds nothing
            // (fixpoint) or we hit the bound. INVARIANT on exit: every lot reachable
            // from the root is row-locked, so no concurrent mint can slip an
            // un-quarantined descendant past us before this transaction commits.
            $maxRewalkRounds = 5;
            for ($round = 0; $round < $maxRewalkRounds; $round++) {
                MaterialLot::whereIn('id', $affectedLotIds)->lockForUpdate()->get(['id']);

                $rewalked = $this->walker->descendantLotIds((string) $rootLot->id);
                $rewalked[] = (string) $rootLot->id;
                $newlyDiscovered = array_diff($rewalked, $affectedLotIds);

                if ($newlyDiscovered === []) {
                    break; // fixpoint — the whole reachable set is already locked
                }

                $affectedLotIds = array_values(array_unique(array_merge($affectedLotIds, $rewalked)));
            }

            // Guarantee the final set is fully locked even if we exited on the bound.
            MaterialLot::whereIn('id', $affectedLotIds)->lockForUpdate()->get(['id']);

            $affectedOrderIds = $this->walker->affectedOrderIds($affectedLotIds);

            // plan-040 M11/TG.3: create the Recall FIRST so its id can tag the
            // quarantine snapshot. cancel() + generateReport() read back from THIS
            // snapshot (the tag), never from a re-walk of a graph that may drift.
            $recall = Recall::create([
                'recall_code' => $this->generateRecallCode($data['organization_id']),
                'root_lot_id' => $rootLot->id,
                'scope_type' => $data['scope_type'] ?? 'lot',
                'status' => 'active',
                'reason' => $data['reason'],
                'affected_lots_count' => count($affectedLotIds),
                'affected_orders_count' => count($affectedOrderIds),
                'initiated_by_id' => $data['initiated_by_id'] ?? null,
                'initiated_at' => now(),
                'organization_id' => $data['organization_id'],
                'brand_id' => $rootLot->brand_id,
            ]);

            // Auto-quarantine all reachable Active lots, tagged with this recall's
            // snapshot tag.
            MaterialLot::whereIn('id', $affectedLotIds)
                ->where('status', MaterialLotStatusEnum::Active->value)
                ->update([
                    'status' => MaterialLotStatusEnum::Quarantined->value,
                    'quarantine_reason' => $this->quarantineTag($recall),
                ]);

            // plan-040 M10 / NEW-LOT-3: a quarantined lot can no longer be drawn,
            // so its active reservations would orphan and keep subtracting from a
            // FEFO availability that is now zero. Release them across the whole
            // blast radius.
            foreach ($affectedLotIds as $lotId) {
                $this->materialLotReservationService->releaseAllForLot((string) $lotId);
            }

            // Snapshot affected orders into the pivot.
            foreach ($affectedOrderIds as $orderId) {
                RecallAffectedOrder::firstOrCreate(
                    [
                        'recall_id' => $recall->id,
                        'customer_order_id' => $orderId,
                        'notification_channel' => null,
                    ],
                    []
                );
            }

            $recall->logAudit('initiated', [
                'affected_lots' => count($affectedLotIds),
                'affected_orders' => count($affectedOrderIds),
                'affected_lot_ids' => $affectedLotIds,
            ]);

            return $recall->fresh()->load(['rootLot:id,lot_code']);
        });
    }

    /**
     * Mark recall as completed. Lots stay quarantined (or transition to
     * disposed via separate ops action); recall just gets a terminal
     * status flag + audit row.
     */
    public function complete(Recall $recall): Recall
    {
        return DB::transaction(function () use ($recall) {
            if ($recall->status->value !== 'active') {
                throw ValidationException::withMessages([
                    'status' => 'Only active recalls can be completed.',
                ]);
            }
            $recall->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
            $recall->logAudit('completed');

            return $recall->fresh();
        });
    }

    /**
     * Cancel a recall. Auto-quarantined lots are released back to active
     * (only those whose quarantine_reason still matches this recall —
     * lots that ops independently quarantined for another reason after
     * the recall fired stay quarantined).
     */
    public function cancel(Recall $recall, string $cancellationReason): Recall
    {
        return DB::transaction(function () use ($recall, $cancellationReason) {
            if (! in_array($recall->status->value, ['active', 'draft'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Only active or draft recalls can be cancelled.',
                ]);
            }

            // plan-040 M11/TG.3: target exactly the lots quarantined AT INITIATE
            // (identified by this recall's snapshot tag) — NOT a re-walk of the
            // live graph, which may have drifted (edges/lots added or removed)
            // since the recall fired and would otherwise strand quarantined lots.
            $ownedLotIds = MaterialLot::where('quarantine_reason', $this->quarantineTag($recall))
                ->where('status', MaterialLotStatusEnum::Quarantined->value)
                ->pluck('id')
                ->all();

            // plan-040 W6 (overlapping recalls): quarantine membership is a single
            // `quarantine_reason` string (no pivot — schema changes are blocked), so
            // a lot quarantined first by THIS recall was never re-tagged when a later
            // overlapping recall's blast radius also covered it (initiate only stamps
            // Active lots). Before flipping a lot back to Active, re-check every other
            // still-active/completed recall's INITIATE snapshot: if one still covers
            // the lot, hand the lot off (re-tag) to that recall instead of releasing
            // it. Reads each other recall's `affected_lot_ids` from its 'initiated'
            // audit row — the same snapshot cancel/report trust, never a drifting
            // re-walk.
            $coverage = $this->otherRecallCoverage($recall, $ownedLotIds);

            foreach ($ownedLotIds as $lotId) {
                if (isset($coverage[$lotId])) {
                    MaterialLot::whereKey($lotId)->update([
                        'quarantine_reason' => 'recall:'.$coverage[$lotId],
                    ]);
                } else {
                    MaterialLot::whereKey($lotId)->update([
                        'status' => MaterialLotStatusEnum::Active->value,
                        'quarantine_reason' => null,
                    ]);
                }
            }

            $recall->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => $cancellationReason,
            ]);
            $recall->logAudit('cancelled', ['reason' => $cancellationReason]);

            return $recall->fresh();
        });
    }

    /**
     * FSMA-204-shaped report payload. JSON only — PDF rendering deferred
     * to plan-018 per README out-of-scope list.
     *
     * @return array<string, mixed>
     */
    public function generateReport(Recall $recall): array
    {
        $rootLot = $recall->rootLot ?? MaterialLot::find($recall->root_lot_id);

        // plan-040 NEW-CON-7/TG.5: read the lot set AND the order set from the
        // SAME initiate snapshot — lots by this recall's quarantine tag, orders
        // from the recall_affected_orders pivot — so the two counts cannot drift
        // apart if the live genealogy graph changes after initiate.
        $affectedOrders = RecallAffectedOrder::where('recall_id', $recall->id)->get();
        $affectedLots = MaterialLot::where('quarantine_reason', $this->quarantineTag($recall))
            ->get(['id', 'lot_code', 'supplier_name', 'supplier_lot_code', 'received_at', 'expiry_date', 'status', 'qty_on_hand', 'unit']);

        return [
            'recall_code' => $recall->recall_code,
            'status' => $recall->status->value ?? $recall->status,
            'reason' => $recall->reason,
            'initiated_at' => $recall->initiated_at,
            'completed_at' => $recall->completed_at,
            'cancelled_at' => $recall->cancelled_at,
            'root_lot' => $rootLot ? [
                'id' => $rootLot->id,
                'lot_code' => $rootLot->lot_code,
                'supplier_name' => $rootLot->supplier_name,
                'supplier_lot_code' => $rootLot->supplier_lot_code,
                'received_at' => $rootLot->received_at,
                'expiry_date' => $rootLot->expiry_date,
            ] : null,
            'affected_lots' => $affectedLots->toArray(),
            'affected_orders' => $affectedOrders->toArray(),
            'counts' => [
                'lots' => $affectedLots->count(),
                'orders' => $affectedOrders->count(),
            ],
        ];
    }

    /**
     * Dispatch recall notification to the AFFECTED CUSTOMERS — the people whose
     * orders consumed the recalled material — NOT the org's staff. Recipients are
     * resolved from the recall_affected_orders snapshot (customer_order → customer),
     * so a customer is contacted exactly once even if several of their orders are
     * affected. The dispatched notification's id is stamped back onto every
     * affected-order row so the recall detail UI can show per-order send status and
     * link to the delivered notification.
     *
     * A dispatch failure PROPAGATES (rolls back the whole txn) instead of being
     * swallowed — the endpoint must not report success when nothing was sent.
     * Orders that carry no registered customer (guest/walk-in, customer_id NULL)
     * are marked processed with a null notification_id: there is nobody to notify.
     */
    public function notify(Recall $recall, ?string $channel = null): Recall
    {
        $rootLot = $recall->rootLot ?? MaterialLot::find($recall->root_lot_id);

        $params = [
            'lot_code' => $rootLot?->lot_code ?? '(unknown)',
            'material_name' => $rootLot?->material?->name ?? '(unknown)',
            'reason' => $recall->reason ?? '(unspecified)',
            'affected_orders_count' => $recall->affected_orders_count ?? 0,
            'recall_code' => $recall->recall_code,
            'initiated_by' => $recall->initiatedBy?->name ?? '(system)',
        ];

        return DB::transaction(function () use ($recall, $channel, $params) {
            $affectedOrders = RecallAffectedOrder::where('recall_id', $recall->id)
                ->whereNull('notified_at')
                ->get();

            // customer_id per affected order (null for guest/walk-in orders).
            $customerIdByOrderId = $this->orderContacts->customerIdsByOrderId(
                array_values(array_map(
                    static fn ($id): string => (string) $id,
                    $affectedOrders->pluck('customer_order_id')->all(),
                )),
            );

            $customerIds = collect($customerIdByOrderId)
                ->filter()
                ->unique()
                ->values();

            $recipients = $this->customerNotifiables->notifiablesForIds($customerIds->all());

            $notificationId = null;
            if ($recipients->isNotEmpty()) {
                // Failure here throws and rolls the transaction back — the caller
                // sees an error, never a false-positive 200.
                $notificationId = $this->notifications->toRecipients(
                    new NotificationRequest(
                        type: 'material_lot.recall_affected',
                        params: $params,
                        organizationId: (string) $recall->organization_id,
                        actor: $recall->initiatedBy,
                        subject: $recall,
                        idempotencyKey: "material_lot.recall_affected:{$recall->id}",
                    ),
                    $recipients,
                );
            }

            // Stamp each affected order: rows for orders with a registered customer
            // get the notification_id; guest orders are marked processed with null.
            foreach ($affectedOrders as $rao) {
                $hasCustomer = filled($customerIdByOrderId[$rao->customer_order_id] ?? null);
                $rao->update([
                    'notified_at' => now(),
                    'notification_channel' => $channel ?? 'in_app',
                    'notification_id' => $hasCustomer ? $notificationId : null,
                ]);
            }

            $recall->logAudit('notified', [
                'channel' => $channel ?? 'in_app',
                'notified_orders' => $affectedOrders->count(),
                'recipients' => $recipients->count(),
                'notification_id' => $notificationId,
            ]);

            return $recall->fresh();
        });
    }

    // =========================================================================
    //  Private helpers
    // =========================================================================

    /**
     * Resolve the root lot, org-scoped when a context org is supplied
     * (plan-040 H7/TG.2) and brand-scoped when a brand context is supplied
     * (plan-040 W3). Throws ModelNotFoundException (→ 404) when the lot does not
     * exist or belongs to another org/brand.
     */
    private function resolveRootLot(string $rootLotId, ?string $organizationId, ?string $brandId = null): MaterialLot
    {
        $query = MaterialLot::query()->whereKey($rootLotId);

        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }

        if ($brandId !== null) {
            $query->where('brand_id', $brandId);
        }

        return $query->firstOrFail();
    }

    /**
     * plan-040 W6: map of lot_id => other recall id that STILL covers the lot.
     * Reads each non-cancelled, non-this recall's initiate snapshot (the
     * `affected_lot_ids` logged to its 'initiated' audit row) and returns the
     * earliest other recall covering each lot, so cancel can hand a shared lot
     * off rather than releasing a still-covered lot back to Active.
     *
     * @param  array<int, string>  $lotIds
     * @return array<string, string>
     */
    private function otherRecallCoverage(Recall $recall, array $lotIds): array
    {
        if ($lotIds === []) {
            return [];
        }

        $otherRecallIds = Recall::where('organization_id', $recall->organization_id)
            ->whereKeyNot($recall->id)
            ->whereIn('status', ['active', 'completed'])
            ->orderBy('initiated_at')
            ->pluck('id')
            ->all();

        if ($otherRecallIds === []) {
            return [];
        }

        $lotSet = array_flip(array_map('strval', $lotIds));
        $coverage = [];
        $recallMorph = $recall->getMorphClass();

        foreach ($otherRecallIds as $otherId) {
            $metadata = AuditLog::where('auditable_type', $recallMorph)
                ->where('auditable_id', $otherId)
                ->where('action', 'initiated')
                ->latest('id')
                ->value('metadata');

            $affected = is_array($metadata) ? ($metadata['affected_lot_ids'] ?? []) : [];

            foreach ($affected as $lotId) {
                $lotId = (string) $lotId;
                if (isset($lotSet[$lotId]) && ! isset($coverage[$lotId])) {
                    $coverage[$lotId] = (string) $otherId;
                }
            }
        }

        return $coverage;
    }

    /**
     * The per-recall quarantine snapshot tag stamped onto every lot quarantined
     * at initiate (plan-040 M11/TG.3). Stable + unique per recall so cancel and
     * report can read the exact initiate-time lot set without re-walking.
     */
    private function quarantineTag(Recall $recall): string
    {
        return 'recall:'.$recall->id;
    }

    private function generateRecallCode(string $organizationId): string
    {
        $prefix = 'RC-'.now()->format('Ymd').'-';
        $last = Recall::where('organization_id', $organizationId)
            ->where('recall_code', 'like', "{$prefix}%")
            ->orderByDesc('recall_code')
            ->value('recall_code');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }
}
