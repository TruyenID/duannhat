<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

use App\Services\Print\Enums\PrintTemplateKind;
use Illuminate\Http\Request;

/**
 * plan-053 M5 (#1171) TR-32 — the shared query contract of the two preview
 * endpoints (HQ brand layer + shop override layer).
 *
 * It lives here rather than in either controller because both must answer the
 * same request identically: a brand and a shop looking at "the 58mm Vietnamese
 * receipt" have to be looking at the same slip, or the override screen is
 * comparing two different things and calling the difference an override.
 */
final class PreviewRequest
{
    /** The two real rolls. Anything else is a 422 rather than a silent 48. */
    public const PAPERS = ['58mm' => 32, '80mm' => 48];

    /**
     * A slip has ~40 blocks; the ceiling exists so a preview stays a bounded
     * rendering job rather than an open invitation to compose a million lines.
     */
    private const MAX_BLOCKS = 200;

    /** @param array<string, mixed>|null $definition */
    private function __construct(
        public readonly string $locale,
        public readonly string $paper,
        public readonly int $columns,
        public readonly bool $useDraft,
        public readonly ?array $definition,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $validated = $request->validate([
            'locale' => ['sometimes', 'string', 'in:ja,en,vi'],
            'paper' => ['sometimes', 'string', 'in:58mm,80mm'],
            // A brand must be able to look at what it is ABOUT to publish —
            // previewing only the live version would make the editor useless
            // for the one job it exists for.
            'source' => ['sometimes', 'string', 'in:draft,published'],
            /*
             * The editor holds unsaved edits, and those are exactly what the
             * author wants to look at. Reading only the PERSISTED draft would
             * force a write on every preview — which bumps the optimistic-lock
             * token and 409s the other tab (TR-09) — so the caller may hand the
             * definition over instead. This is still a READ: nothing is stored,
             * and the answer comes from the same renderer either way.
             */
            'definition' => ['sometimes', 'array'],
            'definition.blocks' => ['required_with:definition', 'array', 'max:'.self::MAX_BLOCKS],
        ]);

        $paper = (string) ($validated['paper'] ?? '80mm');

        /** @var array<string, mixed>|null $definition */
        $definition = isset($validated['definition']) ? (array) $validated['definition'] : null;

        return new self(
            locale: Definition::normalizeLocale((string) ($validated['locale'] ?? 'ja')),
            paper: $paper,
            columns: self::PAPERS[$paper],
            useDraft: ($validated['source'] ?? 'draft') === 'draft',
            definition: $definition,
        );
    }

    /** @param array<string, mixed> $definition */
    public function svg(array $definition, PrintTemplateKind $kind): string
    {
        return app(SvgRenderer::class)->render($definition, $kind, $this->locale, $this->columns);
    }
}
