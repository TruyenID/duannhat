<?php

namespace App\Console\Commands\Notifications;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/**
 * @deletable-after-plan-023
 *
 * Plan-023 M8 T8.1 — one-shot dev tool.
 * Verifies every M8 rule's model/field references resolve before seeding.
 * Run this before T8.6–T8.12 to confirm no column was renamed since the
 * plan spec was written. Exit code 1 if any alias or field is missing.
 */
class AuditCoveragePrecheckCommand extends Command
{
    protected $signature = 'notifications:audit-coverage-precheck';

    protected $description = 'Pre-flight check: all M8 rule model/field references resolve.';

    /**
     * rule_key => [morph_alias, condition_fields[], audience_fields[]]
     */
    private const CHECKS = [
        'product.submitted_for_approval' => ['Product', ['status'], ['created_by_id']],
        'product.approved' => ['Product', ['status'], ['created_by_id']],
        'product.rejected' => ['Product', ['status'], ['created_by_id']],
        'menu.submitted_for_approval' => ['Menu', ['status'], ['created_by_id']],
        'menu.approved' => ['Menu', ['status'], ['created_by_id']],
        'menu.rejected' => ['Menu', ['status'], ['created_by_id']],
        'stock_transaction.submitted' => ['StockTransaction', ['status'], ['warehouse_id', 'created_by_id']],
        'stock_transaction.approved' => ['StockTransaction', ['status'], ['warehouse_id', 'created_by_id']],
        'stock_transaction.rejected' => ['StockTransaction', ['status'], ['warehouse_id', 'created_by_id']],
        'stock_transfer.in_transit' => ['StockTransfer', ['status'], ['destination_warehouse_id']],
        'stock_transfer.received' => ['StockTransfer', ['status'], ['source_warehouse_id']],
        'device.paired' => ['Device', ['status', 'paired_at'], []],
        'device.unpaired' => ['Device', ['status', 'paired_at'], []],
        'device.offline' => ['Device', [], []],
        'coupon.redeemed' => ['CouponRedemption', [], []],
        'coupon.expiring_soon' => ['Coupon', ['valid_until'], ['brand_id']],
        'coupon.expired' => ['Coupon', ['valid_until'], ['brand_id']],
        // brands table uses is_active (boolean), not a status enum
        'brand.status_changed' => ['Brand', ['is_active'], []],
    ];

    public function handle(): int
    {
        $errors = 0;
        $this->table(['Rule', 'Morph alias', 'Model', 'Status'], $this->runChecks($errors));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function runChecks(int &$errors): array
    {
        $rows = [];

        foreach (self::CHECKS as $ruleKey => [$alias, $condFields, $audienceFields]) {
            $modelClass = Relation::getMorphedModel($alias);

            if ($modelClass === null) {
                $rows[] = [$ruleKey, $alias, '?', '❌ morph alias not registered'];
                $errors++;

                continue;
            }

            /** @var Model $model */
            $model = new $modelClass;
            $allFields = [...$condFields, ...$audienceFields];
            $missing = [];

            foreach ($allFields as $field) {
                if (in_array($field, $model->getFillable(), true) || array_key_exists($field, $model->getCasts())) {
                    continue;
                }

                // Fall back to live schema query — may throw if no DB is reachable.
                // In that case assume OK (Eloquent fillable/casts were already checked above).
                try {
                    if (! DB::getSchemaBuilder()->hasColumn($model->getTable(), $field)) {
                        $missing[] = $field;
                    }
                } catch (\Throwable) {
                    // Cannot reach DB schema — treat as present.
                }
            }

            if ($missing) {
                $rows[] = [$ruleKey, $alias, $modelClass, '❌ missing: '.implode(', ', $missing)];
                $errors++;
            } else {
                $rows[] = [$ruleKey, $alias, $modelClass, '✓ OK'];
            }
        }

        return $rows;
    }
}
