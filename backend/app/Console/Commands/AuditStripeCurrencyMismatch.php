<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CustomerOrder;
use App\Models\ShopOrderSetting;
use App\Services\Customer\StripePaymentService;
use App\Support\ZeroDecimalCurrency;
use Illuminate\Console\Command;

/**
 * #815 — read-only exposure report for the Stripe currency bug.
 *
 * Before the fix, StripePaymentService created every PaymentIntent with the global
 * `config('services.stripe.currency')` instead of the branch's priced currency
 * (shop_order_settings.currency_code). This command surfaces the orders that were
 * (or may have been) charged in the wrong currency so ops can reconcile.
 *
 * READ-ONLY: no writes, no refunds, no Stripe mutations. Two passes:
 *   - local (default): flag Stripe-paid orders whose PRICED currency differs from
 *     the historical charge currency (config('services.stripe.currency')). Cheap —
 *     no Stripe calls.
 *   - --verify-stripe: additionally retrieve each PaymentIntent and compare the
 *     ACTUAL charged currency + amount against the order total to quantify exposure.
 *
 *     php artisan stripe:audit-currency-mismatch
 *     php artisan stripe:audit-currency-mismatch --verify-stripe --branch=<id> --limit=500
 *
 * Exit code: 0 when clean, 1 when any mismatch is found (so CI/ops can alert).
 */
class AuditStripeCurrencyMismatch extends Command
{
    protected $signature = 'stripe:audit-currency-mismatch
        {--verify-stripe : Also retrieve each PaymentIntent from Stripe to read the actual charged currency/amount}
        {--branch= : Restrict the scan to a single branch id}
        {--limit= : Cap the number of Stripe-paid orders scanned}';

    protected $description = '#815 read-only report of orders whose Stripe charge currency ≠ the branch priced currency.';

    public function handle(): int
    {
        $configCurrency = strtoupper((string) config('services.stripe.currency', 'jpy'));
        $verify = (bool) $this->option('verify-stripe');

        $query = CustomerOrder::query()
            ->whereNotNull('stripe_payment_intent_id')
            ->orderBy('branch_id');

        if ($branch = $this->option('branch')) {
            $query->where('branch_id', $branch);
        }

        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
        }

        $orders = $query->get(['id', 'order_code', 'branch_id', 'total_amount', 'stripe_payment_intent_id']);

        if ($orders->isEmpty()) {
            $this->info('No Stripe-paid orders found for the given scope.');

            return self::SUCCESS;
        }

        // Resolve each branch's PRICED currency (shop_order_settings.currency_code,
        // JPY default — same resolution as StripePaymentService::resolveOrderCurrency).
        $pricedByBranch = ShopOrderSetting::query()
            ->whereIn('branch_id', $orders->pluck('branch_id')->unique()->all())
            ->pluck('currency_code', 'branch_id');

        $stripe = $verify ? app(StripePaymentService::class) : null;

        $rows = [];
        $mismatchCount = 0;

        foreach ($orders as $order) {
            $priced = strtoupper((string) ($pricedByBranch[$order->branch_id] ?: 'JPY'));

            // Local signal: the order was charged (historically) in the global config
            // currency; a priced currency that differs from it is at-risk.
            $localMismatch = $priced !== $configCurrency;

            $charged = $configCurrency;
            $chargedMajor = null;
            $exposure = null;

            if ($verify && $stripe !== null) {
                try {
                    $intent = $stripe->retrieveIntent((string) $order->stripe_payment_intent_id);
                    $charged = strtoupper((string) $intent->currency);
                    $chargedMajor = ZeroDecimalCurrency::contains($charged)
                        ? (float) $intent->amount
                        : round($intent->amount / 100, 2);
                    $exposure = $chargedMajor - (float) $order->total_amount;
                } catch (\Throwable $e) {
                    $charged = 'ERR:'.substr($e->getMessage(), 0, 30);
                }
            }

            $isMismatch = $verify ? ($charged !== $priced) : $localMismatch;

            if (! $isMismatch) {
                continue;
            }

            $mismatchCount++;

            $row = [
                'branch_id' => (string) $order->branch_id,
                'order_code' => (string) $order->order_code,
                'priced' => $priced,
                'charged' => $charged,
                'total' => (string) $order->total_amount,
            ];

            if ($verify) {
                $row['charged_major'] = $chargedMajor !== null ? (string) $chargedMajor : '—';
                $row['exposure'] = $exposure !== null ? sprintf('%+.2f', $exposure) : '—';
            }

            $rows[] = $row;
        }

        if ($rows === []) {
            $this->info(sprintf(
                'Scanned %d Stripe-paid order(s): no currency mismatch (config=%s).',
                $orders->count(),
                $configCurrency,
            ));

            return self::SUCCESS;
        }

        $headers = $verify
            ? ['branch_id', 'order_code', 'priced', 'charged', 'total', 'charged_major', 'exposure']
            : ['branch_id', 'order_code', 'priced', 'charged(config)', 'total'];

        $this->table($headers, $rows);
        $this->warn(sprintf(
            '%d of %d Stripe-paid order(s) have a currency mismatch (priced ≠ %s).',
            $mismatchCount,
            $orders->count(),
            $verify ? 'charged' : 'config '.$configCurrency,
        ));
        $this->line('READ-ONLY report — no refunds or writes were performed. Reconcile with ops.');

        return self::FAILURE;
    }
}
