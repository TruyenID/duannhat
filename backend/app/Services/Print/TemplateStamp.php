<?php

declare(strict_types=1);

namespace App\Services\Print;

use App\Services\Print\Enums\PrintTemplateScope;
use App\Services\Printing\CloudPrntEnqueueService;
use App\Services\Printing\PrintJobJournalIngestService;

/**
 * plan-053 TR-28 (#1171) — WHICH layout definition drew a sheet, as one string.
 *
 * This is the value stored in `print_jobs.template_version`, and it is written
 * by two producers that must agree byte for byte: the workstation (Go —
 * {@see PrintJobJournalIngestService} receives it over
 * sync-UP) and Cloud itself
 * ({@see CloudPrntEnqueueService}). A shape that drifted
 * between them would make the column unreadable exactly when it matters — an
 * auditor holding a 赤伝 and the invoice it reverses, asking whether the two came
 * off the same layout.
 *
 * ── Why scope AND version, not just the number ────────────────────────────
 *
 * A version number alone does not identify a definition: brand v3 and shop v3
 * are different documents, and {@see TemplateResolver::forVersion()} needs both
 * to address the right row. `system:0` is the code-shipped layer 0, which has no
 * database row and therefore no version of its own — 0 is its permanent name,
 * not a placeholder.
 *
 * ── NULL is the one value this class never produces ───────────────────────
 *
 * A NULL column means the sheet was drawn by the workstation's LEGACY
 * FORMATTER — Go code rather than a published definition, carrying no version
 * at all. That is the state every shop is in until the
 * `print_template_renderer_enabled` flag is turned on (T5.4). Filling it in with
 * `system:0` would send a reprint to the embedded default for a sheet a
 * formatter drew, which is precisely the silent divergence TR-28 exists to
 * prevent — so the distinction has to survive every layer that touches it.
 */
final class TemplateStamp
{
    /** Widest a stamp can be — `unknown:` plus room for any realistic version. */
    public const MAX_LENGTH = 32;

    private function __construct(
        public readonly ?PrintTemplateScope $scope,
        public readonly int $version,
    ) {}

    /** The stamp for a resolved template, ready to store. */
    public static function of(ResolvedTemplate $resolved): string
    {
        return $resolved->sourceScope->value.':'.(int) ($resolved->version ?? 0);
    }

    /**
     * Read a stored stamp back.
     *
     * Returns null for NULL/blank (the legacy formatter — nothing to resolve)
     * AND for anything malformed. A caller that cannot parse the stamp must fall
     * back to resolving the current template, which is what every caller here
     * does; guessing at a half-readable value would be worse than admitting the
     * provenance is lost.
     *
     * `unknown:N` — the workstation's word for a cache row whose scope column
     * was never filled — parses to a null scope with the version kept, so a
     * caller can still say "version 4 of something" without claiming which
     * layer.
     */
    public static function parse(?string $stamp): ?self
    {
        $stamp = trim((string) $stamp);

        if ($stamp === '' || ! str_contains($stamp, ':')) {
            return null;
        }

        [$scope, $version] = explode(':', $stamp, 2);
        $scope = trim($scope);

        // An empty scope is not the same as `unknown`. `unknown:4` is a word the
        // workstation writes on purpose ("a cache row whose layer we lost");
        // `:4` is a mangled string, and treating the two alike would launder a
        // corrupt value into a readable-looking provenance.
        if ($scope === '' || ! preg_match('/^\d+$/', trim($version))) {
            return null;
        }

        return new self(
            PrintTemplateScope::tryFrom($scope),
            (int) trim($version),
        );
    }

    /** True when the stamp names the code-shipped layer 0 — no row to look up. */
    public function isSystemDefault(): bool
    {
        return $this->scope === PrintTemplateScope::System || $this->version === 0;
    }

    public function toString(): string
    {
        return ($this->scope?->value ?? 'unknown').':'.$this->version;
    }
}
