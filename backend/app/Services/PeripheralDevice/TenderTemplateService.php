<?php

namespace App\Services\PeripheralDevice;

/**
 * #1156 — Resolves a vendor tender-acceptance template from a terminal
 * model string.
 *
 * Templates live in `config/tender_templates.php`, keyed by model slug
 * ('stera', 'starpay', …). Matching is deliberately forgiving: the operator
 * types free text into `metadata.model` ("Stera", "stera terminal",
 * "StarPay-α"), so a template matches when its slug equals — or is contained
 * in — the lowercased model string. First declared match wins, which keeps
 * resolution deterministic if two slugs ever both match.
 *
 * The returned list is a PREFILL suggestion only — the caller
 * (PeripheralDeviceService::create) intersects it with the organization's
 * active org-level tender vocabulary before persisting, and the operator can
 * edit `metadata.accepts` freely afterwards.
 */
class TenderTemplateService
{
    /**
     * Tender keys the given terminal model accepts under the vendor's
     * standard contract, or null when no template matches.
     *
     * @return list<string>|null
     */
    public function acceptsForModel(?string $model): ?array
    {
        if ($model === null || trim($model) === '') {
            return null;
        }

        $normalized = mb_strtolower(trim($model));

        /** @var array<string, list<string>> $templates */
        $templates = config('tender_templates', []);

        foreach ($templates as $slug => $accepts) {
            if ($normalized === $slug || str_contains($normalized, $slug)) {
                return array_values($accepts);
            }
        }

        return null;
    }
}
