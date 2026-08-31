<?php

namespace App\Services\Printing\Enums;

/**
 * plan-052 — how the bytes reach the physical machine, and (DESIGN §1b) WHO
 * owns the queue for it. The queue lives at the tier CLOSEST to the printer;
 * Cloud only syncs the fact "printed or not".
 */
enum PrintTransport: string
{
    /** ESC/POS over TCP/USB, driven by the workstation. Queue: workstation. */
    case WsLan = 'ws_lan';
    /** Epson TM-i, pos-web POSTs ePOS XML straight at it. Queue: operator. */
    case EposHttp = 'epos_http';
    /** Star WebPRNT / mC-Print, same shape as ePOS. Queue: operator. */
    case WebPrnt = 'webprnt';
    /** Star CloudPRNT — the printer polls Cloud, so Cloud IS the closest tier. */
    case CloudPrnt = 'cloudprnt';

    /**
     * Journal mode (DESIGN §1b): the row is an immutable FACT that arrives
     * AFTER the print already happened, carrying its real time. Cloud never
     * schedules, retries or transitions such a row — the workstation owns all
     * of that, and always will, because a Cloud outage must never stop a shop
     * from printing (PR2).
     */
    public function isJournalMode(): bool
    {
        return $this === self::WsLan;
    }

    /**
     * Transports that need Cloud to RENDER the payload itself (there is no
     * workstation at that shop to do it). P-39: they stay fail-closed —
     * offering a shop a transport whose payload we cannot build is worse than
     * not offering it.
     *
     * ## This asks "does Cloud have to build the bytes", NOT "may a shop pick it"
     *
     * The distinction was blurred until T5.4 (2026-08-06) because the answer
     * happened to be the same for all three. It is not any more: `cloudprnt`
     * still needs Cloud to render — the printer polls Cloud and there is no
     * workstation at that shop — and Cloud can now do it. So the meaning of
     * this method is UNCHANGED and its value for `cloudprnt` is still `true`;
     * what moved is {@see cloudCanRender()}.
     *
     * Resist redefining this one to mean "blocked". Every reader of it inverts
     * if you do, and the two readers that matter — the config gate and the
     * serving path — would then agree on a sentence that is false.
     */
    public function requiresCloudRenderer(): bool
    {
        return $this !== self::WsLan;
    }

    /**
     * Can Cloud actually PRODUCE the bytes this transport carries, today?
     *
     * plan-053 T5.4 (#1171). Three separate facts, measured, not assumed:
     *
     *  - `ws_lan` — yes. `App\Services\Print\Renderer\PrintRenderer` emits
     *    ESC/POS that is byte-identical to the workstation's for 117/117
     *    golden cells (`SlipByteParityTest`, T5.2b). Cloud never SENDS these
     *    bytes (the workstation owns that queue, §1b) but it can build them.
     *  - `cloudprnt` — yes, and it is the same payload: the encoder targets
     *    Star mC-Print in **StarPRNT emulation** (`Escpos::ALIGN_LEFT` is
     *    `ESC GS a`, the cut is `ESC d`), which is exactly the dialect a
     *    CloudPRNT client fetches as `application/vnd.star.starprnt`.
     *  - `epos_http` / `webprnt` — **no**. They carry ePOS XML and WebPRNT
     *    markup. NEITHER repo has a renderer for either, so there is no
     *    baseline to compare a new one against; writing one would crown itself
     *    the standard, which is the trap TR-34 exists to avoid. Parity being
     *    green for ESC/POS says nothing about these two, and reading it as if
     *    it did is the wrong inference that kept T5.4 blocked for a while.
     *
     * This is the half that CHANGES as renderers land. Keep it separate from
     * {@see requiresCloudRenderer()}, which states an architectural fact that
     * does not change.
     */
    public function cloudCanRender(): bool
    {
        return match ($this) {
            self::WsLan, self::CloudPrnt => true,
            self::EposHttp, self::WebPrnt => false,
        };
    }

    /**
     * May a shop choose this transport for a printer? P-39, expressed as an
     * EFFECT rather than as a sentence.
     *
     * Fail-closed by construction: a transport Cloud must render for is
     * selectable only once Cloud can render for it. Adding a case to this enum
     * without touching {@see cloudCanRender()} is a compile error (the `match`
     * is exhaustive), so a new transport cannot arrive selectable by accident.
     */
    public function isSelectable(): bool
    {
        return ! $this->requiresCloudRenderer() || $this->cloudCanRender();
    }

    /**
     * The transports a printer write path may accept.
     *
     * @return list<string>
     */
    public static function selectableValues(): array
    {
        return array_values(array_map(
            static fn (self $t): string => $t->value,
            array_filter(self::cases(), static fn (self $t): bool => $t->isSelectable()),
        ));
    }

    /**
     * The transports refused at the config layer, with the reason. P-39 asks
     * for "a clear message", and a bare `The selected transport is invalid`
     * sends an operator to the wrong place — the machine is fine, the feature
     * is not built.
     *
     * @return list<string>
     */
    public static function refusedValues(): array
    {
        return array_values(array_map(
            static fn (self $t): string => $t->value,
            array_filter(self::cases(), static fn (self $t): bool => ! $t->isSelectable()),
        ));
    }
}
