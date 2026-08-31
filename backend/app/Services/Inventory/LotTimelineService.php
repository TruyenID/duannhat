<?php

namespace App\Services\Inventory;

use App\Models\AuditLog;
use App\Models\ExpiryAlert;
use App\Models\GenealogyLink;
use App\Models\MaterialLot;
use App\Models\Recall;
use App\Models\StockMovement;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class LotTimelineService
{
    /**
     * @param  array{types?: string[], per_page?: int, page?: int}  $filters
     */
    public function timeline(MaterialLot $lot, array $filters = []): LengthAwarePaginator
    {
        $allowedTypes = ['audit', 'movement', 'genealogy', 'expiry_alert', 'recall'];
        $types = ! empty($filters['types'])
            ? array_intersect($filters['types'], $allowedTypes)
            : $allowedTypes;

        $perPage = min($filters['per_page'] ?? 50, 100);
        $page = $filters['page'] ?? 1;

        $events = new Collection;

        if (in_array('audit', $types)) {
            $events = $events->concat($this->auditEvents($lot));
        }

        if (in_array('movement', $types)) {
            $events = $events->concat($this->movementEvents($lot));
        }

        if (in_array('genealogy', $types)) {
            $events = $events->concat($this->genealogyEvents($lot));
        }

        if (in_array('expiry_alert', $types)) {
            $events = $events->concat($this->expiryAlertEvents($lot));
        }

        if (in_array('recall', $types)) {
            $events = $events->concat($this->recallEvents($lot));
        }

        $sorted = $events->sortByDesc('occurred_at')->values();
        $total = $sorted->count();
        $slice = $sorted->forPage($page, $perPage)->values();

        return new LengthAwarePaginator($slice, $total, $perPage, $page, [
            'path' => request()?->url(),
        ]);
    }

    private function auditEvents(MaterialLot $lot): Collection
    {
        return AuditLog::where('auditable_type', MaterialLot::class)
            ->where('auditable_id', $lot->id)
            ->get()
            ->map(fn (AuditLog $log) => [
                'type' => 'audit',
                'occurred_at' => $log->created_at,
                'id' => $log->id,
                'action' => $log->action,
                'user_id' => $log->user_id,
                'details' => $log->metadata,
            ]);
    }

    private function movementEvents(MaterialLot $lot): Collection
    {
        return StockMovement::where('material_lot_id', $lot->id)
            ->get()
            ->map(fn (StockMovement $m) => [
                'type' => 'movement',
                'occurred_at' => $m->created_at,
                'id' => $m->id,
                'movement_type' => $m->movement_type,
                'quantity' => $m->quantity,
                'warehouse_id' => $m->warehouse_id,
            ]);
    }

    private function genealogyEvents(MaterialLot $lot): Collection
    {
        return GenealogyLink::where('parent_lot_id', $lot->id)
            ->orWhere('child_lot_id', $lot->id)
            ->get()
            ->map(fn (GenealogyLink $g) => [
                'type' => 'genealogy',
                'occurred_at' => $g->created_at,
                'id' => $g->id,
                'parent_lot_id' => $g->parent_lot_id,
                'child_lot_id' => $g->child_lot_id,
                'source_event_type' => $g->source_event_type,
            ]);
    }

    private function expiryAlertEvents(MaterialLot $lot): Collection
    {
        return ExpiryAlert::where('material_lot_id', $lot->id)
            ->get()
            ->map(fn (ExpiryAlert $a) => [
                'type' => 'expiry_alert',
                'occurred_at' => $a->fired_at,
                'id' => $a->id,
                'threshold_days' => $a->threshold_days,
            ]);
    }

    private function recallEvents(MaterialLot $lot): Collection
    {
        return Recall::where('root_lot_id', $lot->id)
            ->get()
            ->map(fn (Recall $r) => [
                'type' => 'recall',
                'occurred_at' => $r->created_at,
                'id' => $r->id,
                'status' => $r->status,
                'reason' => $r->reason,
            ]);
    }
}
