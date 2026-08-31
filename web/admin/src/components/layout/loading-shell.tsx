import { Spinner } from "@godxjp/ui";

/**
 * Full-viewport centered spinner used by HQ / Shop layouts while the
 * scope-resolution endpoint (`/api/v1/hq/{slug}` or `/api/v1/shops/{slug}`)
 * is in flight. Rendering anything else before the scope is known means
 * showing a navigation shell with a placeholder slug — confusing UX, since
 * the user has nothing to interact with yet anyway.
 */
export function LoadingShell() {
  return (
    <div className="flex min-h-screen items-center justify-center">
      <Spinner className="size-5 text-muted-foreground" />
    </div>
  );
}
