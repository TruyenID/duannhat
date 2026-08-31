<?php

namespace App\Services\Inventory;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SupplierYieldReportService
{
    /**
     * @return array{suppliers: list<array{supplier_name: string, lots_count: int, total_qty_consumed: string, total_actual_yield: string, yield_percent: float, variance_from_planned: float}>}
     */
    public function aggregate(string $brandId, array $dateRange, ?string $supplierName = null): array
    {
        $cacheKey = $this->cacheKey($brandId, $dateRange, $supplierName);

        return Cache::remember($cacheKey, 3600, function () use ($brandId, $dateRange, $supplierName) {
            return ['suppliers' => $this->query($brandId, $dateRange, $supplierName)];
        });
    }

    /**
     * @return list<array{supplier_name: string, lots_count: int, total_qty_consumed: string, total_actual_yield: string, yield_percent: float, variance_from_planned: float}>
     */
    private function query(string $brandId, array $dateRange, ?string $supplierName): array
    {
        $query = DB::table('material_lots as ml')
            ->join('genealogy_links as gl', 'gl.parent_lot_id', '=', 'ml.id')
            ->join('material_batches as mb', 'mb.output_lot_id', '=', 'gl.child_lot_id')
            ->where('ml.brand_id', $brandId)
            ->where('ml.source', 'inbound')
            ->whereNotNull('ml.supplier_name')
            ->whereBetween('gl.consumed_at', [
                Carbon::parse($dateRange['date_from'])->startOfDay(),
                Carbon::parse($dateRange['date_to'])->endOfDay(),
            ])
            ->whereNull('ml.deleted_at')
            ->whereNull('mb.deleted_at');

        if ($supplierName) {
            $query->where('ml.supplier_name', $supplierName);
        }

        $rows = $query
            ->groupBy('ml.supplier_name')
            ->select([
                'ml.supplier_name',
                DB::raw('COUNT(DISTINCT ml.id) as lots_count'),
                DB::raw('SUM(gl.qty_consumed) as total_qty_consumed'),
                DB::raw('SUM(mb.actual_yield) as total_actual_yield'),
                DB::raw('SUM(mb.planned_yield) as total_planned_yield'),
            ])
            ->orderBy('ml.supplier_name')
            ->get();

        return $rows->map(function ($row) {
            $consumed = (float) $row->total_qty_consumed;
            $actual = (float) $row->total_actual_yield;
            $planned = (float) $row->total_planned_yield;

            $yieldPercent = $consumed > 0 ? round($actual / $consumed * 100, 2) : 0;
            $plannedPercent = $consumed > 0 ? round($planned / $consumed * 100, 2) : 0;

            return [
                'supplier_name' => $row->supplier_name,
                'lots_count' => (int) $row->lots_count,
                'total_qty_consumed' => number_format($consumed, 4, '.', ''),
                'total_actual_yield' => number_format($actual, 4, '.', ''),
                'yield_percent' => $yieldPercent,
                'variance_from_planned' => round($yieldPercent - $plannedPercent, 2),
            ];
        })->all();
    }

    private function cacheKey(string $brandId, array $dateRange, ?string $supplierName): string
    {
        return 'supplier_yield:'.$brandId.':'.$dateRange['date_from'].':'.$dateRange['date_to'].':'.($supplierName ?? '*');
    }
}
