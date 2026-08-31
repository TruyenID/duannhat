<?php

namespace App\Services\Shop;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Table;
use App\Models\TableTemplate;
use App\Models\Zone;
use App\Models\ZoneTemplate;
use App\Support\QrTokenGenerator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * BrandTableDefaultsService — issue #890.
 *
 * Copies the brand's HQ zone/table templates into REAL shop zones/tables.
 * A template is matched to a shop row by `code`; existing codes (including
 * soft-deleted rows, which still occupy the unique index) are skipped, so
 * the operation is idempotent and never overwrites shop customizations.
 * Copied tables get their own qr_token and start with status `free`.
 */
class BrandTableDefaultsService
{
    public function __construct(
        private readonly QrTokenGenerator $qrTokenGenerator,
    ) {}

    /**
     * What apply() WOULD do, without writing — drives the confirm dialog.
     *
     * @return array{zones: array{create: int, skip: int}, tables: array{create: int, skip: int}}
     */
    public function preview(Branch $shop, string $organizationId): array
    {
        $plan = $this->plan($shop, $organizationId);

        return [
            'zones' => [
                'create' => $plan['zones_to_create']->count(),
                'skip' => $plan['zones_to_skip']->count(),
            ],
            'tables' => [
                'create' => $plan['tables_to_create']->count(),
                'skip' => $plan['tables_to_skip']->count(),
            ],
        ];
    }

    /**
     * Copy the brand's active templates into the shop. Idempotent by code.
     *
     * @return array{zones_created: int, zones_skipped: int, tables_created: int, tables_skipped: int}
     */
    public function apply(Branch $shop, string $organizationId): array
    {
        return DB::transaction(function () use ($shop, $organizationId) {
            $plan = $this->plan($shop, $organizationId);

            /** @var array<string, Zone> $zonesByCode existing + newly created shop zones, keyed by code */
            $zonesByCode = $plan['existing_zones_by_code'];

            foreach ($plan['zones_to_create'] as $zoneTemplate) {
                $zonesByCode[$this->normalizeCode($zoneTemplate->code)] = Zone::create([
                    'organization_id' => $organizationId,
                    'branch_id' => $shop->id,
                    // Origin marker (BR-Z04): HQ-origin zones cannot be
                    // deleted by the shop.
                    'zone_template_id' => $zoneTemplate->id,
                    'code' => $zoneTemplate->code,
                    'name' => $zoneTemplate->name,
                    'description' => $zoneTemplate->description,
                    'display_order' => $zoneTemplate->display_order,
                    'is_active' => true,
                ]);
            }

            $tablesCreated = 0;
            foreach ($plan['tables_to_create'] as $tableTemplate) {
                $zone = $zonesByCode[$this->normalizeCode($tableTemplate->zoneTemplate->code)] ?? null;
                if ($zone === null || $zone->trashed()) {
                    // Zone slot is occupied by a soft-deleted shop zone — the
                    // table has nowhere sane to land; count it as skipped.
                    continue;
                }

                Table::create([
                    'organization_id' => $organizationId,
                    'branch_id' => $shop->id,
                    'zone_id' => $zone->id,
                    // Origin marker (BR-T09): HQ-origin tables cannot be
                    // deleted by the shop.
                    'table_template_id' => $tableTemplate->id,
                    'code' => $tableTemplate->code,
                    'name' => $tableTemplate->name,
                    'seat_count' => $tableTemplate->seat_count,
                    'status' => 'free',
                    'is_active' => true,
                    'qr_token' => $this->qrTokenGenerator->generate(Table::class),
                ]);
                $tablesCreated++;
            }

            return [
                'zones_created' => $plan['zones_to_create']->count(),
                'zones_skipped' => $plan['zones_to_skip']->count(),
                'tables_created' => $tablesCreated,
                'tables_skipped' => $plan['tables_to_create']->count() - $tablesCreated + $plan['tables_to_skip']->count(),
            ];
        });
    }

    /**
     * Resolve the shop's Brand (branches join brands via console_brand_id).
     */
    public function resolveBrand(Branch $shop): ?Brand
    {
        if (! $shop->console_brand_id) {
            return null;
        }

        return $shop->brand;
    }

    /**
     * Canonicalize a zone/table code for duplicate detection so PHP matches
     * the case-insensitive MySQL unique-index collation.
     */
    private function normalizeCode(?string $code): string
    {
        return mb_strtoupper((string) $code);
    }

    /**
     * Build the copy plan: active templates split into create vs skip by
     * comparing codes against the shop's existing zones/tables (withTrashed —
     * a soft-deleted row still occupies the [branch_id, code] unique index).
     *
     * @return array{
     *     zones_to_create: Collection<int, ZoneTemplate>,
     *     zones_to_skip: Collection<int, ZoneTemplate>,
     *     tables_to_create: Collection<int, TableTemplate>,
     *     tables_to_skip: Collection<int, TableTemplate>,
     *     existing_zones_by_code: array<string, Zone>,
     * }
     */
    private function plan(Branch $shop, string $organizationId): array
    {
        $brand = $this->resolveBrand($shop);

        if ($brand === null) {
            abort(response()->json([
                'message' => 'This shop is not linked to a brand, so there are no HQ defaults to apply.',
                'code' => 'SHOP_HAS_NO_BRAND',
            ], 409));
        }

        // Branch targeting: a template applies to this shop when its
        // branch_id is NULL (brand-wide) or equals the shop. A table template
        // additionally needs its zone template to be applicable here.
        $appliesToShop = fn ($q) => $q->where(
            fn ($qq) => $qq->whereNull('branch_id')->orWhere('branch_id', $shop->id)
        );

        $zoneTemplates = ZoneTemplate::where('brand_id', $brand->id)
            ->where('is_active', true)
            ->tap($appliesToShop)
            ->orderBy('display_order')
            // #3170 — display_order defaults to 0, so tied templates would apply
            // in a query-plan-dependent order.
            ->orderBy('zone_templates.id')
            ->get();

        $tableTemplates = TableTemplate::with('zoneTemplate')
            ->where('brand_id', $brand->id)
            ->where('is_active', true)
            ->tap($appliesToShop)
            ->whereHas('zoneTemplate', fn ($q) => $q->where('is_active', true)->tap($appliesToShop))
            ->orderBy('code')
            ->get();

        // Codes are matched case-insensitively to mirror the MySQL unique
        // index collation ([branch_id, code], utf8mb4_unicode_ci). A
        // case-sensitive PHP compare here would let e.g. template "Counter"
        // slip past an existing "COUNTER" and then blow up on insert with a
        // 1062 duplicate-key (issue #890 server error).
        $existingZones = Zone::withTrashed()
            ->where('branch_id', $shop->id)
            ->get()
            ->keyBy(fn (Zone $z) => $this->normalizeCode($z->code));

        $existingTableCodes = Table::withTrashed()
            ->where('branch_id', $shop->id)
            ->pluck('code')
            ->map(fn (string $code) => $this->normalizeCode($code))
            ->flip();

        [$zonesToSkip, $zonesToCreate] = $zoneTemplates->partition(
            fn (ZoneTemplate $t) => $existingZones->has($this->normalizeCode($t->code))
        );

        [$tablesToSkip, $tablesToCreate] = $tableTemplates->partition(
            fn (TableTemplate $t) => $existingTableCodes->has($this->normalizeCode($t->code))
        );

        return [
            'zones_to_create' => $zonesToCreate->values(),
            'zones_to_skip' => $zonesToSkip->values(),
            'tables_to_create' => $tablesToCreate->values(),
            'tables_to_skip' => $tablesToSkip->values(),
            'existing_zones_by_code' => $existingZones->all(),
        ];
    }
}
