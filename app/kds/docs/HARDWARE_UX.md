# Hardware UX

Requirements + caveats for running godx-kds on kitchen-mounted tablet hardware. Many of these are **best-effort** per browser/OS limitations — document the gaps explicitly.

## Target hardware

- iPad (Safari 16.4+) — primary target. Mounted landscape, wall-bracketed.
- Android tablet (Chrome) — secondary.
- Mac/PC browser — dev only.

## Touch targets

Minimum sizes (per Apple HIG + kitchen-glove tolerance):

| Element | Min size |
|---|---|
| Tap targets (item rows, links, menus) | 44pt / 44px |
| Primary action buttons (bump, bump-all) | 56pt / 56px |
| Icon-only buttons (settings, close, 3-dot) | 44pt × 44pt minimum touch surface |

Tailwind tokens: `min-h-11` (44px), `min-h-14` (56px). Applied throughout `components/dashboard/`.

## Orientation

Manifest declares `orientation: "landscape"` (Phase 6 with vite-plugin-pwa). Browser enforcement is **best-effort**:
- Safari iOS: respects landscape orientation when installed as PWA
- Chrome Android: respects when installed as PWA
- Desktop browsers: ignore (no rotation)

CSS fallback: if portrait detected, show a "rotate device" hint. Not yet implemented — add in Phase 6.

## Fullscreen

Manifest declares `display: "fullscreen"` (Phase 6). Effect when installed as PWA:
- iPad Safari: hides Safari chrome on PWA launch (after Add to Home Screen)
- Chrome Android: launches in fullscreen
- Browser tab (not installed): standard browser chrome

## Screen Wake Lock

Implementation: `navigator.wakeLock.request("screen")` on dashboard mount (Phase 5 via WakeLockProvider).

Caveats:
- iPad Safari 16.4+ supports Wake Lock API ✓
- iPad Safari < 16.4 — NOT supported. Tablet relies on OS auto-lock setting being disabled manually.
- Wake lock automatically releases when tab loses visibility. WakeLockProvider re-acquires on visibility change.

**Best-effort summary:** Wake Lock works on modern tablets in PWA mode. On older iPads, document operator instruction: Settings → Display & Brightness → Auto-Lock → Never.

## Audio chime

Implementation: `HTMLAudioElement` preloaded at app mount, played on `order_created` WS event (Phase 5).

Browser autoplay restrictions:
- All modern browsers require a user gesture before audio can play
- Pairing screen includes "Test âm thanh" / "音声テスト" button — pressing it unlocks audio for the session
- If audio fails, KDS still functions (visual signal is primary)

Chime file: `public/sounds/new-order.mp3` — Phase 5 will add. Use CC0-licensed short bell sound (Mixkit/Freesound).

## Theme

Defaults to `system` (OS preference). Settings drawer allows manual override to `light` or `dark`.

Kitchen lighting varies:
- Bright fluorescent / LED → light mode preferred (reduces glare)
- Dim or warm lighting → dark mode preferred (reduces eye strain)

Per session decision: KDS does NOT force dark mode. Operator picks.

## Age-based color coding

Ticket border color shifts as time elapses since `opened_at`:

| Age | Border |
|---|---|
| 0–5 min | `border-green-500` |
| 5–10 min | `border-amber-500` |
| > 10 min | `border-red-500 animate-pulse` |

Implementation: `ageColorClass(minutes)` in `src/lib/utils.ts`. TicketCard re-renders every 30s to track elapsed time (no full per-second re-render needed for minute-granularity).

These thresholds match common kitchen workflow expectations but are not tuned to specific brand SLAs. Adjustable in future.

## Viewport + zoom

`<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" />` (set in `index.html`).

Prevents accidental pinch-zoom during kitchen handling. Trade-off: accessibility users can't manually zoom. KDS is a controlled-environment app, not a public-facing accessibility target.

## Toast notifications

Sonner config: `position: top-right`, `duration: 2s`, `expand: false`. Compact + auto-dismiss so toasts don't obscure tickets.

## Anti-patterns

Avoid:
- Long-press gestures (Safari iOS interprets long-press as text selection / context menu — conflicts with single-tap-to-advance behavior). Use explicit 3-dot menus instead — already implemented in ItemRow.
- Modal dialogs that cover the whole screen — bếp can't see incoming orders while modal open. Use slide-out drawers (Settings) instead.
- Confirmation prompts for routine actions (bump). Single tap = single action. Mistakes are reversible via 3-dot menu set-to-other-status.

## Performance

Target: 60fps scroll, sub-100ms tap response, < 50ms re-render on bump.

Current optimizations:
- Optimistic UI update on bump (useBump onMutate) — no waiting for server roundtrip
- React Query staleTime 5s on orders — avoids over-fetching during burst of bumps
- TicketCard re-render limited to 30s tick (age color updates)

To monitor: if grid exceeds ~50 active tickets, consider virtualization (Phase 7 measurement).

## See also

- `src/lib/utils.ts` — age color helper
- `src/app/dashboard/components/` — all components implementing this guide
- Plan-027 DESIGN.md §5 — full hardware UX spec
