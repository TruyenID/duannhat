interface PageContentProps {
  children: React.ReactNode;
  className?: string;
}

export function PageContent({ children, className }: PageContentProps) {
  return <div className={`flex-1 overflow-auto p-4 ${className ?? ""}`}>{children}</div>;
}
