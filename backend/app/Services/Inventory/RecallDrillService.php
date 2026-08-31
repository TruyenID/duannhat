<?php

namespace App\Services\Inventory;

use App\Models\MaterialLot;
use App\Models\RecallDrill;
use App\Services\Order\Contracts\OrderCustomerContacts;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RecallDrillService
{
    public function __construct(
        private readonly GenealogyWalker $walker,
        private readonly OrderCustomerContacts $orderContacts,
    ) {}

    public function run(string $brandId, string $organizationId, string $triggeredById, ?string $selectedLotId = null): RecallDrill
    {
        $startedAt = Carbon::now();

        $lot = $selectedLotId
            ? MaterialLot::where('brand_id', $brandId)->findOrFail($selectedLotId)
            : $this->autoPickLot($brandId);

        // plan-040 TG.1/NEW-CON-3: same shared walker (reversal/manual filtered,
        // brand-scoped) + same customer_order order filter as RecallService, so a
        // drill's blast radius is identical to a real recall's.
        $affectedLotIds = $this->walker->descendantLotIds((string) $lot->id);
        array_unshift($affectedLotIds, (string) $lot->id);
        $affectedLotIds = array_values(array_unique($affectedLotIds));
        $affectedLotsAt = Carbon::now();

        $affectedOrderIds = $this->walker->affectedOrderIds($affectedLotIds);
        $affectedOrdersAt = Carbon::now();

        $completenessPercent = $this->computeCompleteness($affectedOrderIds);
        $completedAt = Carbon::now();

        $elapsedSeconds = (int) $startedAt->diffInSeconds($completedAt);

        return RecallDrill::create([
            'triggered_by_id' => $triggeredById,
            'selected_lot_id' => $lot->id,
            'started_at' => $startedAt,
            'affected_lots_at' => $affectedLotsAt,
            'affected_orders_at' => $affectedOrdersAt,
            'completed_at' => $completedAt,
            'elapsed_seconds' => $elapsedSeconds,
            'affected_lots_count' => count($affectedLotIds),
            'affected_orders_count' => count($affectedOrderIds),
            'completeness_percent' => $completenessPercent,
            'organization_id' => $organizationId,
            'brand_id' => $brandId,
        ]);
    }

    /**
     * @return array<string>
     */
    public function list(string $brandId): array
    {
        return RecallDrill::where('brand_id', $brandId)
            ->orderByDesc('started_at')
            ->get()
            ->all();
    }

    private function autoPickLot(string $brandId): MaterialLot
    {
        $lot = MaterialLot::where('brand_id', $brandId)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('genealogy_links')
                    ->whereColumn('genealogy_links.parent_lot_id', 'material_lots.id')
                    ->where('genealogy_links.consumed_at', '>=', now()->subDays(7));
            })
            ->inRandomOrder()
            ->first();

        if (! $lot) {
            $lot = MaterialLot::where('brand_id', $brandId)
                ->where('status', 'active')
                ->inRandomOrder()
                ->firstOrFail();
        }

        return $lot;
    }

    /**
     * Tỉ lệ đơn bị ảnh hưởng mà cửa hàng CÓ THỂ liên lạc được — chỉ số chính của
     * một buổi diễn tập thu hồi. Phép chia ở lại đây (Inventory sở hữu chỉ số);
     * "đơn nào liên lạc được" hỏi Ordering qua {@see OrderCustomerContacts}.
     *
     * @param  array<string>  $orderIds
     */
    private function computeCompleteness(array $orderIds): float
    {
        if (empty($orderIds)) {
            return 0.0;
        }

        $withContact = count($this->orderContacts->orderIdsWithReachableContact(array_values($orderIds)));

        return round(($withContact / count($orderIds)) * 100, 2);
    }
}
