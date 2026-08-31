import { ReactNode } from 'react';
import { Separator } from "@/separator";

export interface PageContainerProps {
  /**
   * Page title
   */
  title?: string;

  /**
   * Subtitle or description below title
   */
  subtitle?: string;

  /**
   * Extra content (buttons, actions) displayed on the right side of header
   */
  extra?: ReactNode;

  /**
   * Main page content
   */
  children: ReactNode;

  /**
   * Footer content displayed at the bottom
   */
  footer?: ReactNode;

  /**
   * Sidebar content displayed on left or right
   */
  sidebar?: ReactNode;

  /**
   * Sidebar position
   * @default 'right'
   */
  sidebarPosition?: 'left' | 'right';

  /**
   * Sidebar width
   * @default 'w-80'
   */
  sidebarWidth?: string;

  /**
   * Layout variant
   * - 'standard': Default padded layout with header
   * - 'full': Full width, no padding (for boards, gantt)
   * - 'split': Layout with sidebar inside page
   * @default 'standard'
   */
  variant?: 'standard' | 'full' | 'split';

  /**
   * Custom container className
   */
  className?: string;

  /**
   * Custom content className
   */
  contentClassName?: string;

  /**
   * Show separator below header
   * @default true for standard variant
   */
  showHeaderSeparator?: boolean;
}

/**
 * PageContainer - Flexible page layout component
 *
 * @example
 * // Standard layout with title and actions
 * <PageContainer
 *   title="Dashboard"
 *   subtitle="Overview of all projects"
 *   extra={<Button>Create</Button>}
 * >
 *   <div>Content here</div>
 * </PageContainer>
 *
 * @example
 * // Split layout with right sidebar
 * <PageContainer
 *   title="Task Detail"
 *   variant="split"
 *   sidebar={<CommentSection />}
 *   sidebarPosition="right"
 * >
 *   <div>Main content</div>
 * </PageContainer>
 *
 * @example
 * // Full width layout (no padding)
 * <PageContainer variant="full">
 *   <KanbanBoard />
 * </PageContainer>
 */
export function PageContainer({
  title,
  subtitle,
  extra,
  children,
  footer,
  sidebar,
  sidebarPosition = 'right',
  sidebarWidth = 'w-80',
  variant = 'standard',
  className = '',
  contentClassName = '',
  showHeaderSeparator = true,
}: PageContainerProps) {
  // Full width variant - no padding, no header
  if (variant === 'full') {
    return (
      <div className={`h-full flex flex-col ${className}`}>
        {children}
        {footer && <div className="border-t border-border">{footer}</div>}
      </div>
    );
  }

  // Split variant - with sidebar
  if (variant === 'split') {
    return (
      <div className={`h-full flex flex-col ${className}`}>
        {(title || extra) && (
          <div className="px-page py-3 border-b border-border">
            <div className="flex items-start justify-between gap-4">
              <div className="flex-1 min-w-0">
                {title && <h1 className="text-page-title font-semibold mb-1">{title}</h1>}
                {subtitle && <p className="text-sm text-muted-foreground">{subtitle}</p>}
              </div>
              {extra && <div className="flex-shrink-0">{extra}</div>}
            </div>
          </div>
        )}

        <div className="flex-1 flex overflow-hidden">
          {sidebar && sidebarPosition === 'left' && (
            <aside className={`${sidebarWidth} border-r border-border overflow-y-auto flex-shrink-0`}>
              {sidebar}
            </aside>
          )}

          <main className={`flex-1 overflow-y-auto ${contentClassName}`}>
            {children}
          </main>

          {sidebar && sidebarPosition === 'right' && (
            <aside className={`${sidebarWidth} border-l border-border overflow-y-auto flex-shrink-0`}>
              {sidebar}
            </aside>
          )}
        </div>

        {footer && (
          <div className="border-t border-border">
            {footer}
          </div>
        )}
      </div>
    );
  }

  // Standard variant - default padded layout
  return (
    <div className={`h-full flex flex-col ${className}`}>
      {(title || extra) && (
        <>
          <div className="px-page py-3">
            <div className="flex items-start justify-between gap-4">
              <div className="flex-1 min-w-0">
                {title && <h1 className="text-page-title font-semibold mb-1">{title}</h1>}
                {subtitle && <p className="text-sm text-muted-foreground">{subtitle}</p>}
              </div>
              {extra && <div className="flex-shrink-0">{extra}</div>}
            </div>
          </div>
          {showHeaderSeparator && <Separator />}
        </>
      )}

      <main className={`flex-1 overflow-y-auto p-page ${contentClassName}`}>
        {children}
      </main>

      {footer && (
        <>
          <Separator />
          <div className="px-page py-3">
            {footer}
          </div>
        </>
      )}
    </div>
  );
}

export function StandardPageContainer(props: Omit<PageContainerProps, 'variant'>) {
  return <PageContainer {...props} variant="standard" />;
}

export function SplitPageContainer(props: Omit<PageContainerProps, 'variant'>) {
  return <PageContainer {...props} variant="split" />;
}

export function FullWidthPageContainer(props: Omit<PageContainerProps, 'variant' | 'title' | 'subtitle' | 'extra'>) {
  return <PageContainer {...props} variant="full" />;
}
