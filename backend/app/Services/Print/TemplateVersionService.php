<?php

declare(strict_types=1);

namespace App\Services\Print;

use App\Exceptions\Print\TemplateConflictException;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\PrintTemplate;
use App\Services\Print\Enums\PrintTemplateKind;
use App\Services\Print\Enums\PrintTemplateScope;
use App\Services\Print\Enums\PrintTemplateStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * plan-053 M2 (#1171) — the version lifecycle (DESIGN §4, TASKS T2.2/T2.3).
 *
 *   draft ──(validate)──▶ published ──(explicit retire)──▶ retired
 *
 * Two invariants carry the whole design:
 *
 *  1. **Published is immutable** (TR-08). Every mutation refuses a non-draft
 *     row. A printed slip has to stay explainable years later, which is only
 *     possible if the definition it was printed from can never change.
 *
 *  2. **Publishing does NOT auto-retire the previous version.** DESIGN's state
 *     diagram suggests it, but auto-retiring opens a hole: publish a version
 *     with a FUTURE `effective_from` and the outgoing version would be retired
 *     immediately, leaving a window where the resolver falls all the way back
 *     to the system default and every shop's slip silently changes. So the
 *     newest version already in force simply wins, and `retired` stays an
 *     explicit administrative act meaning "never use this for new prints
 *     again" (TR-13).
 *
 * Rollback is a PUBLISH of the old definition, never an un-publish (TR-38):
 * the history keeps growing forwards, so "what were we printing on the 3rd"
 * is always answerable.
 */
class TemplateVersionService
{
    public function __construct(
        private readonly TemplateValidator $validator,
        private readonly BlockCatalog $catalog,
    ) {}

    /**
     * Create or update THE draft of a scope tuple (DESIGN §2: at most one).
     *
     * @param  array<string, mixed>  $definition
     * @param  list<string>|null  $shopEditable  brand scope only
     * @param  string|null  $expectedLockToken  optimistic lock (TR-09); required
     *                                          once a draft exists
     */
    public function saveDraft(
        PrintTemplateKind $kind,
        PrintTemplateScope $scope,
        Brand $brand,
        ?Branch $branch,
        array $definition,
        ?array $shopEditable = null,
        ?string $notes = null,
        ?string $expectedLockToken = null,
        ?string $actorId = null,
    ): PrintTemplate {
        if ($shopEditable !== null) {
            $this->validator->validateShopEditable($shopEditable, $kind);
        }

        return DB::transaction(function () use ($kind, $scope, $brand, $branch, $definition, $shopEditable, $notes, $expectedLockToken, $actorId) {
            $draft = $this->scopeQuery($kind, $scope, $brand, $branch)
                ->where('status', PrintTemplateStatus::Draft->value)
                ->lockForUpdate()
                ->first();

            if ($draft !== null) {
                // TR-09 — no auto-merge. Two people editing one layout produce
                // a third layout nobody designed; the loser reloads.
                $actual = self::lockToken($draft);
                if ($expectedLockToken === null || ! hash_equals($actual, $expectedLockToken)) {
                    throw TemplateConflictException::staleDraft($expectedLockToken, $actual);
                }

                $draft->fill([
                    'definition' => $definition,
                    'notes' => $notes ?? $draft->notes,
                    // Re-parent to whatever is live NOW so the rebase check at
                    // publish reflects the draft's real base.
                    'parent_version_id' => $this->currentPublished($kind, $scope, $brand, $branch)?->id,
                ]);
                if ($shopEditable !== null) {
                    $draft->shop_editable = $shopEditable;
                }
                $draft->save();

                return $draft->refresh();
            }

            $parent = $this->currentPublished($kind, $scope, $brand, $branch);

            return PrintTemplate::create([
                'organization_id' => $this->organizationIdFor($brand, $branch),
                'brand_id' => $brand->id,
                'branch_id' => $scope === PrintTemplateScope::Shop ? $branch?->id : null,
                'kind' => $kind->value,
                'scope' => $scope->value,
                'version' => $this->nextVersion($kind, $scope, $brand, $branch),
                'status' => PrintTemplateStatus::Draft->value,
                'definition' => $definition,
                'shop_editable' => $shopEditable ?? ($scope === PrintTemplateScope::Brand ? [] : null),
                'parent_version_id' => $parent?->id,
                'notes' => $notes,
                'created_by_id' => $actorId,
            ]);
        });
    }

    /**
     * Publish the draft of a scope tuple.
     *
     * @param  string|null  $effectiveFrom  BRANCH-LOCAL wall clock 'Y-m-d H:i:s'
     *                                      (#1091 — see TemplateResolver); null =
     *                                      in force immediately
     */
    public function publish(
        PrintTemplateKind $kind,
        PrintTemplateScope $scope,
        Brand $brand,
        ?Branch $branch,
        ?string $effectiveFrom = null,
        ?string $notes = null,
        ?string $actorId = null,
        ?string $expectedParentVersionId = null,
    ): PrintTemplate {
        return DB::transaction(function () use ($kind, $scope, $brand, $branch, $effectiveFrom, $notes, $actorId, $expectedParentVersionId) {
            $draft = $this->scopeQuery($kind, $scope, $brand, $branch)
                ->where('status', PrintTemplateStatus::Draft->value)
                ->lockForUpdate()
                ->first();

            if ($draft === null) {
                throw new TemplateConflictException(
                    'PRINT_TEMPLATE_NO_DRAFT',
                    'There is nothing to publish: this scope has no draft.',
                );
            }

            $live = $this->currentPublished($kind, $scope, $brand, $branch);

            // TR-10 — the draft must be based on what is live now. If someone
            // published in between, blindly publishing this draft would revert
            // their change without anyone noticing.
            $claimedParent = $expectedParentVersionId ?? $draft->parent_version_id;
            if ((string) $claimedParent !== (string) $live?->id) {
                throw TemplateConflictException::rebaseRequired((string) $claimedParent ?: null, (string) $live?->id ?: null);
            }

            $brandShopEditable = $scope === PrintTemplateScope::Shop
                ? array_values((array) ($live?->shop_editable ?? $this->brandAllowList($kind, $brand)))
                : [];

            $definition = $this->validator->validateForPublish(
                (array) $draft->definition,
                $kind,
                $scope,
                $brand,
                $branch,
                $brandShopEditable,
            );

            $draft->fill([
                'definition' => $definition,
                'status' => PrintTemplateStatus::Published->value,
                'effective_from' => $this->normalizeEffectiveFrom($effectiveFrom),
                'published_at' => now(),
                'published_by_id' => $actorId,
                'parent_version_id' => $live?->id,
            ]);
            if ($notes !== null) {
                $draft->notes = $notes;
            }
            $draft->save();

            return $draft->refresh();
        });
    }

    /**
     * TR-13 — take a version out of service for NEW prints. The row survives:
     * a reprint of a job that used it must still render (TR-28/TR-29).
     */
    public function retire(PrintTemplate $template, ?string $actorId = null): PrintTemplate
    {
        if ($template->status !== PrintTemplateStatus::Published) {
            throw TemplateConflictException::immutable($template->status->value);
        }

        $template->status = PrintTemplateStatus::Retired->value;
        $template->save();

        return $template->refresh();
    }

    /**
     * TR-38 — republish an old definition as a NEW version. Never an
     * un-publish: history only moves forwards, so "what was printing on the
     * 3rd" stays answerable after any number of mistakes.
     */
    public function rollback(
        PrintTemplate $target,
        ?string $actorId = null,
        ?string $effectiveFrom = null,
    ): PrintTemplate {
        $brand = Brand::withTrashed()->findOrFail($target->brand_id);
        $branch = $target->branch_id ? Branch::withTrashed()->find($target->branch_id) : null;
        $kind = $target->kind;
        $scope = $target->scope;

        return DB::transaction(function () use ($target, $brand, $branch, $kind, $scope, $actorId, $effectiveFrom) {
            // A rollback replaces whatever is being drafted — the intent is
            // "go back to vN", not "go back to vN plus my half-finished edit".
            $this->scopeQuery($kind, $scope, $brand, $branch)
                ->where('status', PrintTemplateStatus::Draft->value)
                ->delete();

            $live = $this->currentPublished($kind, $scope, $brand, $branch);

            $definition = $this->validator->validateForPublish(
                (array) $target->definition,
                $kind,
                $scope,
                $brand,
                $branch,
                $scope === PrintTemplateScope::Shop
                    ? array_values((array) ($live?->shop_editable ?? $this->brandAllowList($kind, $brand)))
                    : [],
            );

            return PrintTemplate::create([
                'organization_id' => $target->organization_id,
                'brand_id' => $target->brand_id,
                'branch_id' => $target->branch_id,
                'kind' => $kind->value,
                'scope' => $scope->value,
                'version' => $this->nextVersion($kind, $scope, $brand, $branch),
                'status' => PrintTemplateStatus::Published->value,
                'definition' => $definition,
                'shop_editable' => $target->shop_editable,
                'effective_from' => $this->normalizeEffectiveFrom($effectiveFrom),
                'parent_version_id' => $live?->id,
                // Auto-generated so the history reads as an intentional act,
                // not an unexplained identical republish.
                'notes' => "Rollback from v{$target->version}",
                'created_by_id' => $actorId,
                'published_by_id' => $actorId,
                'published_at' => now(),
            ]);
        });
    }

    /**
     * Full version history of a scope tuple, newest first (TR-31).
     *
     * @return Collection<int, PrintTemplate>
     */
    public function history(PrintTemplateKind $kind, PrintTemplateScope $scope, Brand $brand, ?Branch $branch): Collection
    {
        return $this->scopeQuery($kind, $scope, $brand, $branch)
            ->with(['createdBy:id,name', 'publishedBy:id,name'])
            ->orderByDesc('version')
            ->get();
    }

    /** The live (published, non-retired, highest version) row of a scope tuple. */
    public function currentPublished(PrintTemplateKind $kind, PrintTemplateScope $scope, Brand $brand, ?Branch $branch): ?PrintTemplate
    {
        return $this->scopeQuery($kind, $scope, $brand, $branch)
            ->where('status', PrintTemplateStatus::Published->value)
            ->orderByDesc('version')
            ->first();
    }

    /** The single draft of a scope tuple, if any. */
    public function currentDraft(PrintTemplateKind $kind, PrintTemplateScope $scope, Brand $brand, ?Branch $branch): ?PrintTemplate
    {
        return $this->scopeQuery($kind, $scope, $brand, $branch)
            ->where('status', PrintTemplateStatus::Draft->value)
            ->first();
    }

    /**
     * The optimistic-lock token of a draft (TR-09).
     *
     * Derived from the draft's CONTENT, not from `updated_at`: MySQL stores
     * timestamps at one-second resolution, so two editors saving inside the
     * same second would both see an unchanged `updated_at` and the second
     * write would silently clobber the first — which is precisely the failure
     * this lock exists to prevent. Content-derived also means two people who
     * happen to save the SAME layout do not fight over it, because they are
     * not actually in conflict.
     */
    public static function lockToken(PrintTemplate $draft): string
    {
        return TemplateChecksum::of([
            'id' => (string) $draft->id,
            'definition' => (array) $draft->definition,
            'shop_editable' => (array) ($draft->shop_editable ?? []),
            'notes' => (string) $draft->notes,
        ]);
    }

    /**
     * Guard every in-place edit path: only a draft may be written (TR-08).
     *
     * @throws TemplateConflictException
     */
    public function assertEditable(PrintTemplate $template): void
    {
        if (! $template->isDraft()) {
            throw TemplateConflictException::immutable($template->status->value);
        }
    }

    /** @return list<string> */
    private function brandAllowList(PrintTemplateKind $kind, Brand $brand): array
    {
        $row = PrintTemplate::query()
            ->brandLayer((string) $brand->id)
            ->where('kind', $kind->value)
            ->where('status', PrintTemplateStatus::Published->value)
            ->orderByDesc('version')
            ->first();

        return array_values((array) ($row?->shop_editable ?? []));
    }

    private function nextVersion(PrintTemplateKind $kind, PrintTemplateScope $scope, Brand $brand, ?Branch $branch): int
    {
        return (int) $this->scopeQuery($kind, $scope, $brand, $branch)
            ->withTrashed()
            ->max('version') + 1;
    }

    /** @return Builder<PrintTemplate> */
    private function scopeQuery(PrintTemplateKind $kind, PrintTemplateScope $scope, Brand $brand, ?Branch $branch)
    {
        return PrintTemplate::query()
            ->where('kind', $kind->value)
            ->where('scope', $scope->value)
            ->where('brand_id', $brand->id)
            ->when(
                $scope === PrintTemplateScope::Shop,
                fn ($q) => $q->where('branch_id', $branch?->id),
                fn ($q) => $q->whereNull('branch_id'),
            );
    }

    private function organizationIdFor(Brand $brand, ?Branch $branch): ?string
    {
        $consoleOrgId = $branch?->console_organization_id ?? $brand->console_organization_id;

        return $consoleOrgId
            ? DB::table('organizations')->where('console_organization_id', $consoleOrgId)->value('id')
            : null;
    }

    /**
     * `effective_from` is a branch-local wall clock, so it is stored VERBATIM
     * (#1091). Parsing it into an instant here is exactly the bug that would
     * make a Hanoi branch switch on Tokyo's clock.
     */
    private function normalizeEffectiveFrom(?string $effectiveFrom): ?string
    {
        $effectiveFrom = $effectiveFrom !== null ? trim($effectiveFrom) : null;
        if ($effectiveFrom === null || $effectiveFrom === '') {
            return null;
        }

        // Accept 'Y-m-d' as shorthand for that business day's midnight.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveFrom) === 1) {
            return $effectiveFrom.' 00:00:00';
        }

        return str_replace('T', ' ', substr($effectiveFrom, 0, 19));
    }
}
