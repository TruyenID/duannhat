<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/**
 * #1950 — what happens to the paper AFTER the content: how (and whether) it is
 * cut, and how far to feed first.
 *
 * The PHP mirror of `escpos.Finishing`
 * (`workstation/internal/printer/escpos/finishing.go`). It is a plain value
 * type rather than `App\Services\Printing\PrinterCapabilityProfile` — named in
 * prose, deliberately NOT imported — for the same reason Go keeps its copy out
 * of the `printer` package: the encoder must stay a leaf that knows about bytes
 * and nothing about printer configuration. The caller translates a profile into
 * one of these.
 *
 * ── The drawer fields are NOT here, deliberately ──────────────────────────
 *
 * Go's struct also carries `DrawerKickSupported` / `DrawerPin` / timings, used
 * by `Encoder.KickDrawer`. Cloud has no drawer path at all — CloudPRNT hands a
 * printer a document, and the till is on the shop floor next to a workstation.
 * Mirroring fields nothing reads would be a contract no side implements, and
 * #1951 (the drawer that does not open) is the issue that decides their shape.
 */
final class Finishing
{
    /** A tear-bar machine. NO cut command may be sent — P-36. */
    public const CUT_NONE = 'none';

    /** ESC/POS `GS V 0`. */
    public const CUT_GS_V_FULL = 'gs_v_full';

    /** ESC/POS `GS V 1` — leaves a tab of paper so the slip stays hanging. */
    public const CUT_GS_V_PARTIAL = 'gs_v_partial';

    /** StarPRNT `ESC d 3`. Feeds n lines itself, so it needs no separate feed. */
    public const CUT_ESC_D = 'esc_d';

    public function __construct(
        /** One of the CUT_* constants. */
        public readonly string $cutMode = self::CUT_GS_V_FULL,
        /**
         * A PHYSICAL quirk, not a preference: the distance from print head to
         * blade differs per chassis, and too little feed slices the last line
         * off the slip. Data, so a shop corrects it without a release.
         */
        public readonly int $feedBeforeCut = 0,
        /**
         * The machine cuts by itself at end of job. Sending a cut as well
         * produces a second, BLANK slip every single time.
         */
        public readonly bool $autoCutPerJob = false,
    ) {}
}
