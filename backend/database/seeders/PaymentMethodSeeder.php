<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Services\Omnify\PaymentMethodService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Seeds the four standard payment methods for every branch.
 *
 * Idempotent: matches on (branch_id, code). Re-running syncs existing rows
 * without duplicating them.
 *
 * Goes through PaymentMethodService::create / ::update so Astrotomic
 * translations persist via flushTranslations() (convention #3 — raw
 * PaymentMethod::create would silently lose translatable locale keys).
 *
 * Usage:
 *   php artisan db:seed --class=PaymentMethodSeeder
 */
class PaymentMethodSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(PaymentMethodService $service): void
    {
        $orgs = Organization::all();

        if ($orgs->isEmpty()) {
            $this->command?->error('PaymentMethodSeeder: No organization found. Run MockDataSeeder first.');

            return;
        }

        $rows = $this->paymentMethods();
        $total = 0;

        foreach ($orgs as $org) {
            foreach ($rows as $row) {
                $row['organization_id'] = $org->id;

                $existing = PaymentMethod::query()
                    ->where('code', $row['code'])
                    ->where('organization_id', $org->id)
                    ->when(
                        $row['branch_id'] !== null,
                        fn ($q) => $q->where('branch_id', $row['branch_id']),
                        fn ($q) => $q->whereNull('branch_id'),
                    )
                    ->first();

                if ($existing) {
                    $service->update($existing, $row);
                } else {
                    $service->create($row);
                }
                $total++;
            }
        }

        $this->command?->info("PaymentMethodSeeder: {$total} payment methods seeded across {$orgs->count()} orgs.");
    }

    /**
     * `type` is the behavioural class POS keys off — notably `on_account`,
     * which is how the "Ghi nợ" button finds its method. It must be set
     * explicitly: the column's DB default is `cash`, so leaving it out (as
     * this seeder used to) silently stamps every method as cash.
     *
     * @return array<int, array{
     *     code: string,
     *     type: string,
     *     'name:ja': string,
     *     'name:en': string,
     *     'name:vi': string,
     *     is_auto_confirm: bool,
     *     requires_tendered: bool,
     *     is_active: bool,
     *     sort_order: int,
     *     branch_id: null,
     * }>
     */
    private function paymentMethods(): array
    {
        return [
            [
                'code' => 'cash',
                'type' => 'cash',
                'name:ja' => '現金',
                'name:en' => 'Cash',
                'name:vi' => 'Tiền mặt',
                'is_auto_confirm' => true,
                'requires_tendered' => true,
                'is_active' => true,
                'sort_order' => 0,
                'branch_id' => null,
            ],
            [
                // Cashier swipes the card on the BANK's standalone payment
                // terminal; approval happens out-of-band, so POS only records
                // the sale.
                //
                // is_auto_confirm MUST stay true: routes/api/pos.php exposes no
                // payments/{payment}/confirm route, so a `pending` payment made
                // from POS would have no way to ever reach `succeeded` and the
                // order would hang open forever.
                //
                // requires_tendered=false is the whole feature on the pos-web
                // side — it's the flag PaymentDialog gates the tendered/change
                // keypad on. The payment terminal already handled the amount.
                //
                // This is a NEW row rather than a flip of `card` because kiosk
                // (KioskController) and workstation (PaymentController) both read
                // `card`'s is_auto_confirm=false to set confirm_type; flipping it
                // would silently change those two surfaces.
                'code' => 'card_terminal',
                'type' => 'card',
                'name:ja' => '決済端末',
                'name:en' => 'Payment terminal',
                'name:vi' => 'Thiết bị thanh toán',
                'is_auto_confirm' => true,
                'requires_tendered' => false,
                'is_active' => true,
                'sort_order' => 1,
                'branch_id' => null,
            ],
            [
                'code' => 'card',
                'type' => 'card',
                'name:ja' => 'カード',
                'name:en' => 'Card',
                'name:vi' => 'Thẻ',
                'is_auto_confirm' => false,
                'requires_tendered' => false,
                'is_active' => true,
                'sort_order' => 2,
                'branch_id' => null,
            ],
            [
                'code' => 'transfer',
                'type' => 'transfer',
                'name:ja' => '振込',
                'name:en' => 'Transfer',
                'name:vi' => 'Chuyển khoản',
                'is_auto_confirm' => false,
                'requires_tendered' => false,
                'is_active' => true,
                'sort_order' => 3,
                'branch_id' => null,
            ],
            [
                'code' => 'e_wallet',
                'type' => 'qr',
                'name:ja' => '電子マネー',
                'name:en' => 'E-Wallet',
                'name:vi' => 'Ví điện tử',
                'is_auto_confirm' => false,
                'requires_tendered' => false,
                'is_active' => true,
                'sort_order' => 4,
                'branch_id' => null,
            ],
            [
                // Stripe online payment — auto-confirmed by webhook on
                // payment_intent.succeeded. Webhook will firstOrCreate this
                // row on demand if the seeder hasn't run, but seeding it
                // makes admin payment-method dropdowns / reports complete
                // out of the box.
                'code' => 'stripe',
                'type' => 'card',
                'name:ja' => 'Stripe',
                'name:en' => 'Stripe',
                'name:vi' => 'Stripe',
                'is_auto_confirm' => true,
                'requires_tendered' => false,
                'is_active' => true,
                'sort_order' => 5,
                'branch_id' => null,
            ],
            [
                // plan-038 capability 4 — "Ghi nợ". The 2026_06_20 migration
                // that was supposed to seed this loops over `branches`, but on
                // a fresh install migrations run BEFORE seeders, so the table
                // is still empty and the loop is a no-op. The method therefore
                // never existed on any freshly-seeded DB and the POS debt
                // button was permanently disabled ("debt method not
                // configured"). Seeding it here — after branches exist — is
                // what actually makes the capability reachable.
                'code' => 'debt',
                'type' => 'on_account',
                'name:ja' => 'ツケ',
                'name:en' => 'On account',
                'name:vi' => 'Ghi nợ',
                'is_auto_confirm' => true,
                'requires_tendered' => false,
                'is_active' => true,
                'sort_order' => 90,
                'branch_id' => null,
            ],
        ];
    }
}
