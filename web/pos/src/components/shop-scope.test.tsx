/**
 * Regression: the POS was unusable on a cold load with
 * `400 "X-Shop-Slug header is required."`.
 *
 * ShiftGate fires GET /pos/till/current on mount and renders `null` while it
 * resolves, so PosPage — which used to publish the shop slug in a
 * useLayoutEffect — never mounted in time, and the first request left without
 * the header. The slug therefore has to be published during RENDER, above the
 * gate. These tests pin that ordering down.
 */

import { beforeEach, describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { useEffect } from "react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { ShopScope } from "./shop-scope";
import { getCurrentShopSlug, setCurrentShopSlug } from "@/lib/shop-context";

/**
 * Stands in for ShiftGate: reads the shop context in the same places a
 * TanStack query would (during render, and again in a mount effect) and
 * renders `null`, so nothing below it ever mounts.
 */
function GateSpy({ onRead }: { onRead: (slugAtEffect: string) => void }) {
  const slugAtRender = getCurrentShopSlug();
  useEffect(() => {
    onRead(getCurrentShopSlug());
  }, [onRead]);
  return <div data-testid="at-render">{slugAtRender}</div>;
}

function renderAt(path: string, children: React.ReactNode) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route
          path="/shop/:shopSlug"
          element={<ShopScope>{children}</ShopScope>}
        />
      </Routes>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  setCurrentShopSlug(""); // cold load — nothing carried over from a prior nav
});

describe("ShopScope", () => {
  it("publishes the slug during render, before any descendant can fire a query", () => {
    const reads: string[] = [];
    renderAt("/shop/van-phong-chinh", <GateSpy onRead={(s) => reads.push(s)} />);

    // The child already sees the slug on its very first render — this is the
    // property that matters, because a query created during that render (or in
    // the effect right after) is what used to go out header-less.
    expect(screen.getByTestId("at-render")).toHaveTextContent("van-phong-chinh");
    expect(reads).toEqual(["van-phong-chinh"]);
  });

  it("keeps the context in step when the route's slug changes", () => {
    renderAt("/shop/shop-a", <GateSpy onRead={() => {}} />);
    expect(getCurrentShopSlug()).toBe("shop-a");

    renderAt("/shop/shop-b", <GateSpy onRead={() => {}} />);
    expect(getCurrentShopSlug()).toBe("shop-b");
  });

  it("renders its children through", () => {
    renderAt("/shop/van-phong-chinh", <div data-testid="child">ok</div>);

    expect(screen.getByTestId("child")).toBeInTheDocument();
  });
});
