/**
 * plan-056 — one disclosure widget for the three nested levels of the "Tồn món"
 * detail (variants · topping groups · a topping's add-on prices).
 *
 * ## Why a shared component rather than three inline chevrons
 *
 * The three levels are visually nested, so the ONE thing that must never drift
 * between them is the affordance: same chevron, same direction, same hit area,
 * same keyboard behaviour. Three hand-rolled disclosures drift on the first
 * edit, and a header that looks tappable but is not — or one that reads
 * "expanded" while showing nothing — costs a cashier a real second mid-service.
 *
 * ## Two things here are not cosmetic
 *
 *   · The trigger is a `<button type="button">`. This tree renders inside a
 *     `<form>` on some screens, and a bare `<button>` defaults to submit — so
 *     opening a topping group would fire the form.
 *   · `aria-expanded` + `aria-controls` point at the panel, and the panel is
 *     UNMOUNTED when closed rather than hidden with CSS. A screen reader
 *     walking a collapsed section must not find forty switches in it, and a
 *     shop with 300 dishes must not pay to render every topping of every one.
 *
 * The `count` slot is deliberately a ReactNode, not a number: every level wants
 * to say something different in it ("3 mục · 2/3 đang bán", "5/6"), and forcing
 * them through one format string is how a badge ends up lying about a level it
 * was not written for.
 */

import { useState, type ReactNode } from "react";
import { ChevronDown, ChevronRight } from "lucide-react";
import { cn } from "@/lib/utils";

export interface CollapsibleSectionProps {
  /** Stable within one dish — used for `aria-controls`. */
  id: string;
  title: ReactNode;
  /** Badge / summary shown next to the title, e.g. "3 mục · 2/3 đang bán". */
  summary?: ReactNode;
  /** Rendered right-aligned in the header, e.g. a section-level action. */
  trailing?: ReactNode;
  defaultOpen?: boolean;
  /** Visual weight — nested levels step down. */
  tone?: "section" | "group" | "row";
  children: ReactNode;
  "data-testid"?: string;
}

export function CollapsibleSection({
  id,
  title,
  summary,
  trailing,
  defaultOpen = false,
  tone = "section",
  children,
  "data-testid": testId,
}: CollapsibleSectionProps) {
  const [open, setOpen] = useState(defaultOpen);

  return (
    <div
      className={cn(
        "overflow-hidden rounded-md border",
        tone === "section" && "bg-background",
        tone === "group" && "bg-muted/20",
        tone === "row" && "border-dashed bg-background",
      )}
      data-slot="collapsible-section"
    >
      <div className="flex items-center gap-1">
        <button
          type="button"
          onClick={() => setOpen((v) => !v)}
          aria-expanded={open}
          aria-controls={`${id}-panel`}
          // h-11 — this is a tablet held by someone mid-service, and the whole
          // header is the target, not just the chevron.
          className={cn(
            "flex min-h-11 flex-1 items-center gap-2 px-3 text-left transition-colors hover:bg-accent/50",
            tone === "section" && "text-sm font-medium",
            tone === "group" && "text-sm font-medium",
            tone === "row" && "text-xs",
          )}
          data-testid={testId}
        >
          {open ? (
            <ChevronDown className="size-4 shrink-0 text-muted-foreground" />
          ) : (
            <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
          )}
          <span className="min-w-0 flex-1 truncate">{title}</span>
          {summary != null && (
            <span className="shrink-0 text-[11px] font-normal text-muted-foreground tabular-nums">
              {summary}
            </span>
          )}
        </button>
        {trailing != null && <div className="shrink-0 pr-3">{trailing}</div>}
      </div>

      {open && (
        <div id={`${id}-panel`} className="border-t p-2">
          {children}
        </div>
      )}
    </div>
  );
}
