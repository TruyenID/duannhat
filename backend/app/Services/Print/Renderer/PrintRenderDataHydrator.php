<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * plan-053 T5.4 (#1171) — a stored print payload → {@see PrintRenderData}.
 *
 * The one place Cloud turns `print_jobs.payload` back into the object the
 * emitters draw from. Used by the CloudPRNT serving path, which is the first
 * time Cloud renders a slip for a real machine rather than for a preview.
 *
 * ── The payload shape is NOT invented here ────────────────────────────────
 *
 * It is the serialisation of `PrintRenderData` itself — the exact shape the
 * workstation already exports in `print_input_golden.json`. That matters more
 * than it looks: the alternative was to design a fresh "cloud print request"
 * DTO, which would have been a second description of the same slip with
 * nothing to check it against. Because the shape is the fixture's, the
 * hydrator can be gated on the SAME 117 golden cells the Go↔PHP byte parity
 * runs on — see `tests/Feature/Print/CloudPrntPayloadRenderTest.php`. A field
 * this class drops on the floor therefore shows up as a byte mismatch against
 * the workstation, not as a missing line somebody notices on paper.
 *
 * ── Names are matched, not mapped ─────────────────────────────────────────
 *
 * Go marshals the outer struct PascalCase (no json tags) and the nested domain
 * types snake_case (explicit tags); PHP promotes camelCase throughout. Casing
 * and underscores are noise on every side, so both are stripped before
 * comparison. A hand-written key map was the obvious alternative and is worse:
 * it must be edited whenever either side gains a field, and forgetting leaves
 * a silently DEFAULTED value — a zero total that prints as a real zero.
 *
 * ── Unknown keys are refused at the TOP LEVEL only, and that asymmetry was
 *    measured, not assumed ────────────────────────────────────────────────
 *
 * An unplaced field is a fact the slip will not carry, and Cloud renders money
 * documents for a machine with no operator standing over it — so the default
 * is fail-closed: refuse rather than drop, and let the job fail with a reason.
 * That is deliberately stricter than Go's `json.Unmarshal`, which ignores what
 * it does not know.
 *
 * But it can only hold where PHP's shape MIRRORS Go's. It does at the top
 * level: `PrintContractParityTest` asserts `PrintRenderData` carries exactly
 * the fields Go's does, so an unrecognised key there is a genuine producer
 * error. It does NOT hold one level down. The first run of this hydrator over
 * the golden fixture rejected `PrintRenderOrder` for fifteen fields
 * (`branch_id`, `items`, `total_amount`, `is_tax_included`, …) — and every one
 * of them is *supposed* to be missing: PHP's nested VOs are documented
 * PROJECTIONS of much larger Go domain structs, with three tax fields left out
 * ON PURPOSE so nobody is invited to recompute tax in the print layer (#1908,
 * #1925). Refusing there would not be fail-closed; it would refuse every real
 * payload.
 *
 * So: strict at the root, lenient inside. The rule is "be strict exactly where
 * a parity test says the two sides agree", which is also why widening the
 * strictness later requires widening a parity test first.
 *
 * ── It never computes money, and never invents a tax snapshot ─────────────
 *
 * {@see taxSummary()} returns `null` when the payload carries no snapshot, and
 * `null` is a REAL state the emitters branch on (the aggregate 「税」 line,
 * exactly what Go does for an order with no per-rate snapshot). It must never
 * be replaced by a fabricated `ReceiptTaxSummary` — a summary rebuilt with
 * `taxable: 0, tax: 0` prints tax lines that are not true and no test in this
 * repo went red for it (#1092, #1937).
 */
final class PrintRenderDataHydrator
{
    /** Envelope key holding the serialised {@see PrintRenderData}. */
    public const DATA_KEY = 'data';

    /** Envelope key holding the per-rate tax snapshot, if the slip has one. */
    public const TAX_KEY = 'tax_summary';

    /**
     * @param  array<string, mixed>  $row
     *
     * @throws InvalidArgumentException when the payload cannot be placed
     */
    public function hydrate(array $row): PrintRenderData
    {
        /** @var PrintRenderData */
        return $this->build(PrintRenderData::class, $row, strict: true);
    }

    /**
     * The per-rate tax snapshot the slip was issued with, or `null`.
     *
     * Accepts both spellings of the same fact: the `by_rate` shape produced by
     * `OrderTaxBreakdownReads` (Cloud's own aggregator, the eventual producer)
     * and the `Blocks` shape Go exports (`ReceiptTaxSummary` marshalled). They
     * describe identical rows; refusing one of them would mean the parity
     * fixture and the production producer could not share this code path, and
     * a code path only one of them exercises is a code path with no gate.
     *
     * @param  array<string, mixed>|null  $snapshot
     *
     * @throws InvalidArgumentException
     */
    public function taxSummary(?array $snapshot): ?ReceiptTaxSummary
    {
        // No snapshot is NOT an empty snapshot. See the class docblock.
        if ($snapshot === null) {
            return null;
        }

        $rows = null;
        $named = false;

        foreach ($snapshot as $key => $value) {
            $normalized = self::normalizeKey((string) $key);
            if ($normalized === 'byrate' || $normalized === 'blocks') {
                $rows = $value;
                $named = true;

                break;
            }
        }

        if (! $named) {
            throw new InvalidArgumentException(
                'print payload: tax_summary carries neither `by_rate` nor `Blocks`; '
                .'a snapshot that names no rates cannot be told apart from a fabricated one'
            );
        }

        // A NAMED-but-null list is an empty list, not a missing one: Go marshals
        // a nil slice as `null`, so `{"Blocks": null}` is how "this slip was
        // issued with no per-rate rows" arrives on the wire. Collapsing it into
        // the missing-snapshot branch (which returns `null`) would be a
        // different fact, and one the emitters draw differently.
        if ($rows === null) {
            $rows = [];
        }

        if (! is_array($rows)) {
            throw new InvalidArgumentException('print payload: tax_summary rate rows must be an array');
        }

        return ReceiptTaxSummary::fromBreakdown([
            'by_rate' => array_values(array_map(
                function (mixed $block): array {
                    if (! is_array($block)) {
                        throw new InvalidArgumentException('print payload: each tax_summary row must be an object');
                    }

                    $lower = self::normalizeRow($block);

                    foreach (['rate', 'taxable', 'tax'] as $field) {
                        if (! array_key_exists($field, $lower)) {
                            throw new InvalidArgumentException(
                                "print payload: tax_summary row is missing `{$field}` — a partial rate row "
                                .'would print a tax figure nobody computed'
                            );
                        }
                    }

                    return [
                        'rate' => (float) $lower['rate'],
                        'taxable' => (int) round((float) $lower['taxable']),
                        'tax' => (int) round((float) $lower['tax']),
                    ];
                },
                $rows,
            )),
        ]);
    }

    /**
     * @param  class-string  $class
     * @param  array<string, mixed>  $row
     * @param  bool  $strict  refuse keys this class has no field for — true only
     *                        at the root, see the class docblock
     */
    private function build(string $class, array $row, bool $strict = false): object
    {
        $ctor = (new ReflectionClass($class))->getConstructor();

        if ($ctor === null) {
            throw new InvalidArgumentException("print payload: {$class} has no constructor to hydrate");
        }

        $lower = self::normalizeRow($row);
        $elementTypes = self::elementTypes($ctor);
        $args = [];
        $consumed = [];

        foreach ($ctor->getParameters() as $param) {
            $key = self::normalizeKey($param->getName());

            if (! array_key_exists($key, $lower)) {
                if ($param->isDefaultValueAvailable()) {
                    $args[] = $param->getDefaultValue();

                    continue;
                }

                throw new InvalidArgumentException(
                    "print payload: {$class}::\${$param->getName()} is required and the payload has no value for it"
                );
            }

            $consumed[$key] = true;
            $args[] = $this->coerce($class, $param, $lower[$key], $elementTypes);
        }

        $unknown = $strict ? array_diff(array_keys($lower), array_keys($consumed)) : [];

        if ($unknown !== []) {
            sort($unknown);

            throw new InvalidArgumentException(sprintf(
                'print payload: %s has no field for [%s]. Refused rather than dropped — an unplaced field '
                .'is a fact this slip would not carry.',
                $class,
                implode(', ', $unknown),
            ));
        }

        return new $class(...$args);
    }

    /**
     * @param  class-string  $class
     * @param  array<string, class-string>  $elementTypes
     */
    private function coerce(string $class, ReflectionParameter $param, mixed $value, array $elementTypes): mixed
    {
        $type = $param->getType();
        $name = $type instanceof ReflectionNamedType ? $type->getName() : '';

        if ($value === null) {
            if ($type instanceof ReflectionNamedType && $type->allowsNull()) {
                return null;
            }

            if ($param->isDefaultValueAvailable()) {
                return $param->getDefaultValue();
            }

            throw new InvalidArgumentException(
                "print payload: {$class}::\${$param->getName()} is not nullable but the payload sends null"
            );
        }

        if ($name === CarbonImmutable::class) {
            return CarbonImmutable::parse((string) $value);
        }

        if ($name === 'array') {
            if (! is_array($value)) {
                throw new InvalidArgumentException(
                    "print payload: {$class}::\${$param->getName()} must be an array"
                );
            }

            $element = $elementTypes[$param->getName()] ?? null;

            if ($element === null) {
                return $value;
            }

            return array_values(array_map(
                function (mixed $item) use ($element, $class, $param): object {
                    if (! is_array($item)) {
                        throw new InvalidArgumentException(
                            "print payload: every entry of {$class}::\${$param->getName()} must be an object"
                        );
                    }

                    return $this->build($element, $item);
                },
                $value,
            ));
        }

        if (class_exists($name)) {
            if (! is_array($value)) {
                throw new InvalidArgumentException(
                    "print payload: {$class}::\${$param->getName()} must be an object"
                );
            }

            return $this->build($name, $value);
        }

        return match ($name) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => (bool) $value,
            'string' => (string) $value,
            default => $value,
        };
    }

    /**
     * Element class per array-typed constructor parameter, read from the
     * docblock. PHP cannot type "array of Foo", so the element type exists ONLY
     * in `@param list<Foo> $name` — which this family of VOs writes
     * consistently. Parsing it keeps the hydrator following the source: a VO
     * that gains a nested collection is handled the moment it is declared,
     * whereas a hand-maintained property→class map fails in the one direction
     * that matters (a forgotten entry leaves raw arrays where the emitter reads
     * properties).
     *
     * @return array<string, class-string>
     */
    private static function elementTypes(ReflectionMethod $ctor): array
    {
        $doc = $ctor->getDocComment();

        if ($doc === false) {
            return [];
        }

        preg_match_all('/@param\s+(?:list|array)<\s*([A-Za-z0-9_\\\\]+)\s*>\s+\$(\w+)/', $doc, $matches, PREG_SET_ORDER);

        $out = [];

        foreach ($matches as $hit) {
            $short = $hit[1];
            $fqcn = str_contains($short, '\\') ? $short : __NAMESPACE__.'\\'.$short;

            if (class_exists($fqcn)) {
                /** @var class-string $fqcn */
                $out[$hit[2]] = $fqcn;
            }
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return array<string, mixed>
     */
    private static function normalizeRow(array $row): array
    {
        $out = [];

        foreach ($row as $key => $value) {
            $out[self::normalizeKey((string) $key)] = $value;
        }

        return $out;
    }

    private static function normalizeKey(string $name): string
    {
        return str_replace('_', '', strtolower($name));
    }
}
