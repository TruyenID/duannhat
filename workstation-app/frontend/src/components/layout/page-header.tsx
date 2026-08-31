import { Link } from "react-router";
import { ArrowLeft } from "lucide-react";

interface PageHeaderProps {
  title: string;
  description?: string;
  backHref?: string;
  children?: React.ReactNode;
}

export function PageHeader({ title, description, backHref, children }: PageHeaderProps) {
  return (
    <div className="sticky top-0 z-30 flex h-12 shrink-0 items-center justify-between border-b bg-background px-4">
      <div className="flex items-center gap-2">
        {backHref && (
          <Link
            to={backHref}
            className="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
          >
            <ArrowLeft className="size-4" />
          </Link>
        )}
        <h1 className="text-lg font-semibold">{title}</h1>
        {description && (
          <span className="text-xs text-muted-foreground">{description}</span>
        )}
      </div>
      {children && <div className="flex items-center gap-1.5">{children}</div>}
    </div>
  );
}
