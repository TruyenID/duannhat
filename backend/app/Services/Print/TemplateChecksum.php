<?php

declare(strict_types=1);

namespace App\Services\Print;

/**
 * plan-053 (#1171) — the definition checksum used by sync DOWN (§5).
 *
 * Recursive key sort before encoding: two definitions that differ only in key
 * ORDER are the same template, and a checksum that disagreed would make every
 * workstation re-download the whole registry after any unrelated PHP array
 * reshuffle.
 *
 * NOTE — this is a CACHE identity, not the #1092 signing scheme. Offline order
 * evidence deliberately does NOT use canonical JSON (cross-language
 * `json_encode` parity is a silent-drift trap that would reject honest
 * orders); here a mismatch only costs one extra download, so JSON is fine.
 */
final class TemplateChecksum
{
    /** @param array<string, mixed> $definition */
    public static function of(array $definition): string
    {
        $canonical = self::sortRecursive($definition);

        return hash('sha256', (string) json_encode(
            $canonical,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function sortRecursive(array $value): array
    {
        $isList = array_is_list($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortRecursive($item);
            }
        }

        // Lists keep their order — block order IS meaning on a receipt.
        if (! $isList) {
            ksort($value);
        }

        return $value;
    }
}
