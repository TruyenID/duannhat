import { Badge } from "../badge";

const statusStyles: Record<string, string> = {
  draft: "border-transparent bg-muted text-muted-foreground",
  pending: "border-transparent bg-amber-50 text-amber-700",
  approved: "border-transparent bg-blue-50 text-blue-700",
  active: "border-transparent bg-green-50 text-green-700",
  inactive: "border-transparent bg-muted text-muted-foreground",
  rejected: "border-transparent bg-red-50 text-red-700",
  deleted: "border-transparent bg-red-50 text-red-400 line-through",
  completed: "border-transparent bg-green-50 text-green-700",
  cancelled: "border-transparent bg-muted text-muted-foreground line-through",
  hidden: "border-transparent bg-amber-50 text-amber-700",
  visible: "border-transparent bg-green-50 text-green-700",
  in_progress: "border-transparent bg-blue-50 text-blue-700",
  in_transit: "border-transparent bg-blue-50 text-blue-700",
  pending_approval: "border-transparent bg-amber-50 text-amber-700",
};

export interface StatusBadgeProps {
  status: string;
  className?: string;
}

export function StatusBadge({ status, className }: StatusBadgeProps) {
  const style = statusStyles[status] ?? statusStyles.draft;
  const label = status.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());

  return (
    <Badge
      data-slot="status-badge"
      variant="outline"
      className={`h-5 px-1.5 text-xs font-medium ${style} ${className ?? ""}`}
    >
      {label}
    </Badge>
  );
}
