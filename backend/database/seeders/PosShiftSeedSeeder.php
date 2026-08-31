<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\Till;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * POS shift open/close demo seed — staff + tills per branch.
 *
 * Plan-030 chia trách nhiệm:
 *  - DenominationSeeder         → mệnh giá tiền (JPY/VND/USD/EUR, global)
 *  - PaymentMethodSeeder        → phương thức thanh toán (cash/card/transfer/e_wallet/stripe)
 *  - TillTenderCategorySeeder   → nhóm tender (card / qr / emoney)
 *  - TillTenderTypeSeeder       → master tender (cash, credit, 9 QR, 6 e-money)
 *  - Plan036TillTrackingDemoSeeder → kịch bản đầy đủ chỉ cho chi nhánh demo
 *
 * Seeder này bù phần còn thiếu để cashier mở/kết ca trên MỌI chi nhánh:
 *
 *   1) Mỗi branch (trừ HQ) có sẵn 1 Till "REG1" với currency phù hợp:
 *        - hanoi  → VND
 *        - london → EUR
 *        - các branch khác → JPY
 *
 *   2) Mỗi branch có sẵn nhân viên đăng nhập + chấm ca:
 *        - 1 shop-manager (Quản lý)
 *        - 4 shop-staff   (Thu ngân #1..#4) → xuất hiện trong dropdown
 *          "Người mở ca" của màn hình Open Shift (pos-web).
 *
 *      Email pattern: {slug}-manager@famgia.com, {slug}-cashier-{n}@famgia.com
 *      Password mặc định: "password"
 *      console_user_id sinh deterministic theo (slug, role, index) để re-run
 *      seeder không tạo trùng user.
 *
 * Idempotent: Till dùng firstOrCreate; User dùng firstOrCreate trên
 * (console_user_id, console_organization_id); assignRole tự dedupe.
 *
 * Run standalone:
 *   docker compose exec app php artisan db:seed --class=PosShiftSeedSeeder
 */
class PosShiftSeedSeeder extends Seeder
{
    use RefusesToRunInProduction;
    use WithoutModelEvents;

    /** Branch nội bộ không bán hàng (bản doanh, bếp trung tâm, nhà máy) — không seed POS data. */
    private const EXCLUDED_BRANCH_SLUGS = ['head-office', 'sumiyoshi-kitchen', 'shiroi-factory'];

    /** Map slug → currency override. Mặc định JPY. */
    private const CURRENCY_BY_SLUG = [
        'hanoi' => 'VND',
        'london' => 'EUR',
    ];

    /** Ngưỡng chênh lệch khi đối soát theo currency (đơn vị nguyên thuỷ). */
    private const VARIANCE_TOLERANCE_BY_CURRENCY = [
        'JPY' => 1000,
        'VND' => 250000,
        'EUR' => 5,
        'USD' => 5,
    ];

    private const CASHIER_COUNT = 4;

    public function run(): void
    {
        $this->guardAgainstProduction();

        $branches = Branch::query()
            ->whereNotIn('slug', self::EXCLUDED_BRANCH_SLUGS)
            ->get();

        if ($branches->isEmpty()) {
            $this->command?->warn('PosShiftSeedSeeder: no branches found. Run MockDataSeeder first.');

            return;
        }

        $tillsCreated = 0;
        $usersCreated = 0;

        foreach ($branches as $branch) {
            $organization = Organization::where('console_organization_id', $branch->console_organization_id)->first();
            if (! $organization) {
                $this->command?->warn("PosShiftSeedSeeder: organization missing for branch {$branch->slug}, skipped.");

                continue;
            }

            $brandId = $branch->brand_id ?? DB::table('brands')
                ->where('console_brand_id', $branch->console_brand_id)
                ->value('id');

            if (! $brandId) {
                $this->command?->warn("PosShiftSeedSeeder: brand_id missing for branch {$branch->slug}, skipped.");

                continue;
            }

            if ($this->ensureTill($branch, $organization->id, $brandId)) {
                $tillsCreated++;
            }

            $usersCreated += $this->ensureStaff($branch, $organization);
        }

        $this->command?->info(
            "PosShiftSeedSeeder: {$tillsCreated} tills created, {$usersCreated} users created across {$branches->count()} branches."
        );
    }

    /**
     * Ensure branch has a default REG1 Till. Returns true if created.
     */
    private function ensureTill(Branch $branch, string $organizationId, string $brandId): bool
    {
        $currency = self::CURRENCY_BY_SLUG[$branch->slug] ?? 'JPY';
        $tolerance = self::VARIANCE_TOLERANCE_BY_CURRENCY[$currency] ?? 1000;

        $existed = Till::where('branch_id', $branch->id)
            ->where('till_code', 'REG1')
            ->exists();

        Till::firstOrCreate(
            ['branch_id' => $branch->id, 'till_code' => 'REG1'],
            [
                'default_currency_code' => $currency,
                'variance_tolerance_amount' => $tolerance,
                'is_active' => true,
                'brand_id' => $brandId,
                'organization_id' => $organizationId,
            ]
        );

        return ! $existed;
    }

    /**
     * Ensure branch has 1 manager + N cashier users with role grants.
     * Returns number of users newly created.
     */
    private function ensureStaff(Branch $branch, Organization $organization): int
    {
        $created = 0;

        // Manager.
        $created += $this->upsertUser(
            branch: $branch,
            organization: $organization,
            roleSlug: 'shop-manager',
            roleIndex: 0,
            name: "{$branch->name} - Quản lý",
            email: "{$branch->slug}-manager@famgia.com",
            locale: $this->localeForBranch($branch),
        );

        // Cashiers.
        for ($i = 1; $i <= self::CASHIER_COUNT; $i++) {
            $created += $this->upsertUser(
                branch: $branch,
                organization: $organization,
                roleSlug: 'shop-staff',
                roleIndex: $i,
                name: "{$branch->name} - Thu ngân #{$i}",
                email: "{$branch->slug}-cashier-{$i}@famgia.com",
                locale: $this->localeForBranch($branch),
            );
        }

        return $created;
    }

    /**
     * firstOrCreate user keyed by deterministic console_user_id, then assign
     * the org+branch role. Returns 1 if a new user row was inserted.
     */
    private function upsertUser(
        Branch $branch,
        Organization $organization,
        string $roleSlug,
        int $roleIndex,
        string $name,
        string $email,
        string $locale,
    ): int {
        $consoleUserId = $this->buildConsoleUserId($branch->slug, $roleSlug, $roleIndex);

        $user = User::where('console_user_id', $consoleUserId)
            ->where('console_organization_id', $organization->console_organization_id)
            ->first();

        $created = 0;
        if (! $user) {
            $user = User::create([
                'console_user_id' => $consoleUserId,
                'console_organization_id' => $organization->console_organization_id,
                'name' => $name,
                'email' => $email,
                'password' => bcrypt('password'),
                'is_active' => true,
                'locale' => $locale,
            ]);
            $created = 1;
        }

        // assignRole from HasSsoRoles is idempotent — safe to re-run.
        $user->assignRole($roleSlug, $organization->id, $branch->id);

        return $created;
    }

    /**
     * Deterministic console_user_id so re-running the seeder doesn't create
     * duplicate users. Format: 019eb5cf-{branch_hash:4hex}-7a00-8002-{idx:012d}.
     */
    private function buildConsoleUserId(string $branchSlug, string $roleSlug, int $roleIndex): string
    {
        $branchHash = substr(md5('pos-shift-'.$branchSlug), 0, 4);
        // role nibble: 1 = manager, 2 = cashier. Lets us encode up to 4 cashiers in suffix.
        $roleNibble = $roleSlug === 'shop-manager' ? 1 : 2;
        $suffix = str_pad((string) ($roleNibble * 100 + $roleIndex), 12, '0', STR_PAD_LEFT);

        return "019eb5cf-{$branchHash}-7a00-8002-{$suffix}";
    }

    private function localeForBranch(Branch $branch): string
    {
        return match ($branch->slug) {
            'hanoi' => 'vi',
            'london' => 'en',
            default => 'ja',
        };
    }
}
