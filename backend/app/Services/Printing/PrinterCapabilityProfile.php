<?php

namespace App\Services\Printing;

use App\Services\Printing\Enums\PrintTransport;

/**
 * plan-052 T1.4b/T1.4c (DESIGN §3b) — a printer's capability profile, resolved.
 *
 * The profile is DATA (`printers.model_profile`, or a named preset in
 * `config/printer_profiles.php`). This class is the only thing that reads it,
 * and it answers questions the RENDERER asks — "which cut command", "may I
 * kick the drawer", "can this machine tell me it is out of paper". It never
 * answers questions about CONTENT: what a slip says is one template for every
 * machine in the fleet.
 *
 * Everything merges over `escpos_generic` (P-29 + P-40): an unknown machine
 * prints, and a wizard run that stopped after two questions is strictly better
 * than none.
 */
class PrinterCapabilityProfile
{
    public const DEFAULT_PRESET = 'escpos_generic';

    public const CUT_NONE = 'none';

    public const CUT_GS_V_FULL = 'gs_v_full';

    public const CUT_GS_V_PARTIAL = 'gs_v_partial';

    public const CUT_ESC_D = 'esc_d';

    public const TEXT_MODE_AUTO = 'auto';

    public const TEXT_MODE_NATIVE = 'native';

    public const TEXT_MODE_RASTER = 'raster';

    /** @param array<string, mixed> $data */
    private function __construct(private readonly array $data) {}

    /**
     * Resolve a stored profile. `null`, `[]` or a partial array all land on a
     * complete, usable profile — refusing to print because a shop has not
     * filled in a form is never the right answer (P-29).
     *
     * @param  array<string, mixed>|string|null  $stored  a `model_profile` value, or a preset name
     */
    public static function resolve(array|string|null $stored): self
    {
        $base = self::preset(self::DEFAULT_PRESET);

        if ($stored === null || $stored === [] || $stored === '') {
            return new self($base);
        }

        if (is_string($stored)) {
            return new self(self::deepMerge($base, self::preset($stored)));
        }

        // A stored profile may name a preset to inherit from, then override it.
        if (isset($stored['preset']) && is_string($stored['preset'])) {
            $base = self::deepMerge($base, self::preset($stored['preset']));
        }

        return new self(self::deepMerge($base, $stored));
    }

    /** @return array<string, mixed> */
    private static function preset(string $name): array
    {
        /** @var array<string, mixed>|null $preset */
        $preset = config("printer_profiles.{$name}");

        if (! is_array($preset)) {
            /** @var array<string, mixed> $fallback */
            $fallback = config('printer_profiles.'.self::DEFAULT_PRESET, []);

            return $fallback;
        }

        return $preset;
    }

    /**
     * Recursive merge where the override wins for scalars AND for lists
     * (`quirks: []` must be able to CLEAR the inherited quirks, not append to
     * them — a preset's workaround that a firmware update fixed has to be
     * removable).
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private static function deepMerge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && ! array_is_list($value)) {
                $base[$key] = self::deepMerge($base[$key], $value);

                continue;
            }
            $base[$key] = $value;
        }

        return $base;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    // ── charset / text mode ────────────────────────────────────────────────

    public function supportsKanji(): bool
    {
        return (bool) ($this->data['charset']['kanji'] ?? false);
    }

    /** @return list<string> */
    public function codepages(): array
    {
        return array_values(array_map('strval', $this->data['charset']['codepages'] ?? []));
    }

    public function declaredTextMode(): string
    {
        $mode = (string) ($this->data['text_mode'] ?? self::TEXT_MODE_AUTO);

        return in_array($mode, [self::TEXT_MODE_AUTO, self::TEXT_MODE_NATIVE, self::TEXT_MODE_RASTER], true)
            ? $mode
            : self::TEXT_MODE_AUTO;
    }

    /**
     * P-30 — the ONE decision the renderer needs per block of text.
     *
     * `native` and `raster` are explicit operator choices and are honoured as
     * given. `auto` means "work it out": a machine with a kanji ROM prints
     * text natively (fast); a machine without one turns a block that CONTAINS
     * characters outside its codepages into a bitmap, and leaves the numbers
     * and money alone — rasterising the whole slip on a slow head is how a
     * queue backs up during a rush.
     */
    public function textModeFor(string $block): string
    {
        $declared = $this->declaredTextMode();

        if ($declared !== self::TEXT_MODE_AUTO) {
            return $declared;
        }

        if ($this->supportsKanji()) {
            return self::TEXT_MODE_NATIVE;
        }

        return self::isAscii($block) ? self::TEXT_MODE_NATIVE : self::TEXT_MODE_RASTER;
    }

    private static function isAscii(string $text): bool
    {
        return preg_match('/[^\x00-\x7F]/', $text) !== 1;
    }

    // ── finishing ──────────────────────────────────────────────────────────

    /**
     * The cut command this machine understands, or `none`.
     *
     * An UNRECOGNISED mode resolves to `none`, mirroring the workstation's
     * `Profile.normalised()` (#1950). `model_profile` is a free-form JSON
     * column — nothing validates its contents — so a typo ("gsv_full",
     * "guillotine") can be stored, and until #1950 it did not matter because
     * every machine was sent a hard-coded `ESC d 3` whatever the profile said.
     * Now the value reaches the paper, and P-36 decides what to do with one we
     * cannot act on: send NO cut command. Some cheap firmware prints an
     * unrecognised escape sequence as literal garbage onto the next customer's
     * slip, so guessing a dialect is strictly worse than declining to cut.
     */
    public function cutMode(): string
    {
        $mode = (string) ($this->data['finishing']['cut']['mode'] ?? self::CUT_NONE);

        return in_array($mode, [
            self::CUT_NONE,
            self::CUT_GS_V_FULL,
            self::CUT_GS_V_PARTIAL,
            self::CUT_ESC_D,
        ], true) ? $mode : self::CUT_NONE;
    }

    /**
     * P-36 — a tear-bar machine gets NO cut command. Some cheap firmware
     * prints the unrecognised bytes as literal garbage on the next customer's
     * slip, so "send it and hope" is not a safe default.
     */
    public function cutsPaper(): bool
    {
        return $this->cutMode() !== self::CUT_NONE;
    }

    public function feedBeforeCut(): int
    {
        return max(0, (int) ($this->data['finishing']['cut']['feed_before_cut'] ?? 0));
    }

    public function autoCutPerJob(): bool
    {
        return (bool) ($this->data['finishing']['cut']['auto_cut_per_job'] ?? false);
    }

    /**
     * P-37 — when this is false the UI must HIDE the open-drawer button and
     * warn on a cash tender. Sending a kick that does nothing, and saying
     * nothing about it, is the failure mode where a cashier stands there
     * pressing a button while the queue grows.
     */
    public function supportsDrawerKick(): bool
    {
        return (bool) ($this->data['finishing']['drawer_kick']['supported'] ?? false);
    }

    /** @return array{pin: int, on_ms: int, off_ms: int} */
    public function drawerKickTiming(): array
    {
        $kick = $this->data['finishing']['drawer_kick'] ?? [];

        return [
            'pin' => (int) ($kick['pin'] ?? 2),
            'on_ms' => (int) ($kick['on_ms'] ?? 120),
            'off_ms' => (int) ($kick['off_ms'] ?? 240),
        ];
    }

    public function supportsBuzzer(): bool
    {
        return (bool) ($this->data['finishing']['buzzer']['supported'] ?? false);
    }

    // ── error detection ────────────────────────────────────────────────────

    /** none | status_back | protocol (DESIGN §3b levels A / B / C). */
    public function errorDetectLevel(): string
    {
        $level = (string) ($this->data['error_detect']['level'] ?? 'none');

        return in_array($level, ['none', 'status_back', 'protocol'], true) ? $level : 'none';
    }

    /**
     * P-33 [HARD] — the confidence a successful send on THIS machine earns.
     * Level A can only ever say "the bytes left"; nothing downstream is allowed
     * to upgrade that.
     */
    public function printConfidence(): string
    {
        return $this->errorDetectLevel() === 'none' ? 'sent_only' : 'confirmed';
    }

    /** P-34 — only a machine that can answer is worth asking before an invoice. */
    public function supportsPreflightStatus(): bool
    {
        return $this->errorDetectLevel() !== 'none';
    }

    // ── health ─────────────────────────────────────────────────────────────

    /** tcp_dial | dle_eot | http_ping | poll_silence. */
    public function healthMethod(): string
    {
        $method = (string) ($this->data['health']['method'] ?? 'tcp_dial');

        return in_array($method, ['tcp_dial', 'dle_eot', 'http_ping', 'poll_silence'], true) ? $method : 'tcp_dial';
    }

    public function healthIntervalSeconds(): int
    {
        return max(1, (int) ($this->data['health']['interval_s'] ?? 60));
    }

    public function healthTimeoutMs(): int
    {
        return max(1, (int) ($this->data['health']['timeout_ms'] ?? 3000));
    }

    public function offlineAfterMisses(): int
    {
        return max(1, (int) ($this->data['health']['offline_after_misses'] ?? 3));
    }

    // ── layout / transports / quirks ───────────────────────────────────────

    public function columnsFor(int $paperWidthMm): int
    {
        $key = $paperWidthMm <= 58 ? '58mm' : '80mm';

        return (int) ($this->data['columns'][$key] ?? ($paperWidthMm <= 58 ? 32 : 48));
    }

    /** @return list<string> */
    public function transports(): array
    {
        return array_values(array_map('strval', $this->data['transports'] ?? [PrintTransport::WsLan->value]));
    }

    public function supportsTransport(PrintTransport|string $transport): bool
    {
        $value = $transport instanceof PrintTransport ? $transport->value : $transport;

        return in_array($value, $this->transports(), true);
    }

    /** @return list<string> */
    public function quirks(): array
    {
        return array_values(array_map('strval', $this->data['quirks'] ?? []));
    }

    public function hasQuirk(string $quirk): bool
    {
        return in_array($quirk, $this->quirks(), true);
    }
}
