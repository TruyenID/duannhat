<?php

declare(strict_types=1);

namespace App\Services\Print;

use App\Services\Print\Enums\PrintTemplateKind;

/**
 * plan-053 (#1171) — DESIGN §4 rule 6: "render the thing before you let anyone
 * publish it". A template that cannot be rendered must fail at PUBLISH, in
 * front of the person who wrote it, never at PRINT in front of a customer
 * (TR-14).
 *
 * The real renderer is Go and lives in the workstation (TASKS T3.1); the PHP
 * renderer arrives in M5 (T5.1). Until then {@see StructuralRenderProbe} runs
 * the part of the check that needs no renderer at all — geometry: does every
 * authored string physically fit the paper, in each locale. That catches the
 * failure this rule exists for (a 58mm shop discovering at the till that the
 * new header overflows) without pretending to be a renderer.
 *
 * TODO(plan-053 M5/T5.1) — swap the binding in a service provider for a probe
 * backed by the real PHP renderer so publish also proves ESC/POS + raster
 * output, and keep the golden fixtures (§7) as the Go↔PHP parity gate.
 */
interface RenderProbe
{
    /**
     * Try to render the definition across the full matrix the plan requires:
     * 2 paper widths × 3 locales × 2 text modes.
     *
     * @param  array<string, mixed>  $definition
     * @return list<array{code: string, path: string, message: string}> violations, empty when it renders
     */
    public function probe(array $definition, PrintTemplateKind $kind): array;
}
