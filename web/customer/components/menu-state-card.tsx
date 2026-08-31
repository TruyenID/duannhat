import type { ReactNode } from "react";
import type { LucideIcon } from "lucide-react";

import { cn } from "@/lib/utils";

/**
 * #1750 — the one card every "you asked for a menu and there isn't one" screen
 * is built from: shop closed, menu outside its schedule window, menu failed to
 * load, menu is empty.
 *
 * To the customer those four are the same moment — they opened the store and
 * got no dishes — so they must look like the same thing. They did not: the two
 * schedule-driven ones had this card, while the load failure was a line of red
 * text and the empty menu a line of grey text, both reading like a crash rather
 * than a state. Keeping the markup in one place is what stops that drifting
 * apart again.
 *
 * Presentational only — every caller owns its own copy, icon and actions.
 */

/** Badge/border colour. Tone carries meaning, not decoration. */
export type MenuStateTone =
  /** Time-based and self-resolving: closed shop, menu outside its hours. */
  | "amber"
  /** Something went wrong and a retry may fix it. */
  | "danger"
  /** Nothing wrong, nothing to show: the menu is genuinely empty. */
  | "neutral";

const TONE_STYLES: Record<MenuStateTone, { border: string; badge: string }> = {
  amber: { border: "border-amber-200", badge: "bg-amber-50 text-amber-700" },
  danger: { border: "border-red-200", badge: "bg-red-50 text-red-600" },
  neutral: { border: "border-neutral-200", badge: "bg-neutral-100 text-neutral-500" },
};

interface MenuStateCardProps {
  icon: LucideIcon;
  tone: MenuStateTone;
  /** Must be unique per screen — wires `aria-labelledby` to the heading. */
  titleId: string;
  title: string;
  description: string;
  /** Optional `<dl>` detail block (e.g. next opening time). */
  details?: ReactNode;
  /** Small print below the details. */
  hint?: string;
  /**
   * Buttons / links. Stacked full width on mobile, side by side from `sm` —
   * so give each child `w-full sm:w-auto`.
   */
  actions?: ReactNode;
}

export default function MenuStateCard({
  icon: Icon,
  tone,
  titleId,
  title,
  description,
  details,
  hint,
  actions,
}: MenuStateCardProps) {
  const toneStyles = TONE_STYLES[tone];

  return (
    // `flex-1` alone centred nothing: the takeaway page wrapper is a plain
    // `min-h-screen` block, not a flex column, so the card hugged the header.
    // `min-h-[60vh]` gives it something to centre inside no matter which
    // parent renders it.
    <main className="flex min-h-[60vh] flex-1 items-center justify-center bg-[#FAFAFA] px-4 py-10">
      <section
        aria-labelledby={titleId}
        className={cn(
          "w-full max-w-lg rounded-2xl border bg-white p-6 text-center shadow-sm md:p-8",
          toneStyles.border,
        )}
      >
        <div
          className={cn(
            "mx-auto mb-4 flex size-12 items-center justify-center rounded-full",
            toneStyles.badge,
          )}
        >
          <Icon className="size-6" aria-hidden="true" />
        </div>

        <h2 id={titleId} className="text-xl font-bold text-neutral-900">
          {title}
        </h2>

        <p className="mt-2 text-sm leading-6 text-neutral-600">{description}</p>

        {details}

        {hint && <p className="mt-4 text-xs leading-5 text-neutral-500">{hint}</p>}

        {actions && (
          <div className="mt-5 flex flex-col gap-2 sm:flex-row sm:justify-center">
            {actions}
          </div>
        )}
      </section>
    </main>
  );
}
