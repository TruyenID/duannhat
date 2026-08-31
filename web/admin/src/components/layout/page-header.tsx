import Link from "next/link";
import { ArrowLeft, RefreshCw } from "lucide-react";
import { Button } from "@godxjp/ui";
import { Spinner } from "@godxjp/ui";

export interface PageHeaderProps {
  title: string;
  description?: string;
  backHref?: string;
  /** Intercept the back arrow (e.g. to confirm unsaved changes). Takes
   *  precedence over `backHref` — renders a button instead of a link. */
  onBack?: () => void;
  /** Inline node rendered next to the title/description (e.g. an "unsaved"
   *  badge), so status sits with the title on the left rather than the actions. */
  titleBadge?: React.ReactNode;
  /** Pass the query's `refetch` function to show a refresh button in the header. */
  onRefresh?: () => void;
  /** Pass the query's `isFetching` to show a spinner while refreshing. */
  isRefreshing?: boolean;
  children?: React.ReactNode;
}

export function PageHeader({
  title,
  description,
  backHref,
  onBack,
  titleBadge,
  onRefresh,
  isRefreshing,
  children,
}: PageHeaderProps) {
  const backClass =
    "inline-flex size-7 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground";
  return (
    <div className="sticky top-0 z-30 flex h-12 shrink-0 items-center justify-between border-b bg-background px-4">
      <div className="flex items-center gap-2">
        {onBack ? (
          <button type="button" onClick={onBack} className={backClass}>
            <ArrowLeft className="size-4" />
          </button>
        ) : (
          backHref && (
            <Link href={backHref} className={backClass}>
              <ArrowLeft className="size-4" />
            </Link>
          )
        )}
        <h1 className="text-lg font-semibold">{title}</h1>
        {description && <span className="text-xs text-muted-foreground">{description}</span>}
        {titleBadge}
      </div>
      {(onRefresh || children) && (
        <div className="flex items-center gap-1.5">
          {onRefresh && (
            <Button
              variant="ghost"
              size="icon"
              className="size-7 text-muted-foreground"
              onClick={onRefresh}
              disabled={isRefreshing}
              title="Refresh"
            >
              {isRefreshing ? <Spinner className="size-3.5" /> : <RefreshCw className="size-3.5" />}
            </Button>
          )}
          {children}
        </div>
      )}
    </div>
  );
}
