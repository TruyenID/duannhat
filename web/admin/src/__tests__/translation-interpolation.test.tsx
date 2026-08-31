import { describe, it, expect } from "vitest";
import { renderHook } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AppProvider, useTranslation } from "@/providers/app-provider";
import type { ReactNode } from "react";

function wrapper({ children }: { children: ReactNode }) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return (
    <QueryClientProvider client={queryClient}>
      <AppProvider>{children}</AppProvider>
    </QueryClientProvider>
  );
}

/**
 * Bug round 1 — t() param interpolation uses `String.prototype.replace` with a
 * STRING replacement, so `$`-sequences in the interpolated value are treated as
 * special replacement patterns ($&, $', $`, $$). User-entered content (product
 * / material / shop names) can legitimately contain `$`, which then corrupts
 * delete/reject confirmation copy. It also only replaces the FIRST occurrence
 * of a repeated placeholder.
 *
 * We exploit the documented fallback: an unknown key is returned verbatim, so
 * the key string itself acts as the template — letting us test the real t()
 * interpolation without depending on dictionary contents.
 */
describe("t() — param interpolation safety", () => {
  it("does not treat $ in the value as a replacement pattern (regression)", () => {
    const { result } = renderHook(() => useTranslation(), { wrapper });
    const t = result.current.t;

    // $' would otherwise expand to the substring after the match.
    expect(t("Xoá {name}?", { name: "$' hack" })).toBe("Xoá $' hack?");
    // $& would otherwise re-insert the matched "{name}" placeholder.
    expect(t("Xoá {name}?", { name: "$&dup" })).toBe("Xoá $&dup?");
    // $$ would otherwise collapse to a single $.
    expect(t("Giá {p}", { p: "A$$B" })).toBe("Giá A$$B");
  });

  it("replaces every occurrence of a repeated placeholder", () => {
    const { result } = renderHook(() => useTranslation(), { wrapper });
    expect(result.current.t("{x} và {x}", { x: "Y" })).toBe("Y và Y");
  });

  it("interpolates ordinary values and numbers normally", () => {
    const { result } = renderHook(() => useTranslation(), { wrapper });
    expect(result.current.t("Xoá {name}?", { name: "Cà phê" })).toBe("Xoá Cà phê?");
    expect(result.current.t("Tổng {n}", { n: 42 })).toBe("Tổng 42");
  });
});
