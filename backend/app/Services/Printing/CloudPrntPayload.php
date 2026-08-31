<?php

declare(strict_types=1);

namespace App\Services\Printing;

use App\Services\Print\Enums\PrintTemplateKind;
use App\Services\Print\Renderer\PrintRenderData;
use App\Services\Print\Renderer\PrintRenderDataHydrator;
use InvalidArgumentException;

/**
 * plan-053 T5.4 (#1171) — the envelope stored in `print_jobs.payload` for a
 * transport Cloud renders for.
 *
 * `print_jobs.payload` is a free JSON column and, until now, carried whatever
 * the workstation happened to record on a ws_lan journal row (the factory still
 * writes `{"template": …, "version": 1}`). That is fine for a journal FACT,
 * which nobody re-renders. It is not fine for a cloudprnt job, where the column
 * is the ONLY description of a slip Cloud has to draw. So cloudprnt rows carry
 * a versioned envelope and a payload that does not announce its version is
 * refused — a print path that guesses at its own input format is a print path
 * that eventually prints a guess.
 *
 * ```json
 * {
 *   "schema": "print_render_data/1",
 *   "locale": "ja",
 *   "data":   { …PrintRenderData… },
 *   "tax_summary": { "by_rate": [ {"rate":10,"taxable":1000,"tax":100} ] }
 * }
 * ```
 *
 * `data` is the serialised {@see PrintRenderData}
 * — see {@see PrintRenderDataHydrator} for why that shape rather than a fresh
 * DTO. `tax_summary` is OPTIONAL and its absence means "this slip has no
 * per-rate snapshot", which is a real state the emitters branch on. It must
 * never be defaulted to an all-zero summary (#1092, #1937).
 *
 * Paper width is deliberately NOT in here. It is a property of the machine
 * that ends up printing the job, read from the printer row at serve time; a
 * width baked into the payload would be the enqueuing tier's guess about
 * hardware it cannot see.
 */
final class CloudPrntPayload
{
    public const SCHEMA = 'print_render_data/1';

    public const SCHEMA_KEY = 'schema';

    public const LOCALE_KEY = 'locale';

    /** The label languages the renderer has (see `PrintLabels`). */
    public const LOCALES = ['ja', 'en', 'vi'];

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $taxSummary
     */
    private function __construct(
        public readonly PrintTemplateKind $kind,
        public readonly string $locale,
        public readonly array $data,
        public readonly ?array $taxSummary,
    ) {}

    /**
     * @param  array<array-key, mixed>|null  $payload
     *
     * @throws InvalidArgumentException when the payload is not a slip this
     *                                  build can draw
     */
    public static function fromArray(?array $payload): self
    {
        if ($payload === null || $payload === []) {
            throw new InvalidArgumentException('print payload: empty — nothing to render');
        }

        $schema = $payload[self::SCHEMA_KEY] ?? null;

        if ($schema !== self::SCHEMA) {
            throw new InvalidArgumentException(sprintf(
                'print payload: schema is %s, this build renders %s',
                is_scalar($schema) ? var_export($schema, true) : 'absent',
                self::SCHEMA,
            ));
        }

        $data = $payload[PrintRenderDataHydrator::DATA_KEY] ?? null;

        if (! is_array($data) || $data === []) {
            throw new InvalidArgumentException('print payload: `data` is missing or empty');
        }

        $kindValue = null;
        foreach ($data as $key => $value) {
            if (strtolower((string) $key) === 'kind') {
                $kindValue = is_scalar($value) ? (string) $value : null;

                break;
            }
        }

        // The document kind comes from the payload, NOT from `print_jobs.kind`.
        // They are different vocabularies on purpose: the ledger column is a
        // `PrintJobKind` (8 values — what the retry matrix and the money-document
        // rules key off), while a template is a `PrintTemplateKind` (13 values —
        // what the renderer draws). `receipt` is spelled the same in both, which
        // is exactly why reading one as the other would look correct in testing
        // and then fall over on `vat_invoice`.
        $kind = $kindValue === null ? null : PrintTemplateKind::tryFrom($kindValue);

        if ($kind === null) {
            throw new InvalidArgumentException(sprintf(
                'print payload: `data.kind` is %s, which is not a printable document kind',
                $kindValue === null ? 'absent' : var_export($kindValue, true),
            ));
        }

        $locale = $payload[self::LOCALE_KEY] ?? null;
        $locale = is_string($locale) ? strtolower($locale) : '';

        if (! in_array($locale, self::LOCALES, true)) {
            throw new InvalidArgumentException(sprintf(
                'print payload: `locale` must be one of [%s], got %s',
                implode(', ', self::LOCALES),
                $locale === '' ? 'nothing' : var_export($locale, true),
            ));
        }

        $tax = $payload[PrintRenderDataHydrator::TAX_KEY] ?? null;

        if ($tax !== null && ! is_array($tax)) {
            throw new InvalidArgumentException('print payload: `tax_summary` must be an object or absent');
        }

        return new self(kind: $kind, locale: $locale, data: $data, taxSummary: $tax);
    }
}
