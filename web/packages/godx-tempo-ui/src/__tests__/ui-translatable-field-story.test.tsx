import React from "react";
import { render } from "@testing-library/react";
import { describe, it, expect } from "vitest";
import { TranslatableField } from "../translatable-field";

describe("TranslatableField (story gap)", () => {
  it("renders with config", () => {
    const config = { locales: { en: "English", ja: "日本語" }, defaultLocale: "en", fallbackLocale: "en" };
    const { container } = render(
      <TranslatableField config={config} value={{ en: "Hello", ja: "" }} onChange={() => {}}>
        {({ value, onChange }) => <input value={value} onChange={(e) => onChange(e.target.value)} />}
      </TranslatableField>
    );
    expect(container.querySelector("input")).toBeInTheDocument();
  });
});
