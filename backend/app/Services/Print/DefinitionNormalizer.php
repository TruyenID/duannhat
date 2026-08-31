<?php

declare(strict_types=1);

namespace App\Services\Print;

/**
 * #1181 — make a definition safe to hand to a parser that is not PHP.
 *
 * ── The bug this closes ───────────────────────────────────────────────────
 *
 * PHP has one array type, so an empty MAP and an empty LIST are the same
 * value, and `json_encode([])` picks `[]`. The workstation decodes `i18n`
 * into a Go `map[string]string`, and Go refuses an array there:
 *
 *   json: cannot unmarshal array into Go struct field .i18n of type map[string]string
 *
 * `ParsePrintTemplateDefinition` returns that error for the WHOLE definition,
 * so a single block with no authored text — the default state of `footer_text`,
 * `greeting`, `header_text` and `shift_signature` on every kind — made the
 * entire template unparseable. The workstation then does the safe thing
 * (TR-14) and falls back to its embedded default, which means brand and shop
 * edits would have silently never arrived: the slip would look right, the
 * registry would look live, and nothing HQ published would take effect.
 *
 * ── The fix ───────────────────────────────────────────────────────────────
 *
 * Drop the key instead of emitting an ambiguous empty container. Absent and
 * empty mean exactly the same thing on both sides — Go's `omitempty` produces
 * a nil map and `text()` returns "" for `len(table) == 0`, while PHP's
 * `$block['i18n'] ?? []` reads absent as empty — so nothing about rendering
 * changes. Only the ambiguity goes away.
 *
 * Casting to `stdClass` would also work on the wire, but it would leak an
 * object into `TemplateChecksum::sortRecursive`, the validator and the merger,
 * all of which are written against arrays. Dropping the key keeps one shape
 * everywhere.
 *
 * Applied in {@see ResolvedTemplate}, which every resolve path constructs, and
 * in {@see SystemTemplateDefaults} so the exported parity fixture matches what
 * sync DOWN actually sends.
 */
final class DefinitionNormalizer
{
    /**
     * Map-typed block props. An empty value for any of these must be omitted
     * rather than encoded, because the consumer expects an object.
     */
    private const MAP_PROPS = ['i18n', 'i18n_narrow'];

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public static function forTransport(array $definition): array
    {
        if (! isset($definition['blocks']) || ! is_array($definition['blocks'])) {
            return $definition;
        }

        $blocks = [];
        foreach ($definition['blocks'] as $block) {
            $blocks[] = is_array($block) ? self::normalizeBlock($block) : $block;
        }
        $definition['blocks'] = $blocks;

        return $definition;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private static function normalizeBlock(array $block): array
    {
        foreach (self::MAP_PROPS as $prop) {
            if (array_key_exists($prop, $block) && $block[$prop] === []) {
                unset($block[$prop]);
            }
        }

        return $block;
    }
}
