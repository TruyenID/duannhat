import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { MenuServiceTypeBadge } from "./menu-service-type-badge";
import ja from "@/i18n/ja.json";
import vi from "@/i18n/vi.json";

/** Stand-in for the real provider's `t` — reads the shipped catalogue. */
const translator = (catalogue: Record<string, string>) => (key: string) =>
  catalogue[key] ?? key;

const tJa = translator(ja as Record<string, string>);
const tVi = translator(vi as Record<string, string>);

describe("MenuServiceTypeBadge (#1756)", () => {
  it("labels a dine-in menu with the wording the order-type picker uses", () => {
    render(<MenuServiceTypeBadge menu={{ service_type: "DineIn" }} t={tJa} />);
    expect(screen.getByText("イートイン")).toBeInTheDocument();
  });

  it("labels a takeaway menu", () => {
    render(<MenuServiceTypeBadge menu={{ service_type: "Takeaway" }} t={tVi} />);
    expect(screen.getByText("Mang đi")).toBeInTheDocument();
  });

  it("labels a Both menu — it is ambiguous by design, not unknown", () => {
    render(<MenuServiceTypeBadge menu={{ service_type: "Both" }} t={tVi} />);
    expect(screen.getByText("Cả hai")).toBeInTheDocument();
  });

  it("resolves Cloud's inheriting shape from effective_service_type", () => {
    const { container } = render(
      <MenuServiceTypeBadge
        menu={{ service_type: null, effective_service_type: "Takeaway" }}
        t={tVi}
      />,
    );
    expect(
      container.querySelector("[data-service-type]")?.getAttribute(
        "data-service-type",
      ),
    ).toBe("Takeaway");
  });

  it("renders NOTHING when the server stated no service type", () => {
    // The load-bearing case. A backend/workstation older than #1756 sends
    // neither field; defaulting to "Both" here would put an unfounded
    // 8%-vs-10% claim on screen, so the badge must stay absent.
    const { container } = render(
      <MenuServiceTypeBadge menu={{ service_type: null }} t={tVi} />,
    );
    expect(container.querySelector("[data-slot]")).toBeNull();
    expect(container).toBeEmptyDOMElement();
  });

  it("gives dine-in and takeaway visibly different styling", () => {
    const { container: dineIn } = render(
      <MenuServiceTypeBadge menu={{ service_type: "DineIn" }} t={tVi} />,
    );
    const { container: takeaway } = render(
      <MenuServiceTypeBadge menu={{ service_type: "Takeaway" }} t={tVi} />,
    );
    const cls = (c: HTMLElement) =>
      c.querySelector("[data-slot]")?.className ?? "";

    expect(cls(dineIn)).not.toBe("");
    expect(cls(dineIn)).not.toBe(cls(takeaway));
  });
});
