/**
 * Per-dialog render error boundary (#1738 — the missing half of #284/#279 DoD).
 *
 * `main.tsx` already wraps the whole tree in an `ErrorBoundary`, which saves the
 * app from a white screen. But an app-level boundary UNMOUNTS THE WHOLE TREE: a
 * render crash inside the payment or topping dialog takes the order screen the
 * cashier was working on with it. That is exactly the loss of context #279
 * describes, one order of magnitude smaller.
 *
 * A boundary around each dialog body keeps the order intact: the dialog alone
 * collapses into a "close and reopen" card, the cashier reopens it, and the
 * order is still there.
 *
 * WHY THE BOUNDARY LIVES INSIDE `DialogContent`, NOT AROUND IT
 * ------------------------------------------------------------
 * `DialogContent` is what renders the portal, the overlay and the close button.
 * A boundary placed *around* it would replace all of that with a bare div — the
 * dialog would simply vanish, and the cashier would have no way to dismiss the
 * (now invisible) modal. Placing the boundary *inside* keeps the modal chrome
 * and swaps only its body.
 *
 * WHY A LOCAL `DialogContent` INSTEAD OF WRAPPING 22 CALL SITES BY HAND
 * ---------------------------------------------------------------------
 * `DialogContent` comes from `@godxjp/ui` (a shared repo — admin-web and
 * workstation-app consume it too), so it cannot be patched there without a
 * cross-repo decision. Re-exporting a pos-web-local `DialogContent` that wires
 * the boundary in gives every dialog a boundary from ONE place: there is no
 * per-call-site step to forget, and adding a 23rd dialog inherits it for free.
 * `dialog-boundary.arch.test.ts` keeps `@godxjp/ui`'s raw `DialogContent` out of
 * the app so the local one cannot be bypassed.
 *
 * The happy path adds NO DOM node — `DialogBoundary` is a class component that
 * returns `children` untouched — so the CSS layout of all 22 dialogs (grid /
 * flex children of `DialogContent`) is byte-for-byte what it was.
 */

import { Component, type ComponentProps, type ErrorInfo, type ReactNode } from "react";
import {
  Button,
  DialogClose,
  DialogContent as UiDialogContent,
  DialogDescription,
  DialogTitle,
} from "@godxjp/ui";
import { TriangleAlertIcon } from "lucide-react";
import { captureException } from "@/lib/sentry";
import { getT } from "@/providers/app-provider";

interface FallbackProps {
  error: Error;
  reset: () => void;
}

/**
 * Deliberately uses `getT()` (reads localStorage) rather than `useTranslation()`
 * — the fallback must not depend on a React context that a crashing subtree may
 * itself have been mid-way through consuming. A fallback that throws would
 * escalate to the app boundary and lose the order anyway.
 *
 * Renders a `DialogTitle`: Radix warns (a11y) when a dialog has no accessible
 * name, and the crashing body was the one providing it.
 */
function DialogErrorFallback({ error, reset }: FallbackProps) {
  const t = getT();
  return (
    <div className="flex flex-col items-center gap-3 px-6 py-8 text-center">
      <span className="flex size-12 items-center justify-center rounded-full bg-destructive/10 text-destructive">
        <TriangleAlertIcon className="size-6" />
      </span>
      <DialogTitle className="text-base font-semibold">
        {t("dialog.error.title")}
      </DialogTitle>
      <DialogDescription className="text-sm text-muted-foreground">
        {t("dialog.error.desc")}
      </DialogDescription>
      {import.meta.env.DEV && (
        <pre className="max-h-40 w-full overflow-auto rounded bg-muted p-3 text-left text-xs">
          {error.message}
          {"\n"}
          {error.stack}
        </pre>
      )}
      <div className="mt-1 flex gap-2">
        <Button variant="outline" onClick={reset}>
          {t("common.retry")}
        </Button>
        <DialogClose asChild>
          <Button>{t("common.close")}</Button>
        </DialogClose>
      </div>
    </div>
  );
}

interface BoundaryProps {
  children: ReactNode;
  /** Test seam — overrides the default fallback card. */
  fallback?: (error: Error, reset: () => void) => ReactNode;
}

interface BoundaryState {
  error: Error | null;
}

/**
 * Error boundary scoped to one dialog body. Exported so a dialog that renders
 * several independent panels can subdivide further; every `DialogContent` in
 * this app already gets one implicitly.
 */
export class DialogBoundary extends Component<BoundaryProps, BoundaryState> {
  state: BoundaryState = { error: null };

  static getDerivedStateFromError(error: Error): BoundaryState {
    return { error };
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    // Sentry first — a console write that throws must not lose the report.
    captureException(error, {
      tags: { boundary: "dialog" },
      extra: { componentStack: info.componentStack ?? "" },
    });
    console.error("[DialogBoundary] caught render error", error, info);
  }

  reset = (): void => {
    this.setState({ error: null });
  };

  render(): ReactNode {
    const { error } = this.state;
    if (error) {
      return (
        this.props.fallback?.(error, this.reset) ?? (
          <DialogErrorFallback error={error} reset={this.reset} />
        )
      );
    }
    return this.props.children;
  }
}

/**
 * Drop-in replacement for `@godxjp/ui`'s `DialogContent` with a render error
 * boundary around the body. Import this everywhere; the arch test enforces it.
 */
export function DialogContent({
  children,
  ...props
}: ComponentProps<typeof UiDialogContent>) {
  return (
    <UiDialogContent {...props}>
      <DialogBoundary>{children}</DialogBoundary>
    </UiDialogContent>
  );
}
