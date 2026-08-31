import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { AppProvider } from "@/providers/app-provider";
import {
  markApiOutcome,
  resetNetworkStatus,
  seedLastSyncedAt,
} from "@/lib/network-status";
import { OfflineBanner } from "./offline-banner";

function renderBanner() {
  return render(
    <AppProvider>
      <OfflineBanner />
    </AppProvider>,
  );
}

beforeEach(() => {
  resetNetworkStatus();
});

describe("OfflineBanner", () => {
  it("im lặng khi còn kết nối", () => {
    renderBanner();
    expect(screen.queryByTestId("offline-banner")).toBeNull();
  });

  it("hiện ra sau hai lần lỗi mạng liên tiếp", () => {
    markApiOutcome("network-error");
    markApiOutcome("network-error");
    renderBanner();
    expect(screen.getByTestId("offline-banner")).toBeInTheDocument();
  });

  it("NÓI RA dữ liệu cũ tới lúc nào", () => {
    // Màn POS hiện số liệu cũ mà không nói là cũ thì tệ hơn màn báo lỗi:
    // giờ hiển thị ở đây là toàn bộ giá trị của banner.
    vi.useFakeTimers();
    vi.setSystemTime(new Date("2026-08-03T09:41:00"));
    markApiOutcome("reached-server");
    vi.useRealTimers();

    markApiOutcome("network-error");
    markApiOutcome("network-error");
    renderBanner();

    expect(screen.getByTestId("offline-banner")).toHaveTextContent("09:41");
  });

  it("nói rõ chưa có dữ liệu khi cache rỗng", () => {
    markApiOutcome("network-error");
    markApiOutcome("network-error");
    renderBanner();

    const banner = screen.getByTestId("offline-banner");
    expect(banner.textContent).not.toMatch(/\d{2}:\d{2}/);
  });

  it("tuổi dữ liệu gieo từ cache offline cũng hiện ra", () => {
    seedLastSyncedAt(new Date("2026-08-03T07:05:00").getTime());
    markApiOutcome("network-error");
    markApiOutcome("network-error");
    renderBanner();

    expect(screen.getByTestId("offline-banner")).toHaveTextContent("07:05");
  });

  it("liệt kê hành động bị khoá — không để nút bấm-rồi-chết-im", () => {
    markApiOutcome("network-error");
    markApiOutcome("network-error");
    renderBanner();

    // Nội dung theo locale mặc định (ja); chỉ cần chắc dòng thứ ba có mặt.
    const banner = screen.getByTestId("offline-banner");
    expect(banner.querySelectorAll("p")).toHaveLength(3);
  });
});
