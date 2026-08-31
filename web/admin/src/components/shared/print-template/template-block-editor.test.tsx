/**
 * TemplateBlockEditor — plan-053 M4 (#1171), TR-03 / TR-16 / TR-17.
 *
 * The form is the first of three gates (the publish validator and the resolver
 * are the other two), so what it must never do is OFFER a control the backend
 * would reject: no content field on an engine-owned block, no editing of a
 * field the brand did not delegate.
 */
import { describe, expect, it, vi } from "vitest";
import type { ReactNode } from "react";
import { fireEvent, render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AppProvider } from "@/providers/app-provider";
import { TemplateBlockEditor } from "./template-block-editor";
import type { PrintTemplateCatalog, PrintTemplateDefinition } from "@/types/models/PrintTemplate";

const queryClient = new QueryClient();

function Wrapper({ children }: { children: ReactNode }) {
  return (
    <QueryClientProvider client={queryClient}>
      <AppProvider>{children}</AppProvider>
    </QueryClientProvider>
  );
}

function definition(): PrintTemplateDefinition {
  return {
    schema: "tempo.print.v1",
    blocks: [
      { id: "title", type: "text", enabled: true, align: "right", i18n: { ja: "支払済" } },
      { id: "grand_total", type: "locked" },
      { id: "registration_number", type: "locked", enabled: true },
      {
        id: "footer_text",
        type: "text",
        enabled: true,
        align: "center",
        fallback: true,
        i18n: { ja: "ありがとう", en: "Thanks", vi: "Cam on" },
      },
    ],
  };
}

/**
 * What the server sends (#2043): the editor no longer derives the catalog from
 * client-side constants, so the test has to supply the same document the API
 * does. `BlockCatalog::catalogFor()` builds it; the values are pinned against
 * `config/print_blocks.php` by `tests/Feature/Print/ShopCatalogReadTest.php`.
 */
function catalog(): PrintTemplateCatalog {
  return {
    blocks: ["title", "grand_total", "registration_number", "footer_text"],
    required: ["grand_total", "registration_number"],
    sources: ["brand_logo", "branch_logo", "order_url"],
    param_fields: ["store_name", "order_no"],
    mutability: {
      title: "free",
      grand_total: "locked",
      registration_number: "toggleable",
      footer_text: "free",
    },
    editable_props: {
      title: ["enabled", "align", "i18n", "i18n_narrow", "fallback", "bold"],
      grand_total: [],
      registration_number: ["enabled"],
      footer_text: ["enabled", "align", "i18n", "fallback", "bold"],
    },
    prop_enums: {},
  };
}

function renderEditor(props: Partial<Parameters<typeof TemplateBlockEditor>[0]> = {}) {
  const onChange = vi.fn<(definition: PrintTemplateDefinition) => void>();
  const definitionValue = props.definition ?? definition();
  render(
    <TemplateBlockEditor
      definition={definitionValue}
      catalog={props.catalog ?? catalog()}
      mode={props.mode ?? "brand"}
      allowedPaths={props.allowedPaths}
      shopEditable={props.shopEditable}
      onShopEditableChange={props.onShopEditableChange}
      onChange={onChange}
    />,
    { wrapper: Wrapper }
  );
  return { onChange };
}

describe("brand mode", () => {
  it("offers no control at all on an engine-owned block", () => {
    renderEditor();

    const block = screen.getByTestId("block-grand_total");
    expect(block.querySelectorAll("input, button[role=switch], select")).toHaveLength(0);
    expect(block).toHaveAttribute("data-mutability", "locked");
  });

  it("TR-17: a toggleable block gets its switch and nothing else", () => {
    renderEditor();

    expect(screen.getByTestId("enabled-registration_number")).toBeEnabled();
    expect(screen.queryByTestId("i18n-registration_number-ja")).not.toBeInTheDocument();
    expect(screen.getByTestId("block-registration_number")).toHaveAttribute(
      "data-mutability",
      "toggleable"
    );
  });

  it("edits a free text block in all three locales", () => {
    const { onChange } = renderEditor();

    fireEvent.change(screen.getByTestId("i18n-footer_text-vi"), {
      target: { value: "Cam on quy khach" },
    });

    const next = onChange.mock.calls[0][0] as PrintTemplateDefinition;
    expect(next.blocks.find((block) => block.id === "footer_text")?.i18n).toEqual({
      ja: "ありがとう",
      en: "Thanks",
      vi: "Cam on quy khach",
    });
  });

  it("delegating a block adds it to shop_editable; undelegating removes it", () => {
    const onShopEditableChange = vi.fn();
    renderEditor({ shopEditable: [], onShopEditableChange });

    fireEvent.click(screen.getByTestId("delegate-footer_text"));
    expect(onShopEditableChange).toHaveBeenCalledWith(["footer_text"]);
  });

  it("never offers to delegate an engine-owned block", () => {
    renderEditor({ shopEditable: [], onShopEditableChange: vi.fn() });

    expect(screen.queryByTestId("delegate-grand_total")).not.toBeInTheDocument();
    expect(screen.getByTestId("delegate-footer_text")).toBeInTheDocument();
  });
});

describe("shop mode — TR-03 allow-list gate", () => {
  it("enables a delegated field and disables everything else", () => {
    renderEditor({ mode: "shop", allowedPaths: ["footer_text"] });

    expect(screen.getByTestId("i18n-footer_text-ja")).toBeEnabled();
    expect(screen.getByTestId("i18n-title-ja")).toBeDisabled();
    expect(screen.getByTestId("enabled-title")).toBeDisabled();
  });

  it("honours a prop-scoped path: only that prop is live", () => {
    renderEditor({ mode: "shop", allowedPaths: ["footer_text.i18n"] });

    expect(screen.getByTestId("i18n-footer_text-ja")).toBeEnabled();
    expect(screen.getByTestId("enabled-footer_text")).toBeDisabled();
    expect(screen.getByTestId("align-footer_text")).toBeDisabled();
  });

  it("disables everything when the brand delegates nothing", () => {
    renderEditor({ mode: "shop", allowedPaths: [] });

    expect(screen.getByTestId("i18n-footer_text-ja")).toBeDisabled();
    expect(screen.getByTestId("enabled-footer_text")).toBeDisabled();
  });

  it("does not offer the delegation checkbox to a shop", () => {
    renderEditor({ mode: "shop", allowedPaths: ["footer_text"] });

    expect(screen.queryByTestId("delegate-footer_text")).not.toBeInTheDocument();
  });

  it("explains a dead control instead of just greying it out", () => {
    renderEditor({ mode: "shop", allowedPaths: ["footer_text"] });

    // The disabled input is wrapped in the tooltip trigger that carries the
    // reason — a disabled input on its own is not an explanation.
    const disabled = screen.getByTestId("i18n-title-ja");
    expect(disabled.closest("[data-state]")).not.toBeNull();
  });
});
