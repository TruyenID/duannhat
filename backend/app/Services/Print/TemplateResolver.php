<?php

declare(strict_types=1);

namespace App\Services\Print;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\PrintTemplate;
use App\Services\Print\Enums\PrintTemplateKind;
use App\Services\Print\Enums\PrintTemplateScope;
use App\Services\Print\Enums\PrintTemplateStatus;
use App\Services\Till\TenderTypeResolver;
use App\Support\BusinessClock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * plan-053 M1 (#1171) — the three-layer resolve (DESIGN §1, TASKS T1.3).
 *
 * Shaped after {@see TenderTypeResolver}, with one decisive
 * difference: that resolver replaces a row WHOLESALE, this one merges layers
 * FIELD BY FIELD (TR-02). A shop that once changed its footer must still
 * receive every later brand change to the rest of the slip — see
 * {@see DefinitionMerger} for why wholesale replacement is the wrong default
 * for a centrally managed document.
 *
 *   layer 0  system default  ← config (code, so a never-online machine prints)
 *   layer 1  brand           ← the effective published version for the brand
 *   layer 2  shop override   ← the effective published version for the branch,
 *                              FILTERED through the brand's `shop_editable`
 *
 * ── Version selection and #1091 ───────────────────────────────────────────
 * Among a layer's published versions, the winner is the newest one whose
 * `effective_from` has already arrived AT THE BRANCH. `effective_from` is a
 * BRANCH-LOCAL WALL CLOCK, not an instant: HQ schedules "2026-08-01 00:00" and
 * a Tokyo branch flips two hours before a Hanoi one, exactly like a breakfast
 * menu window. So the comparison runs against
 * `BusinessClock::now($branchId)->format('Y-m-d H:i:s')` — never `now()`,
 * which would flip every branch on Tokyo's (or the server's) clock and
 * re-issue Hanoi's receipts nine hours early.
 *
 * Retired versions are excluded from NEW prints but never deleted: a reprint
 * addresses its version directly through {@see self::forVersion()} and still
 * renders (TR-13/TR-28/TR-39).
 *
 * Per-instance memoisation (the TaxResolver pattern) keeps a 13-kind sync-DOWN
 * pull to one query per layer instead of 26 — create a fresh resolver per
 * request so the memo cannot go stale mid-flight.
 */
class TemplateResolver
{
    /** @var array<string, string> branch id → brand id (or '' when unresolvable) */
    private array $brandOfBranch = [];

    /** @var array<string, Collection<int, PrintTemplate>> cache key → rows */
    private array $rowCache = [];

    /** @var array<string, true> "branchId:kind" → already warned (log-spam guard) */
    private array $warnedMissingBrand = [];

    public function __construct(
        private readonly BlockCatalog $catalog,
        private readonly SystemTemplateDefaults $defaults,
        private readonly DefinitionMerger $merger,
    ) {}

    /**
     * The definition a branch should print `$kind` with, right now.
     *
     * @param  bool  $withTrashed  include soft-deleted rows — the reprint path
     *                             for a deleted brand/branch (TR-39/S4)
     */
    public function forBranch(
        PrintTemplateKind|string $kind,
        string $branchId,
        bool $withTrashed = false,
    ): ResolvedTemplate {
        $kind = $kind instanceof PrintTemplateKind
            ? $kind
            : (PrintTemplateKind::tryFrom($kind) ?? throw new \InvalidArgumentException("Unknown print template kind [{$kind}]."));

        $definition = $this->defaults->forKind($kind);
        $scope = PrintTemplateScope::System;
        $version = null;
        $effectiveFrom = null;
        $brandRowId = null;
        $shopRowId = null;
        $overriddenPaths = [];
        $updatedAt = null;

        $brandId = $this->brandIdFor($branchId, $kind);
        if ($brandId === null) {
            return new ResolvedTemplate($kind, $definition, $scope, null, null, null, null, [], null);
        }

        $wallClock = $this->branchWallClock($branchId);

        // ── layer 1: brand ────────────────────────────────────────────────
        $brandRow = $this->effectiveRow(
            $this->layerRows($kind, $brandId, null, $withTrashed),
            $wallClock,
        );

        /** @var list<string> $shopEditable */
        $shopEditable = [];
        if ($brandRow !== null) {
            $definition = $this->merger->merge($definition, (array) $brandRow->definition);
            $scope = PrintTemplateScope::Brand;
            $version = (int) $brandRow->version;
            $effectiveFrom = $brandRow->effective_from;
            $brandRowId = (string) $brandRow->id;
            $updatedAt = $brandRow->updated_at?->toIso8601String();
            $shopEditable = array_values((array) ($brandRow->shop_editable ?? []));
        }

        // ── layer 2: shop override, inside the brand's allow-list ─────────
        $shopRow = $this->effectiveRow(
            $this->layerRows($kind, $brandId, $branchId, $withTrashed),
            $wallClock,
        );

        if ($shopRow !== null) {
            // TR-03/TR-04 — the allow-list is applied HERE, at resolve time.
            // A brand that narrows it silences the shop's override of the
            // removed field without touching the stored row, so widening it
            // again brings the override back.
            $allowed = $this->merger->filterToAllowList((array) $shopRow->definition, $shopEditable);
            $overriddenPaths = $this->merger->changedPaths($definition, $allowed);

            if ($allowed !== []) {
                $definition = $this->merger->merge($definition, $allowed);
                $scope = PrintTemplateScope::Shop;
                $version = (int) $shopRow->version;
                $effectiveFrom = $shopRow->effective_from ?? $effectiveFrom;
                $shopRowId = (string) $shopRow->id;
                $shopUpdated = $shopRow->updated_at?->toIso8601String();
                $updatedAt = $updatedAt === null || ($shopUpdated !== null && $shopUpdated > $updatedAt)
                    ? $shopUpdated
                    : $updatedAt;
            }
        }

        return new ResolvedTemplate(
            $kind,
            $definition,
            $scope,
            $version,
            $effectiveFrom,
            $brandRowId,
            $shopRowId,
            $overriddenPaths,
            $updatedAt,
        );
    }

    /**
     * Every kind resolved for a branch — the sync-DOWN payload (§5).
     *
     * @return array<string, ResolvedTemplate>
     */
    public function allForBranch(string $branchId, bool $withTrashed = false): array
    {
        $out = [];
        foreach (PrintTemplateKind::cases() as $kind) {
            $out[$kind->value] = $this->forBranch($kind, $branchId, $withTrashed);
        }

        return $out;
    }

    /**
     * Resolve a SPECIFIC historical version — the reprint path (TR-28/TR-29).
     *
     * Soft-deleted rows are included unconditionally: the brand may be gone,
     * the branch may be gone, and 再発行 still has to be truthful (TR-39/S4).
     * Returns null when the version is genuinely lost, which is the caller's
     * cue to print the current version WITH a visible "template has changed"
     * marker rather than silently substituting it (TR-29).
     */
    public function forVersion(
        PrintTemplateKind|string $kind,
        string $branchId,
        int $version,
        ?PrintTemplateScope $scope = null,
    ): ?ResolvedTemplate {
        $kind = $kind instanceof PrintTemplateKind
            ? $kind
            : (PrintTemplateKind::tryFrom($kind) ?? throw new \InvalidArgumentException("Unknown print template kind [{$kind}]."));

        $brandId = $this->brandIdFor($branchId, $kind, warn: false);
        if ($brandId === null) {
            return null;
        }

        $row = PrintTemplate::withTrashed()
            ->where('kind', $kind->value)
            ->where('brand_id', $brandId)
            ->where('version', $version)
            ->when(
                $scope !== null,
                fn ($q) => $q->where('scope', $scope->value),
                fn ($q) => $q->orderByRaw("CASE WHEN scope = 'shop' THEN 0 ELSE 1 END"),
            )
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->first();

        if ($row === null) {
            return null;
        }

        $definition = $this->merger->merge(
            $this->defaults->forKind($kind),
            (array) $row->definition,
        );

        return new ResolvedTemplate(
            $kind,
            $definition,
            $row->scope,
            (int) $row->version,
            $row->effective_from,
            $row->scope === PrintTemplateScope::Brand ? (string) $row->id : null,
            $row->scope === PrintTemplateScope::Shop ? (string) $row->id : null,
            [],
            $row->updated_at?->toIso8601String(),
        );
    }

    /** The system default of a kind — layer 0 on its own (TR-05/TR-14 fallback). */
    public function systemDefault(PrintTemplateKind|string $kind): ResolvedTemplate
    {
        $kind = $kind instanceof PrintTemplateKind
            ? $kind
            : (PrintTemplateKind::tryFrom($kind) ?? throw new \InvalidArgumentException("Unknown print template kind [{$kind}]."));

        return new ResolvedTemplate(
            $kind,
            $this->defaults->forKind($kind),
            PrintTemplateScope::System,
            null, null, null, null, [], null,
        );
    }

    /**
     * The branch's CURRENT brand (TR-07: after an M&A move the branch resolves
     * against its new brand; already-printed jobs keep the version they
     * recorded, which is a fact about the past and never re-resolved).
     */
    private function brandIdFor(string $branchId, PrintTemplateKind $kind, bool $warn = true): ?string
    {
        if (! array_key_exists($branchId, $this->brandOfBranch)) {
            $consoleBrandId = Branch::withTrashed()->whereKey($branchId)->value('console_brand_id');

            $brandId = $consoleBrandId
                ? Brand::withTrashed()->where('console_brand_id', $consoleBrandId)->value('id')
                : null;

            $this->brandOfBranch[$branchId] = (string) ($brandId ?? '');
        }

        $resolved = $this->brandOfBranch[$branchId];

        if ($resolved === '') {
            // A branch with no brand can only ever print layer 0. Warn once
            // per (branch, kind) — the TaxResolver `warnedBrands` guard; this
            // is polled on the workstation's 60s tick, so an unguarded log
            // would bury real incidents.
            $key = $branchId.':'.$kind->value;
            if ($warn && ! isset($this->warnedMissingBrand[$key])) {
                $this->warnedMissingBrand[$key] = true;
                Log::warning('TemplateResolver: branch has no resolvable brand — printing the system default.', [
                    'branch_id' => $branchId,
                    'kind' => $kind->value,
                ]);
            }

            return null;
        }

        return $resolved;
    }

    /**
     * Published, non-retired rows of one layer, newest version first.
     *
     * @return Collection<int, PrintTemplate>
     */
    private function layerRows(
        PrintTemplateKind $kind,
        string $brandId,
        ?string $branchId,
        bool $withTrashed,
    ): Collection {
        $key = implode('|', [$kind->value, $brandId, $branchId ?? '-', $withTrashed ? 't' : 'f']);

        return $this->rowCache[$key] ??= PrintTemplate::query()
            ->when($withTrashed, fn ($q) => $q->withTrashed())
            ->where('kind', $kind->value)
            ->where('brand_id', $brandId)
            ->where('status', PrintTemplateStatus::Published->value)
            ->when(
                $branchId === null,
                fn ($q) => $q->where('scope', PrintTemplateScope::Brand->value)->whereNull('branch_id'),
                fn ($q) => $q->where('scope', PrintTemplateScope::Shop->value)->where('branch_id', $branchId),
            )
            ->orderByDesc('version')
            ->get();
    }

    /**
     * The newest version already in force at the branch.
     *
     * @param  Collection<int, PrintTemplate>  $rows
     */
    private function effectiveRow(Collection $rows, string $branchWallClock): ?PrintTemplate
    {
        foreach ($rows as $row) {
            $from = $row->effective_from;
            // null = in force from the moment it was published (DESIGN §4).
            // A PAST value is equally valid and simply means "now" (TR-11) —
            // it never rewrites a slip that was already printed, because a
            // printed job carries the version it used.
            if ($from === null || $from === '' || (string) $from <= $branchWallClock) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The branch's wall clock as 'Y-m-d H:i:s' — the value `effective_from` is
     * compared against (#1091). Honors Carbon::setTestNow() via BusinessClock.
     */
    private function branchWallClock(string $branchId): string
    {
        return BusinessClock::now($branchId)->format('Y-m-d H:i:s');
    }
}
