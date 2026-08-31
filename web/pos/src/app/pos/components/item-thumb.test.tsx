import { describe, expect, it } from "vitest";
import { render } from "@testing-library/react";
import { ItemThumb } from "./item-thumb";

describe("ItemThumb", () => {
  it("renders the image when a url is present", () => {
    const { container } = render(
      <ItemThumb imageUrl="https://x/y.jpg" label="Phở Bò" />,
    );
    const img = container.querySelector("img");
    expect(img?.getAttribute("src")).toBe("https://x/y.jpg");
    expect(img?.getAttribute("alt")).toBe("Phở Bò");
  });

  it("falls back to the item's initial when there is no image (orphaned SKU)", () => {
    const { container, getByText } = render(
      <ItemThumb imageUrl={null} label="Iced Coffee (M)" />,
    );
    expect(container.querySelector("img")).toBeNull();
    expect(getByText("I")).toBeTruthy();
  });

  it("upper-cases the initial and handles accented Vietnamese", () => {
    const { getByText } = render(<ItemThumb imageUrl="" label="đá chanh" />);
    expect(getByText("Đ")).toBeTruthy();
  });

  it("shows '?' for a blank label", () => {
    const { getByText } = render(<ItemThumb imageUrl={undefined} label="  " />);
    expect(getByText("?")).toBeTruthy();
  });
});
