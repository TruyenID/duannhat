<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Menu;
use App\Models\MenuSchedule;
use App\Models\Organization;
use App\Models\User;
use App\Omnify\Enums\DeviceStatusEnum;
use App\Omnify\Enums\DeviceTypeEnum;
use App\Omnify\Enums\MenuStatusEnum;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Comprehensive end-to-end POS seeder — lấp đầy data cho TOÀN BỘ endpoints
 * mà pos-web call qua `/api/v1/pos/*`. Idempotent (safe re-run); chỉ TOP-UP
 * gap mà các seeder gốc miss, KHÔNG ghi đè existing data.
 *
 * Coverage map (40+ endpoints — xem comment ở từng method):
 *
 *   AUTH               /pos/me, /pos/staff
 *   SHOP CONFIG        /pos/shop, /pos/settings/order, /pos/tables, /pos/payment-methods
 *   MENUS              /pos/menus, /pos/menus/by-day/{dayOfWeek}, /pos/menus/{menu}, /pos/menus/{menu}/products
 *   ORDERS             /pos/orders (CRUD + checkout + void + start/end-edit)
 *   ITEMS              /pos/orders/{order}/items (CRUD + void)
 *   SPLIT-BILL         /pos/orders/{order}/split-bill, /split-by-items/preview
 *   COUPON             /pos/orders/{order}/apply-coupon, /coupon (release)
 *   TABLE              /pos/orders/{order}/merge-table, /unmerge-table
 *   PAYMENTS           /pos/orders/{order}/payments, /payments/{payment}/refund
 *   INVOICES           /pos/orders/{order}/invoices, /pos/invoices/{invoice}
 *   CUSTOMERS          /pos/customers/find-or-create, /pos/customers/{id}/outstanding
 *   REVENUE            /pos/revenue/summary, /pos/revenue/by-product
 *   TILL               /pos/till/current, /denominations, /tender-types, /tender-categories
 *   TILL SESSIONS      /pos/till/sessions (open/close/abandon/reconciliation/cash-events)
 *
 * Gap analysis findings (run `php artisan db:seed --class=PosE2eSeeder` để fill):
 *   1. MenuScheduleSeeder skip branches có menu không kèm master_menu_id
 *      (tokyo/hanoi/london) → /pos/menus/by-day/{day} return rỗng cho 3 branch
 *      này. Method `fillMissingMenuSchedules()` fix.
 *   2. POS device chưa được seed → không có cách pair POS terminal. Method
 *      `seedPosDevicesForEachBranch()` tạo 1 device pending-activation
 *      kèm pairing_code 15-phút TTL per branch.
 *   3. Customer dataset chỉ 10 row → method `seedCustomerVariety()` thêm
 *      đủ phone variants để test find-or-create + outstanding endpoints.
 *
 * Usage:
 *   docker compose exec app php artisan db:seed --class=PosE2eSeeder
 *
 * Re-run safe (firstOrCreate / updateOrCreate everywhere).
 */
class PosE2eSeeder extends Seeder
{
    use RefusesToRunInProduction;

    /** Day-of-week bitmask: Mon-Sat (all-week-except-Sun) — matches MenuScheduleSeeder. */
    private const DOW_ALL = 127;

    public function run(): void
    {
        $this->guardAgainstProduction();

        $this->command->info('PosE2eSeeder: starting…');

        $this->fillMissingMenuSchedules();
        $this->seedPosDevicesForEachBranch();
        $this->seedCustomerVariety();

        $this->command->info('PosE2eSeeder: done.');
    }

    /**
     * GAP 1 — `/api/v1/pos/menus/by-day/{dayOfWeek}` returns empty for branches
     * whose menus lack a `master_menu_id` (tokyo, hanoi, london), because
     * `MenuScheduleSeeder` filters `whereNotNull('master_menu_id')` and skips
     * them. Add an all-day fallback schedule (07:00–22:00, Mon–Sun) so the
     * endpoint always surfaces something for every active branch menu.
     *
     * Covers: GET /api/v1/pos/menus/by-day/0..6
     */
    private function fillMissingMenuSchedules(): void
    {
        // Find every active branch menu with ZERO schedules (regardless of
        // master_menu_id). MenuScheduleSeeder occasionally misses these when
        // a branch is added after the initial seed pass — checking
        // schedule-count is more robust than filtering by master_menu_id.
        $orphanMenus = Menu::query()
            ->where('is_master', false)
            ->whereNotNull('branch_id')
            ->where('status', MenuStatusEnum::Active->value)
            ->whereDoesntHave('schedules', fn ($q) => $q->whereNull('deleted_at'))
            ->with('branch:id,slug')
            ->get();

        if ($orphanMenus->isEmpty()) {
            $this->command->info('  ✓ menu_schedules: no orphan menus, skipping');

            return;
        }

        $created = 0;
        foreach ($orphanMenus as $menu) {
            $schedule = MenuSchedule::firstOrCreate(
                [
                    'menu_id' => $menu->id,
                    'start_time' => '07:00:00',
                    'end_time' => '22:00:00',
                    'days_of_week' => self::DOW_ALL,
                ],
                [
                    'is_active' => true,
                    'priority' => 100,
                ],
            );

            if ($schedule->wasRecentlyCreated) {
                $created++;
                $this->command->line("    → schedule for {$menu->branch?->slug} menu '{$menu->name}'");
            }
        }

        $this->command->info("  ✓ menu_schedules: filled {$created} gap rows (orphan menus without master)");
    }

    /**
     * GAP 2 — Default seed has 0 POS devices. Without a `device.type=pos`
     * row holding an active `pairing_code`, pos-web /pairing endpoint cannot
     * succeed. Create one `pending_activation` POS device per non-HQ branch
     * with a 15-minute pairing code.
     *
     * Covers: POST /api/v1/devices/pair (the entry point to the entire POS
     * surface — without pair, nothing else works).
     *
     * Idempotent: skip the branch if it already has a POS device.
     */
    private function seedPosDevicesForEachBranch(): void
    {
        $admin = User::where('email', 'admin@famgia.com')->first();
        if (! $admin) {
            $this->command->warn('  ! POS devices: admin@famgia.com not found, skipping');

            return;
        }

        $created = 0;
        $branches = Branch::where('is_active', true)->where('is_headquarters', false)->get();

        foreach ($branches as $branch) {
            $existing = Device::where('branch_id', $branch->id)
                ->where('type', DeviceTypeEnum::Pos)
                ->whereIn('status', [DeviceStatusEnum::Active, DeviceStatusEnum::PendingActivation])
                ->first();

            if ($existing) {
                continue;
            }

            $org = Organization::where('console_organization_id', $branch->console_organization_id)->first();
            if (! $org) {
                $this->command->warn("  ! POS device for {$branch->slug}: no local org for console_org_id, skipping");

                continue;
            }

            $code = strtoupper(Str::substr(md5(uniqid('', true)), 0, 6));

            Device::create([
                'name' => 'POS-'.strtoupper($branch->slug),
                'type' => DeviceTypeEnum::Pos,
                'status' => DeviceStatusEnum::PendingActivation,
                'pairing_code' => $code,
                'pairing_expires_at' => now()->addMinutes(15),
                'organization_id' => $org->id,
                'branch_id' => $branch->id,
                'created_by_id' => $admin->id,
                'device_info' => [
                    'seeded_by' => 'PosE2eSeeder',
                    'note' => 'Pair within 15 minutes of seed run.',
                ],
            ]);

            $this->command->line('    → POS-'.strtoupper($branch->slug)." (branch={$branch->slug}) code={$code}");
            $created++;
        }

        $this->command->info("  ✓ pos devices: created {$created} new (existing pos devices preserved)");
    }

    /**
     * GAP 3 — Default seed has ~10 customers, all linked to old test orders.
     * Add 20 more with phone-number variants so QA can test:
     *   - POST /api/v1/pos/customers/find-or-create (Vietnamese / Japanese phone formats)
     *   - GET /api/v1/pos/customers/{customer}/outstanding (customer has 0 outstanding orders)
     *
     * Uses `firstOrCreate` keyed on phone — re-run won't dup.
     */
    private function seedCustomerVariety(): void
    {
        // Customer schema: first_name / last_name split (no single `name`).
        // Samples cover VN/JP/GB/US phone-country variants for testing
        // /pos/customers/find-or-create + outstanding endpoints.
        $samples = [
            // Vietnamese
            ['first' => 'Khôi',     'last' => 'Nguyễn Văn', 'phone' => '+84-90-123-4501', 'email' => 'khoi@test.vn'],
            ['first' => 'Mai',      'last' => 'Trần Thị',   'phone' => '+84-90-123-4502', 'email' => 'mai@test.vn'],
            ['first' => 'Hùng',     'last' => 'Lê Văn',     'phone' => '+84-90-123-4503', 'email' => 'hung@test.vn'],
            ['first' => 'Lan',      'last' => 'Phạm Thị',   'phone' => '+84-90-123-4504', 'email' => 'lan@test.vn'],
            ['first' => 'An',       'last' => 'Hoàng Văn',  'phone' => '+84-90-123-4505', 'email' => 'an@test.vn'],
            // Japanese
            ['first' => '太郎',      'last' => '田中',         'phone' => '+81-90-1234-5601', 'email' => 'tanaka@test.jp'],
            ['first' => '花子',      'last' => '鈴木',         'phone' => '+81-90-1234-5602', 'email' => 'suzuki@test.jp'],
            ['first' => '健一',      'last' => '佐藤',         'phone' => '+81-90-1234-5603', 'email' => 'sato@test.jp'],
            ['first' => '美咲',      'last' => '山田',         'phone' => '+81-90-1234-5604', 'email' => 'yamada@test.jp'],
            ['first' => '大輔',      'last' => '伊藤',         'phone' => '+81-90-1234-5605', 'email' => 'ito@test.jp'],
            // Edge cases — duplicate name diff phone, no email
            ['first' => 'One',      'last' => 'Test User',  'phone' => '+84-90-999-0001', 'email' => null],
            ['first' => 'Two',      'last' => 'Test User',  'phone' => '+84-90-999-0002', 'email' => 'twouser@test.vn'],
            ['first' => 'NoMail',   'last' => 'Customer',   'phone' => '+81-90-9999-0003', 'email' => null],
            ['first' => 'Dup',      'last' => 'Same Name',  'phone' => '+84-90-555-0001', 'email' => 'dup1@test.vn'],
            ['first' => 'Dup',      'last' => 'Same Name',  'phone' => '+84-90-555-0002', 'email' => 'dup2@test.vn'],
            // UK
            ['first' => 'James',    'last' => 'Smith',      'phone' => '+44-7700-900123', 'email' => 'james@test.uk'],
            ['first' => 'Emma',     'last' => 'Wilson',     'phone' => '+44-7700-900456', 'email' => 'emma@test.uk'],
            ['first' => 'Michael',  'last' => 'Brown',      'phone' => '+44-7700-900789', 'email' => 'michael@test.uk'],
            // US
            ['first' => 'David',    'last' => 'Johnson',    'phone' => '+1-555-100-2001', 'email' => 'david@test.us'],
            ['first' => 'Sarah',    'last' => 'Davis',      'phone' => '+1-555-100-2002', 'email' => 'sarah@test.us'],
        ];

        // Customers are org-scoped via console_organization_id resolved from branch.
        $branch = Branch::whereNotNull('console_organization_id')->first();
        if (! $branch) {
            $this->command->warn('  ! customers: no branch with console_organization_id, skipping');

            return;
        }

        $org = Organization::where('console_organization_id', $branch->console_organization_id)->first();
        if (! $org) {
            $this->command->warn('  ! customers: no local organization, skipping');

            return;
        }

        $created = 0;
        foreach ($samples as $row) {
            $customer = Customer::firstOrCreate(
                ['phone' => $row['phone']],
                [
                    'first_name' => $row['first'],
                    'last_name' => $row['last'],
                    'email' => $row['email'],
                    'organization_id' => $org->id,
                    'branch_id' => $branch->id,
                    'password' => bcrypt('e2e-not-used'),
                    'email_verified_at' => $row['email'] ? now() : null,
                ],
            );

            if ($customer->wasRecentlyCreated) {
                $created++;
            }
        }

        $totalCustomers = Customer::count();
        $this->command->info("  ✓ customers: created {$created} new (total={$totalCustomers})");

        // Sanity check — confirm phones searchable for find-or-create
        $reachable = Customer::whereIn('phone', array_column($samples, 'phone'))->count();
        if ($reachable < count($samples)) {
            $this->command->warn("  ! customers: only {$reachable}/".count($samples).' samples are queryable (expected all)');
        }
    }
}
