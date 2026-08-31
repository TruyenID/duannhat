import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AppProvider } from "@/providers/app-provider";
import { CategoryTreeRow } from "@/app/hq/[brandSlug]/categories/components/category-tree-row";
import type { Category } from "@/services/category-service";
import type { ReactNode } from "react";

function wrapper({ children }: { children: ReactNode }) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return (
    <QueryClientProvider client={queryClient}>
      <AppProvider defaultLocale="en">{children}</AppProvider>
    </QueryClientProvider>
  );
}

/**
 * Regression test for debug-001.
 *
 * Before the fix, the row name was a `<Link href="#">` that called an
 * `onClick` handler to open the edit drawer. After the fix it must be a
 * real `<a href="...">` pointing at the category detail page.
 */

const baseCategory: Category = {
  id: "01010101-0101-0101-0101-010101010101",
  organization_id: "org",
  brand_id: "brand",
  sku: "C-001",
  name: "Beverages",
  slug: "beverages",
  description: null,
  image_url: null,
  is_active: true,
  parent_id: null,
  created_at: "2026-04-07T00:00:00Z",
  updated_at: "2026-04-07T00:00:00Z",
  deleted_at: null,
};

describe("CategoryTreeRow (debug-001 regression)", () => {
  it("renders the name as a real <a> link to the detail page", () => {
    render(
      <CategoryTreeRow
        category={baseCategory}
        depth={0}
        hasChildren={false}
        isExpanded={false}
        detailHref="/hq/acme/categories/01010101-0101-0101-0101-010101010101"
        onToggle={() => {}}
      />,
      { wrapper }
    );

    const link = screen.getByRole("link", { name: "Beverages" });
    expect(link).toBeInTheDocument();
    expect(link.getAttribute("href")).toBe(
      "/hq/acme/categories/01010101-0101-0101-0101-010101010101"
    );
    // The pre-fix code rendered href="#" — guard against regression.
    expect(link.getAttribute("href")).not.toBe("#");
  });

  it("indents by depth × 16px", () => {
    const { container } = render(
      <CategoryTreeRow
        category={baseCategory}
        depth={2}
        hasChildren={false}
        isExpanded={false}
        detailHref="/x"
        onToggle={() => {}}
      />,
      { wrapper }
    );
    const root = container.firstChild as HTMLElement;
    expect(root.style.paddingLeft).toBe("32px");
  });

  it("shows the chevron only when hasChildren is true", () => {
    const withChildren = render(
      <CategoryTreeRow
        category={baseCategory}
        depth={0}
        hasChildren={true}
        isExpanded={false}
        detailHref="/x"
        onToggle={() => {}}
      />,
      { wrapper }
    );
    expect(withChildren.getByRole("button", { name: "Expand" })).toBeInTheDocument();

    withChildren.unmount();

    const noChildren = render(
      <CategoryTreeRow
        category={baseCategory}
        depth={0}
        hasChildren={false}
        isExpanded={false}
        detailHref="/x"
        onToggle={() => {}}
      />,
      { wrapper }
    );
    expect(noChildren.queryByRole("button", { name: /expand|collapse/i })).toBeNull();
  });
});
