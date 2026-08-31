<?php

namespace App\Services\Workstation;

use App\Models\Branch;
use App\Models\BranchScheduleOverride;
use App\Models\Brand;
use App\Models\BrandOrderPolicy;
use App\Models\CatalogRevision;
use App\Models\Coupon;
use App\Models\Denomination;
use App\Models\Device;
use App\Models\MaterialLot;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\MenuProductToppingItemOverride;
use App\Models\MenuPromotion;
use App\Models\MenuSchedule;
use App\Models\PaymentMethod;
use App\Models\PeripheralDevice;
use App\Models\Printer;
use App\Models\PrintImageAsset;
use App\Models\PrintTemplate;
use App\Models\ShopOrderSetting;
use App\Models\Table;
use App\Models\Till;
use App\Models\TillTenderCategory;
use App\Models\TillTenderType;
use App\Models\User;
use App\Models\VoidReason;
use App\Models\Zone;
use App\Support\BusinessClock;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * #1175 phase 2 — per-feed opaque version map for the workstation sync tick.
 *
 * One GET /workstation/sync-manifest replaces N unconditional full pulls: the
 * workstation compares each feed's version against the one it stamped after
 * its last successful pull and re-pulls ONLY feeds whose version moved. The
 * manifest_version aggregates all feeds so the common no-change tick is a
 * single If-None-Match → 304 round-trip.
 *
 * Version strings are OPAQUE to the client — equality is the only defined
 * operation. Internally:
 *   - menu / handy_menu / menu_catalog ride the branch's catalog revision
 *     ("rev-N", #1095/#1114) — the purpose-built monotonic change detector
 *     for everything priced.
 *   - every other feed hashes `max(updated_at):count(*)` aggregates over the
 *     EXACT row scope its pull endpoint serves (including pivot/translation
 *     tables that shape the payload), so a row edit moves max(updated_at), a
 *     scope entry/exit or pivot hard-delete moves count(*), and a
 *     soft-delete moves both (the row leaves the soft-delete-scoped set).
 *
 * The whole computation is memoized per branch for TTL_SECONDS so the 5s
 * fleet tick costs at most one aggregate pass per branch per window; the
 * rebuild job (#1174) forgets the entry after a catalog bump so a poke never
 * races a stale manifest for long.
 */
class SyncManifestService
{
    /**
     * FROZEN contract (#1175) — the Go client is built against exactly these
     * names. Add new feeds by APPENDING a key; never rename or remove one.
     *
     * @var array<int, string>
     */
    public const FEED_KEYS = [
        'menu',
        'handy_menu',
        'menu_catalog',
        'menu_schedules',
        'promotions',
        'coupons',
        'staff',
        'branch_settings',
        'zones',
        'tables',
        'lots',
        'print_images',
        // #2712 — appended: feeds the workstation used to poll unconditionally
        // every 5 s (or, for print_templates / expected_build / print_images,
        // never pulled at all once manifest mode was on, because their only
        // caller was the legacy full-pull path).
        'print_templates',
        'expected_build',
        'payment_methods',
        'peripheral_devices',
        'printers',
        'till',
        'till_denominations',
        'tender_categories',
        'tender_types',
    ];

    /**
     * Memo window for one branch's manifest.
     *
     * MUST stay ≥ the workstation's manifest tick (`pullIntervalManifest` = 5 s,
     * `workstation/internal/service/sync_pull.go`). It was 3 s, which is SHORTER
     * than the tick, so every fleet tick missed the memo: ~25 `max()/count(*)`
     * aggregates per branch per 5 s, plus a SELECT+INSERT on the `cache` table
     * (production runs `CACHE_STORE=database`), multiplied by the number of
     * workstations in the shop. One miss per WINDOW is the design; one miss per
     * TICK is the bug.
     *
     * Freshness does not depend on this number for the change that matters:
     * a catalog rebuild calls `forget()` (#1174) and the poke channel kicks an
     * early tick, so a menu edit is visible immediately regardless of TTL.
     */
    public const TTL_SECONDS = 15;

    public static function cacheKey(string $branchId): string
    {
        return 'workstation:sync-manifest:'.$branchId;
    }

    public static function forget(string $branchId): void
    {
        Cache::forget(self::cacheKey($branchId));
    }

    /**
     * @return array{manifest_version: string, feeds: array<string, string>}
     */
    public function manifestFor(Device $device): array
    {
        return Cache::remember(
            self::cacheKey((string) $device->branch_id),
            self::TTL_SECONDS,
            fn (): array => $this->build($device),
        );
    }

    /**
     * @return array{manifest_version: string, feeds: array<string, string>}
     */
    private function build(Device $device): array
    {
        $branchId = (string) $device->branch_id;
        $orgId = (string) $device->organization_id;

        $catalog = $this->catalogRevisionVersion($branchId);

        // plan-056 — the catalog revision alone is NOT a version of these three
        // feeds. See menuAvailabilityVersion() for the three ways it stands
        // still while the payload changes.
        $catalog .= '|'.$this->menuAvailabilityVersion($branchId);

        $feeds = [
            'menu' => $catalog,
            'handy_menu' => $catalog,
            'menu_catalog' => $catalog,
            'menu_schedules' => $this->menuSchedulesVersion($branchId),
            'promotions' => $this->promotionsVersion($orgId, $branchId),
            'coupons' => $this->couponsVersion($orgId),
            'staff' => $this->staffVersion($orgId),
            'branch_settings' => $this->branchSettingsVersion($branchId),
            'zones' => $this->zonesVersion($branchId),
            'tables' => $this->tablesVersion($branchId),
            'lots' => $this->lotsVersion($branchId),
            'print_images' => $this->printImagesVersion($orgId, $branchId),
            'print_templates' => $this->printTemplatesVersion($branchId),
            'expected_build' => $this->expectedBuildVersion(),
            'payment_methods' => $this->paymentMethodsVersion($orgId, $branchId),
            'peripheral_devices' => $this->peripheralDevicesVersion($orgId, $branchId),
            'printers' => $this->printersVersion($orgId, $branchId),
            'till' => $this->tillVersion($branchId),
            'till_denominations' => $this->denominationsVersion($orgId),
            'tender_categories' => $this->tenderCategoriesVersion($orgId, $branchId),
            'tender_types' => $this->tenderTypesVersion($orgId, $branchId),
        ];

        // Keep the frozen key order authoritative so the aggregate hash never
        // shifts because an array literal above was reordered.
        $ordered = [];
        foreach (self::FEED_KEYS as $key) {
            $ordered[$key] = $feeds[$key];
        }

        return [
            'manifest_version' => sha1(implode('|', $ordered)),
            'feeds' => $ordered,
        ];
    }

    /**
     * menu / handy_menu / menu_catalog — everything priced already has a
     * purpose-built monotonic version: the branch catalog revision
     * (#1095/#1114, bumped by CatalogRevisionObserver via
     * RebuildCatalogRevisionJob). "rev-0" = branch has no catalog yet.
     */
    private function catalogRevisionVersion(string $branchId): string
    {
        $revision = (int) CatalogRevision::query()
            ->where('branch_id', $branchId)
            ->max('revision');

        return 'rev-'.$revision;
    }

    /**
     * plan-056 — the availability half of the menu feeds' version.
     *
     * ## Why the catalog revision cannot carry this on its own
     *
     * `catalog_revisions` is minted ONLY when the branch's price map changes
     * (BR-CR02), and the price map is built from ACTIVE rows
     * (`CatalogRevisionService::buildLineSnapshot` filters `mp.is_active` and
     * `mps.is_active`). So most availability edits do move it — and three do
     * not, each leaving the workstation on a 304 while Cloud serves something
     * different:
     *
     *   1. A menu_product whose variants are ALL already off. Flipping the
     *      product adds and removes no priced line, so the hash is identical.
     *   2. An edit to `disabled_reason` alone (staff corrects "hết hàng" to
     *      "hết nguyên liệu"). No line moves; the POS keeps showing the old
     *      words forever.
     *   3. A soft-deleted row returning via restore, where the price map
     *      happens to land back on a hash it already had.
     *
     * This is the same class of bug #1661 paid for with the tax tiers — see the
     * long note on `CatalogRevisionService::buildTaxTierSnapshot`.
     *
     * ## Why it is composed here and NOT added to the catalog snapshot
     *
     * The snapshot is offline-order EVIDENCE: the verifier re-prices a signed
     * order against it. Adding a key changes the shape of that evidence and
     * forces every branch in the fleet to mint a fresh revision, to buy
     * something this layer gives away free. Feed version strings are declared
     * OPAQUE, equality-only (see the class docblock), so concatenating is
     * within contract.
     *
     * Scope mirrors what the three feeds actually serve: every menu_product of
     * the branch's menus, every variant pivot under them, and the shop-level
     * topping overrides — `is_hidden` there is availability too, and it is
     * absent from the price map entirely.
     */
    private function menuAvailabilityVersion(string $branchId): string
    {
        $menuIds = Menu::query()->where('branch_id', $branchId)->select('id');
        $menuProductIds = MenuProduct::query()->whereIn('menu_id', $menuIds)->select('id');

        return $this->versionOf([
            MenuProduct::query()->whereIn('menu_id', $menuIds),
            MenuProductSku::query()->whereIn('menu_product_id', $menuProductIds),
            MenuProductToppingItemOverride::query()->whereIn('menu_product_id', $menuProductIds),
        ]);
    }

    /**
     * Mirrors MenuScheduleReplicaController: active menu_schedules of the
     * branch's menus + the branch's branch_schedule_overrides folded over
     * them (an override edit changes the served payload without touching
     * menu_schedules).
     */
    private function menuSchedulesVersion(string $branchId): string
    {
        $scheduleScope = fn (): EloquentBuilder => MenuSchedule::query()
            ->where('is_active', true)
            ->whereIn('menu_id', Menu::query()->where('branch_id', $branchId)->select('id'));

        return $this->versionOf([
            $scheduleScope(),
            BranchScheduleOverride::query()
                ->where('branch_id', $branchId)
                ->whereIn('menu_schedule_id', $scheduleScope()->select('id')),
        ]);
    }

    /**
     * Mirrors MenuPromotionReplicaController: active org promotions scoped to
     * this branch or org-wide (branch_id NULL), plus the pivot + translation
     * tables that shape the payload — product/category attachments, the
     * category→product expansion, and i18n rows. Pivot rows hard-delete on
     * detach, so count(*) catches a shrink that max(updated_at) cannot.
     */
    private function promotionsVersion(string $orgId, string $branchId): string
    {
        $promoIds = fn (): EloquentBuilder => MenuPromotion::query()
            ->where('organization_id', $orgId)
            ->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)->orWhereNull('branch_id');
            })
            ->where('is_active', true)
            ->select('id');

        return $this->versionOf([
            MenuPromotion::query()
                ->where('organization_id', $orgId)
                ->where(function ($q) use ($branchId) {
                    $q->where('branch_id', $branchId)->orWhereNull('branch_id');
                })
                ->where('is_active', true),
            DB::table('menu_promotion_product')->whereIn('menu_promotion_id', $promoIds()),
            DB::table('menu_promotion_category')->whereIn('menu_promotion_id', $promoIds()),
            DB::table('product_category')->whereIn(
                'category_id',
                DB::table('menu_promotion_category')->whereIn('menu_promotion_id', $promoIds())->select('category_id'),
            ),
            [DB::table('menu_promotion_translations')->whereIn('menu_promotion_id', $promoIds()), 'id'],
        ]);
    }

    /**
     * Mirrors CouponReplicaController: every (non-trashed) coupon of the org
     * regardless of status — status transitions are derived client-side — plus
     * the coupon_branch whitelist pivot (hard-deletes on detach) and
     * translations.
     */
    private function couponsVersion(string $orgId): string
    {
        $couponIds = fn (): EloquentBuilder => Coupon::query()
            ->where('organization_id', $orgId)
            ->select('id');

        return $this->versionOf([
            Coupon::query()->where('organization_id', $orgId),
            DB::table('coupon_branch')->whereIn('coupon_id', $couponIds()),
            [DB::table('coupon_translations')->whereIn('coupon_id', $couponIds()), 'id'],
        ]);
    }

    /**
     * Mirrors StaffReplicaController: active users holding any role in the
     * org. The pivot aggregate catches grant/revoke (hard delete); the user
     * aggregate catches profile edits and deactivation.
     */
    private function staffVersion(string $orgId): string
    {
        $pivotUserIds = DB::table('role_user_pivots')
            ->where('organization_id', $orgId)
            ->select('user_id');

        return $this->versionOf([
            DB::table('role_user_pivots')->where('organization_id', $orgId),
            User::query()->where('is_active', true)->whereIn('id', $pivotUserIds),
        ]);
    }

    /**
     * Mirrors BranchController::show (the workstation's PullBranch feed): the
     * branch row itself, its shop_order_settings row, the brand row (seller
     * registration number inheritance, #1152), the brand order policy
     * (table-status + print-locale defaults) and the brand's void reasons +
     * translations (plan-051 payload).
     */
    private function branchSettingsVersion(string $branchId): string
    {
        $brandIds = fn (): EloquentBuilder => Brand::query()
            ->whereIn('console_brand_id', Branch::query()->whereKey($branchId)->select('console_brand_id'))
            ->select('id');

        $voidReasonScope = fn (): EloquentBuilder => VoidReason::query()
            ->whereIn('brand_id', $brandIds())
            ->where('is_active', true);

        $rows = $this->versionOf([
            Branch::query()->whereKey($branchId),
            ShopOrderSetting::query()->where('branch_id', $branchId),
            Brand::query()->whereIn('console_brand_id', Branch::query()->whereKey($branchId)->select('console_brand_id')),
            BrandOrderPolicy::query()->whereIn('brand_id', $brandIds()),
            $voidReasonScope(),
            [DB::table('void_reason_translations')->whereIn('void_reason_id', $voidReasonScope()->select('id')), 'id'],
        ]);

        // The branch feed also carries broadcast_* keys derived from Laravel
        // CONFIG, not rows — no updated_at to hash. Without this component a
        // config or host-mapping change deploys and every already-synced
        // workstation 304s forever, keeping the stale value (measured
        // 2026-08-18: the api-→ws- pusher host fix reached no shop until the
        // manifest cursor was cleared by hand).
        return sha1($rows.'|'.BroadcastPokeSettings::fingerprint());
    }

    /** Mirrors TmsController::zones: active zones of the branch. */
    private function zonesVersion(string $branchId): string
    {
        return $this->versionOf([
            Zone::query()->where('branch_id', $branchId)->where('is_active', true),
        ]);
    }

    /** Mirrors TmsController::tables: active tables of the branch (status changes touch updated_at). */
    private function tablesVersion(string $branchId): string
    {
        return $this->versionOf([
            Table::query()->where('branch_id', $branchId)->where('is_active', true),
        ]);
    }

    /**
     * Mirrors LotController::index's base scope (before request filters):
     * active, non-empty lots in the branch's warehouses.
     */
    private function lotsVersion(string $branchId): string
    {
        return $this->versionOf([
            MaterialLot::query()
                ->where('status', 'active')
                ->where('qty_on_hand', '>', 0)
                ->whereHas('warehouse', fn ($q) => $q->where('branch_id', $branchId)),
        ]);
    }

    /**
     * #1957 mảnh B — phiên bản của feed ảnh in.
     *
     * Tính trên ảnh của CHÍNH chi nhánh này cộng brand của nó — một trong hai đổi
     * là thứ máy trạm phải in ra khác đi. Chỉ đếm hàng `published`: một bản nháp ở
     * HQ không được làm quán nào kéo lại gì.
     */
    private function printImagesVersion(string $orgId, string $branchId): string
    {
        // Brand CỦA CHI NHÁNH NÀY, không phải mọi brand. `orWhereNotNull('brand_id')`
        // là bẫy: nó làm một lần publish ở brand bất kỳ đổi version của MỌI chi
        // nhánh trong hệ thống, tức cả đội máy trạm cùng kéo lại — đúng thứ bệnh
        // mà cơ chế hash sinh ra để tránh.
        $brandId = $this->brandIdForBranch($branchId);

        return $this->versionOf([
            PrintImageAsset::query()
                ->where('status', 'published')
                ->where(function ($q) use ($branchId, $brandId) {
                    $q->where('branch_id', $branchId);
                    if ($brandId) {
                        $q->orWhere('brand_id', $brandId);
                    }
                }),
        ]);
    }

    /**
     * #2712 — mirrors PrintTemplateReplicaController: every row the branch's
     * three-layer resolve can read, i.e. the brand's rows (branch_id NULL) plus
     * this branch's own overrides. Deliberately a SUPERSET of what the resolver
     * picks (draft/superseded rows included): over-detecting costs one small
     * delta GET, under-detecting means the shop prints yesterday's template.
     *
     * The second scope is the scheduled-activation gate: `effective_from` is a
     * branch-local wall clock (#1091), so a template published today "effective
     * tomorrow 09:00" changes the served payload with NO row edit — nothing
     * `max(updated_at)` can see. Counting the rows already effective moves the
     * version exactly at that boundary.
     */
    private function printTemplatesVersion(string $branchId): string
    {
        $brandId = $this->brandIdForBranch($branchId);
        if ($brandId === null) {
            return $this->versionOf([]);
        }

        $scope = fn (): EloquentBuilder => PrintTemplate::query()
            ->where('brand_id', $brandId)
            ->where(function ($q) use ($branchId) {
                $q->whereNull('branch_id')->orWhere('branch_id', $branchId);
            });

        return $this->versionOf([
            $scope(),
            $scope()->where('effective_from', '<=', BusinessClock::now($branchId)->format('Y-m-d H:i:s')),
        ]);
    }

    /**
     * #2712 — expected build is CONFIG, not rows (`config/workstation.php`, read
     * by ExpectedBuildController), so its version is a hash of the served value.
     * It moves on the deploy that changes it and never in between, which is the
     * whole point: the feed's only caller used to be the legacy full pull, so in
     * manifest mode the stale-build alert never ran at all.
     *
     * `package` (resolved from the public download manifest) is deliberately not
     * hashed — it is fetched at pull time and carries no alert of its own.
     */
    private function expectedBuildVersion(): string
    {
        $expected = config('workstation.expected_build', []) ?? [];

        // Phủ CẢ GÓI TẢI, không chỉ config. Đo 2026-08-18: URL tải đổi ba đời
        // trong một tối (/archive/ → file tĩnh trên APP_URL → /api/*) mà hash
        // này đứng yên vì nó chỉ nhìn config — máy trạm 304 mãi, giữ URL chết
        // trong cache và assisted update 404 đúng lúc HQ ra lệnh cập nhật;
        // phải xoá cursor bằng tay từng máy. Cùng lớp bẫy với broadcast_* ở
        // branchSettingsVersion (config-derived state không có updated_at).
        $version = trim((string) ($expected['version'] ?? ''));
        $package = $version === ''
            ? null
            : app(WorkstationDownloadCatalog::class)->packageForVersion($version);

        return sha1((string) json_encode([$expected, $package]));
    }

    /** Mirrors PaymentMethodReplicaController: the org's methods for this branch + the org-wide ones. */
    private function paymentMethodsVersion(string $orgId, string $branchId): string
    {
        return $this->versionOf([
            PaymentMethod::query()
                ->where('organization_id', $orgId)
                ->where(function ($q) use ($branchId) {
                    $q->where('branch_id', $branchId)->orWhereNull('branch_id');
                }),
        ]);
    }

    /** Mirrors PeripheralDeviceReplicaController: the branch's peripheral registry. */
    private function peripheralDevicesVersion(string $orgId, string $branchId): string
    {
        return $this->versionOf([
            PeripheralDevice::query()
                ->where('organization_id', $orgId)
                ->where('branch_id', $branchId),
        ]);
    }

    /** Mirrors WorkstationPrinterReplicaController: the branch's ACTIVE printers. */
    private function printersVersion(string $orgId, string $branchId): string
    {
        return $this->versionOf([
            Printer::query()
                ->where('organization_id', $orgId)
                ->where('branch_id', $branchId)
                ->where('is_active', true),
        ]);
    }

    /**
     * Mirrors TillController::current. `current_session_id` is part of the
     * payload, and it is an UPDATE on the till row, so opening or closing a
     * shift on Cloud moves this version.
     */
    private function tillVersion(string $branchId): string
    {
        return $this->versionOf([
            Till::query()->where('branch_id', $branchId),
        ]);
    }

    /** Mirrors TillController::denominations: active rows of this org + the system-wide (org NULL) baseline. */
    private function denominationsVersion(string $orgId): string
    {
        return $this->versionOf([
            Denomination::query()
                ->where('is_active', true)
                ->where(function ($q) use ($orgId) {
                    $q->whereNull('organization_id')->orWhere('organization_id', $orgId);
                }),
        ]);
    }

    /** Mirrors TillController::tenderCategories. */
    private function tenderCategoriesVersion(string $orgId, string $branchId): string
    {
        return $this->versionOf([
            TillTenderCategory::query()
                ->where('organization_id', $orgId)
                ->where('is_active', true)
                ->where(function ($q) use ($branchId) {
                    $q->whereNull('branch_id')->orWhere('branch_id', $branchId);
                }),
        ]);
    }

    /**
     * Mirrors TillController::tenderTypes — including the translation rows, which
     * ARE the payload (`name_i18n`); that table has no timestamps, so it uses the
     * `max(id)` tuple like the other omnify translation tables.
     */
    private function tenderTypesVersion(string $orgId, string $branchId): string
    {
        $tenderScope = fn (): EloquentBuilder => TillTenderType::query()
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->where(function ($q) use ($branchId) {
                $q->whereNull('branch_id')->orWhere('branch_id', $branchId);
            });

        return $this->versionOf([
            $tenderScope(),
            [DB::table('till_tender_type_translations')->whereIn('till_tender_type_id', $tenderScope()->select('id')), 'id'],
        ]);
    }

    /** The Brand row of this branch (soft-deleted included — a trashed brand still owns rows). */
    private function brandIdForBranch(string $branchId): ?string
    {
        $consoleBrandId = Branch::withTrashed()->whereKey($branchId)->value('console_brand_id');

        return $consoleBrandId
            ? Brand::withTrashed()->where('console_brand_id', $consoleBrandId)->value('id')
            : null;
    }

    /**
     * sha1 over `max(<column>):count(*)` per scope. Eloquent builders keep
     * their global scopes (soft deletes) when reduced to a base query, so a
     * trashed row leaves the aggregate exactly like it leaves the pull.
     *
     * Default column is updated_at. Timestamp-less tables (the omnify
     * translation tables) pass an explicit `max(id)` tuple instead — inserts
     * and deletes still move the aggregate (count + auto-increment high-water
     * mark); a byte-in-place UPDATE on such a row is the one blind spot, and
     * the admin flows that edit translations touch their parent row in the
     * same request, which moves the parent aggregate.
     *
     * @param  array<int, EloquentBuilder|QueryBuilder|array{0: EloquentBuilder|QueryBuilder, 1: string}>  $scopes
     */
    private function versionOf(array $scopes): string
    {
        $parts = [];
        foreach ($scopes as $scope) {
            [$builder, $column] = is_array($scope) ? $scope : [$scope, 'updated_at'];
            $query = $builder instanceof EloquentBuilder ? $builder->toBase() : $builder;
            $row = $query->selectRaw("max({$column}) as max_marker, count(*) as row_count")->first();

            $parts[] = (string) ($row->max_marker ?? '').':'.(int) ($row->row_count ?? 0);
        }

        return sha1(implode('|', $parts));
    }
}
