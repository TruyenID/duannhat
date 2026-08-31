<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

use Illuminate\Support\Facades\Log;

/**
 * plan-053 M5 (#1171) — locale resolution for a definition, ported from
 * `PrintTemplateDefinition.text` in the workstation.
 *
 * TR-19's chain is: the reader's locale → **ja** → en. Japanese sits in the
 * middle rather than English because it is the accounting locale of the
 * fleet's home market — a Vietnamese cashier reading a half-translated
 * template should see the Japanese the accountant signed off, not an English
 * paraphrase nobody reviewed.
 *
 * When even that fails, ANY non-empty entry is used in preference to printing
 * a blank line. A brand that typed words expects words.
 */
final class Definition
{
    /** TR-19: locale → ja → en. */
    public const FALLBACK_CHAIN = ['ja', 'en'];

    /**
     * Locales that exist as themselves; everything else (empty, unknown,
     * "ja-JP") is Japanese — mirroring the workstation's
     * `normalizePrintLocale`.
     */
    public static function normalizeLocale(string $locale): string
    {
        return match (strtolower(trim($locale))) {
            'en' => 'en',
            'vi' => 'vi',
            default => 'ja',
        };
    }

    /**
     * Block KHÔNG khai `enabled` thì được coi là BẬT — đối ứng `isEnabled`
     * bên Go (`print_template_def.go`). Mặc định ngược lại sẽ làm mọi
     * definition cũ (viết trước khi có cờ) in ra tờ giấy trắng.
     *
     * Sống ở đây vì luật này có HAI người đọc — vòng lặp block của
     * {@see PrintRenderer} và các phép hỏi "definition có block X không" của
     * từng họ kind — và bên Go cả hai đều đi qua đúng một `has()`. Hai bản sao
     * của luật này đã lệch một lần và tốn 18 ô parity: `hasBlock` chỉ hỏi block
     * CÓ TỒN TẠI, nên `qr_block` bị TẮT của `receipt`/`red_invoice` vẫn tính là
     * có, và phần lề đuôi trước nhát cắt bị nuốt.
     *
     * @param  array<string, mixed>  $block
     */
    public static function blockIsEnabled(array $block): bool
    {
        return ! array_key_exists('enabled', $block) || $block['enabled'] === true;
    }

    /**
     * Resolve a text block's authored copy.
     *
     * @param  array<string, mixed>  $block
     * @param  bool  $narrow  true below 42 columns — picks `i18n_narrow` when the
     *                        brand authored a short form (the VAT invoice's
     *                        "HOA DON GTGT")
     */
    public static function resolveText(array $block, string $locale, bool $narrow = false): string
    {
        $table = is_array($block['i18n'] ?? null) ? $block['i18n'] : [];
        if ($narrow && is_array($block['i18n_narrow'] ?? null) && $block['i18n_narrow'] !== []) {
            $table = $block['i18n_narrow'];
        }
        if ($table === []) {
            return '';
        }

        $locale = self::normalizeLocale($locale);
        $blockId = (string) ($block['id'] ?? '?');

        if (is_string($table[$locale] ?? null) && $table[$locale] !== '') {
            return $table[$locale];
        }

        foreach (self::FALLBACK_CHAIN as $fallback) {
            if (is_string($table[$fallback] ?? null) && $table[$fallback] !== '') {
                self::warnMissingLocale($blockId, $locale, $fallback);

                return $table[$fallback];
            }
        }

        // Nothing usable in the chain — take any non-empty entry rather than
        // print a blank line where the brand expected words. Sorted so the
        // choice is deterministic across requests.
        $keys = array_keys($table);
        sort($keys);
        foreach ($keys as $key) {
            if (is_string($table[$key] ?? null) && $table[$key] !== '') {
                self::warnMissingLocale($blockId, $locale, (string) $key);

                return $table[$key];
            }
        }

        return '';
    }

    /**
     * Warn ONCE per locale, per process — the `warnedBrands` pattern from
     * TaxResolver.
     *
     * Per print would drown the log of a busy shop (hundreds of slips an
     * hour); per block would repeat the same fact for every string the brand
     * forgot. Once, naming the first block that noticed, is what somebody can
     * actually act on.
     *
     * @var array<string, true>
     */
    private static array $warned = [];

    private static function warnMissingLocale(string $blockId, string $want, string $used): void
    {
        if (isset(self::$warned[$want])) {
            return;
        }
        self::$warned[$want] = true;

        Log::warning('print template locale missing — using fallback', [
            'block' => $blockId,
            'locale' => $want,
            'fallback' => $used,
        ]);
    }

    /** Test seam — the warn-once ledger is process-global by design. */
    public static function resetWarnings(): void
    {
        self::$warned = [];
    }

    /** How many distinct locales have warned so far. */
    public static function warnedLocales(): int
    {
        return count(self::$warned);
    }
}
