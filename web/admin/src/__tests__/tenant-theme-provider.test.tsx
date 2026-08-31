import { render, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { TenantThemeProvider } from "@/providers/tenant-theme-provider";

const brand = {
  slug: "betoya",
  primary_color: "#009444",
  secondary_color: "#FFC20E",
  accent_color: "#00B856",
  text_color: "#171614",
};

describe("TenantThemeProvider", () => {
  beforeEach(() => {
    document.body.removeAttribute("style");
    delete document.body.dataset.tenant;
  });

  afterEach(() => {
    document.body.removeAttribute("style");
    delete document.body.dataset.tenant;
  });

  it("stores brand text separately from semantic foreground tokens", async () => {
    render(
      <TenantThemeProvider brand={brand}>
        <div>Tenant content</div>
      </TenantThemeProvider>
    );

    await waitFor(() => expect(document.body.dataset.tenant).toBe("betoya"));
    expect(document.body.style.getPropertyValue("--tenant-foreground")).toBe("#171614");
    expect(document.body.style.getPropertyValue("--foreground")).toBe("");
    expect(document.body.style.getPropertyValue("--card-foreground")).toBe("");
    expect(document.body.style.getPropertyValue("--popover-foreground")).toBe("");
  });

  it("ignores invalid text colors instead of overriding theme tokens", async () => {
    render(
      <TenantThemeProvider brand={{ ...brand, text_color: "black" }}>
        <div>Tenant content</div>
      </TenantThemeProvider>
    );

    await waitFor(() => expect(document.body.dataset.tenant).toBe("betoya"));
    expect(document.body.style.getPropertyValue("--tenant-foreground")).toBe("");
  });

  it("restores the previous tenant variables and slug on unmount", async () => {
    document.body.dataset.tenant = "previous-brand";
    document.body.style.setProperty("--tenant-foreground", "#222222");

    const { unmount } = render(
      <TenantThemeProvider brand={brand}>
        <div>Tenant content</div>
      </TenantThemeProvider>
    );

    await waitFor(() => expect(document.body.dataset.tenant).toBe("betoya"));
    unmount();

    expect(document.body.dataset.tenant).toBe("previous-brand");
    expect(document.body.style.getPropertyValue("--tenant-foreground")).toBe("#222222");
  });
});
