import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
  CLOUD_URL,
  clearWorkstationUrl,
  getMode,
  getWorkstationUrl,
  hasWorkstation,
  isUsingWorkstation,
  breakerState,
  markWorkstationReachable,
  markWorkstationUnreachable,
  resetUnreachable,
  resolveBaseUrl,
  setMode,
  setWorkstationUrl,
  unreachableTimeRemaining,
} from "./base-url-resolver";

beforeEach(() => {
  localStorage.clear();
  resetUnreachable();
});

afterEach(() => {
  vi.useRealTimers();
});

describe("getMode / setMode", () => {
  it("defaults to cloud when no preference is stored", () => {
    expect(getMode()).toBe("cloud");
  });

  it("persists mode to localStorage", () => {
    setMode("workstation");
    expect(getMode()).toBe("workstation");
    setMode("cloud");
    expect(getMode()).toBe("cloud");
    setMode("auto");
    expect(getMode()).toBe("auto");
  });

  it("rejects unknown stored values and returns cloud", () => {
    localStorage.setItem("pos_api_mode", "bogus");
    expect(getMode()).toBe("cloud");
  });
});

describe("resolveBaseUrl", () => {
  it("returns workstation URL in auto mode by default", () => {
    setMode("auto");
    expect(resolveBaseUrl()).toEqual({ url: getWorkstationUrl(), via: "workstation" });
  });

  it("returns workstation URL in explicit workstation mode", () => {
    setMode("workstation");
    expect(resolveBaseUrl()).toEqual({ url: getWorkstationUrl(), via: "workstation" });
  });

  it("returns Cloud URL in cloud mode", () => {
    setMode("cloud");
    expect(resolveBaseUrl()).toEqual({ url: CLOUD_URL, via: "cloud" });
  });

  it("falls back to Cloud in auto mode while in backoff window", () => {
    setMode("auto");
    tripBreaker();
    expect(resolveBaseUrl()).toEqual({ url: CLOUD_URL, via: "cloud" });
  });

  it("stays on workstation in explicit workstation mode even when marked unreachable", () => {
    setMode("workstation");
    tripBreaker();
    expect(resolveBaseUrl()).toEqual({ url: getWorkstationUrl(), via: "workstation" });
  });
});

describe("getWorkstationUrl / setWorkstationUrl / clearWorkstationUrl", () => {
  it("falls back to the build-time default when nothing is paired", () => {
    expect(getWorkstationUrl()).toBe("http://localhost:8080");
  });

  it("persists an operator-paired URL and resolver picks it up", () => {
    setWorkstationUrl("http://192.168.1.50:6969");
    expect(getWorkstationUrl()).toBe("http://192.168.1.50:6969");
    setMode("workstation");
    expect(resolveBaseUrl()).toEqual({
      url: "http://192.168.1.50:6969",
      via: "workstation",
    });
  });

  it("trims trailing slashes and whitespace on save", () => {
    setWorkstationUrl("  http://192.168.1.50:6969/  ");
    expect(getWorkstationUrl()).toBe("http://192.168.1.50:6969");
  });

  it("clearWorkstationUrl reverts to the build-time default", () => {
    setWorkstationUrl("http://192.168.1.50:6969");
    clearWorkstationUrl();
    expect(getWorkstationUrl()).toBe("http://localhost:8080");
  });

  it("setWorkstationUrl resets the unreachable backoff", () => {
    setMode("auto");
    tripBreaker();
    expect(resolveBaseUrl().via).toBe("cloud");

    setWorkstationUrl("http://192.168.1.50:6969");
    expect(resolveBaseUrl().via).toBe("workstation");
  });
});

/** Ngắt mạch = đủ số lỗi LIÊN TIẾP, không phải một lỗi (#2689). */
function tripBreaker(): void {
  markWorkstationUnreachable();
  markWorkstationUnreachable();
  markWorkstationUnreachable();
}

describe("backoff window", () => {
  it("expires after 30 seconds", () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date(2026, 0, 1, 12, 0, 0));
    setMode("auto");

    tripBreaker();
    expect(resolveBaseUrl().via).toBe("cloud");

    vi.advanceTimersByTime(29_000);
    expect(resolveBaseUrl().via).toBe("cloud");

    vi.advanceTimersByTime(2_000); // total 31s
    expect(resolveBaseUrl().via).toBe("workstation");
  });

  it("unreachableTimeRemaining returns 0 when not in backoff", () => {
    resetUnreachable();
    expect(unreachableTimeRemaining()).toBe(0);
  });

  it("unreachableTimeRemaining returns positive ms when in backoff", () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date(2026, 0, 1));
    tripBreaker();
    const remaining = unreachableTimeRemaining();
    expect(remaining).toBeGreaterThan(29_000);
    expect(remaining).toBeLessThanOrEqual(30_000);
  });

  it("setMode clears the backoff (manual override gives fresh try)", () => {
    setMode("auto");
    tripBreaker();
    expect(resolveBaseUrl().via).toBe("cloud");

    setMode("workstation"); // user explicitly chose workstation
    expect(unreachableTimeRemaining()).toBe(0);
  });

  it("resetUnreachable clears the backoff", () => {
    setMode("auto");
    tripBreaker();
    expect(resolveBaseUrl().via).toBe("cloud");

    resetUnreachable();
    expect(resolveBaseUrl().via).toBe("workstation");
  });
});

describe("hasWorkstation (#422)", () => {
  it("returns true for the real workstation URL configured in tests", () => {
    // Test env resolves WORKSTATION_URL to a real http URL (default 8080).
    expect(hasWorkstation()).toBe(true);
  });

  it("treats the 'none' sentinel as no workstation → resolveBaseUrl uses cloud", async () => {
    vi.stubEnv("VITE_WORKSTATION_API_URL", "none");
    vi.resetModules();
    const mod = await import("./base-url-resolver");
    expect(mod.hasWorkstation()).toBe(false);
    // The default is Cloud, so the first call never hits the "none" host.
    expect(mod.resolveBaseUrl()).toEqual({ url: mod.CLOUD_URL, via: "cloud" });
    // Explicit workstation mode also degrades to cloud (nothing to talk to).
    mod.setMode("workstation");
    expect(mod.resolveBaseUrl().via).toBe("cloud");
    vi.unstubAllEnvs();
    vi.resetModules();
  });

  it("treats a whitespace/uppercase 'NONE' as no workstation", async () => {
    vi.stubEnv("VITE_WORKSTATION_API_URL", "  NONE  ");
    vi.resetModules();
    const mod = await import("./base-url-resolver");
    expect(mod.hasWorkstation()).toBe(false);
    vi.unstubAllEnvs();
    vi.resetModules();
  });
});

describe("isUsingWorkstation", () => {
  it("returns true when resolver picks workstation", () => {
    setMode("workstation");
    expect(isUsingWorkstation()).toBe(true);
  });

  it("returns false when resolver picks cloud", () => {
    setMode("cloud");
    expect(isUsingWorkstation()).toBe(false);
  });

  it("returns false when in auto-mode backoff", () => {
    setMode("auto");
    tripBreaker();
    expect(isUsingWorkstation()).toBe(false);
  });
});

describe("same-origin workstation build (#1169 plan-052 T3.5)", () => {
  // The mode is decided at module load from `import.meta.env.BASE_URL`, so each
  // case re-imports the module with the env stubbed — that is also the honest
  // shape of the thing: a bundle is one build or the other, never both.
  const loadWith = async (baseUrl: string) => {
    vi.resetModules();
    vi.stubEnv("BASE_URL", baseUrl);
    return await import("./base-url-resolver");
  };

  afterEach(() => {
    vi.unstubAllEnvs();
    vi.resetModules();
  });

  it("cloud build (base /) keeps pairing: stored URL wins", async () => {
    const m = await loadWith("/");
    expect(m.isServedByWorkstation()).toBe(false);

    m.setWorkstationUrl("http://192.168.1.50:6969");
    expect(m.getWorkstationUrl()).toBe("http://192.168.1.50:6969");
  });

  it("workstation build (base /pos/) uses the serving origin", async () => {
    const m = await loadWith("/pos/");
    expect(m.isServedByWorkstation()).toBe(true);
    expect(m.getWorkstationUrl()).toBe(location.origin);
    expect(m.hasWorkstation()).toBe(true);
  });

  it("a stale paired IP does NOT override the serving origin", async () => {
    // The failure this prevents: a tablet paired to 192.168.1.50 months ago is
    // later opened straight from the workstation. If the stored value won, the
    // page would call a machine it may no longer reach — while the workstation
    // answering the request sits at the other end of the very same connection.
    localStorage.setItem("pos_workstation_url", "http://192.168.1.50:6969");

    const m = await loadWith("/pos/");
    expect(m.getWorkstationUrl()).toBe(location.origin);
  });

  it("workstation build: Cloud mode still targets the backend directly (NOT the workstation)", async () => {
    // LAN mode → the workstation (getWorkstationUrl → serving origin); Cloud mode
    // → the backend at CLOUD_URL. Even for the /pos build, CLOUD_URL keeps the
    // configured backend URL — Cloud mode must reach the backend, not loop back
    // through the workstation. (Cross-origin from a workstation-served page is a
    // backend CORS concern, handled at the backend.)
    vi.resetModules();
    vi.stubEnv("VITE_API_URL", "http://localhost:5400");
    vi.stubEnv("BASE_URL", "/pos/");
    const m = await import("./base-url-resolver");
    expect(m.CLOUD_URL).toBe("http://localhost:5400");
  });

  it("cloud build: CLOUD_URL keeps the absolute VITE_API_URL", async () => {
    vi.resetModules();
    vi.stubEnv("VITE_API_URL", "http://localhost:5400");
    vi.stubEnv("BASE_URL", "/");
    const m = await import("./base-url-resolver");
    expect(m.CLOUD_URL).toBe("http://localhost:5400");
  });

  it("workstation build: CLOUD_URL comes from the injected runtime meta (workstation .env)", async () => {
    // The workstation injects <meta name="x-pos-cloud-url"> from WS_APP_CLOUD_URL,
    // so its .env drives Cloud mode at runtime — overriding the baked VITE_API_URL.
    vi.resetModules();
    vi.stubEnv("VITE_API_URL", "http://localhost:5400"); // baked default (should lose)
    vi.stubEnv("BASE_URL", "/pos/");
    const meta = document.createElement("meta");
    meta.setAttribute("name", "x-pos-cloud-url");
    meta.setAttribute("content", "https://api.deployed.example");
    document.head.appendChild(meta);
    try {
      const m = await import("./base-url-resolver");
      expect(m.CLOUD_URL).toBe("https://api.deployed.example");
    } finally {
      meta.remove();
    }
  });

  it("cloud build ignores the runtime meta (only /pos reads it)", async () => {
    vi.resetModules();
    vi.stubEnv("VITE_API_URL", "http://localhost:5400");
    vi.stubEnv("BASE_URL", "/");
    const meta = document.createElement("meta");
    meta.setAttribute("name", "x-pos-cloud-url");
    meta.setAttribute("content", "https://should-be-ignored.example");
    document.head.appendChild(meta);
    try {
      const m = await import("./base-url-resolver");
      expect(m.CLOUD_URL).toBe("http://localhost:5400");
    } finally {
      meta.remove();
    }
  });

  // The default mode, not the URL. A terminal that has never been to Settings →
  // Connection must route to the machine that served it — otherwise the whole
  // point of the embedded build (sell and print with the Internet unplugged,
  // #1169) is dead on arrival, and the endpoints only the workstation has
  // (/pos/terminal/* for the P400) answer 404 from Cloud.
  it("workstation build defaults to LAN with nothing stored", async () => {
    localStorage.clear();
    const m = await loadWith("/pos/");
    expect(m.getMode()).toBe("workstation");
    expect(m.resolveBaseUrl()).toEqual({ url: location.origin, via: "workstation" });
  });

  it("cloud build still defaults to Cloud with nothing stored", async () => {
    localStorage.clear();
    const m = await loadWith("/");
    expect(m.getMode()).toBe("cloud");
    expect(m.resolveBaseUrl().via).toBe("cloud");
  });

  it("workstation build: an explicitly stored preference still wins", async () => {
    // The Settings toggle and the shift-gate's "switch side" escape hatch are the
    // reason this is a default and not a pin: a cashier whose workstation died
    // mid-shift must be able to choose Cloud and keep selling.
    localStorage.setItem("pos_api_mode", "cloud");
    const m = await loadWith("/pos/");
    expect(m.getMode()).toBe("cloud");
    expect(m.resolveBaseUrl().via).toBe("cloud");
  });

  it("workstation build: VITE_POS_API_MODE still overrides the default", async () => {
    localStorage.clear();
    vi.resetModules();
    vi.stubEnv("BASE_URL", "/pos/");
    vi.stubEnv("VITE_POS_API_MODE", "cloud");
    const m = await import("./base-url-resolver");
    expect(m.isModeForced()).toBe(true);
    expect(m.getMode()).toBe("cloud");
  });
});

/**
 * #2689 — circuit breaker: ngưỡng lỗi liên tiếp + half-open tự dò.
 *
 * Bản trước ngắt mạch sau ĐÚNG MỘT lỗi mạng, tức một cái chớp LAN (tablet đổi
 * AP, một gói rơi) là đủ để terminal bỏ workstation 30 giây — bỏ đúng đường ít
 * trễ hơn VÀ đường duy nhất còn sống khi mất Internet.
 *
 * Ba bảo đảm dưới đây là thứ chưa từng được canh, không phải diễn đạt lại cái
 * cũ: (1) chớp đơn lẻ không ngắt được mạch, (2) một lượt thành công phá chuỗi
 * nên lỗi rải rác không cộng dồn, (3) hết backoff thì lượt gọi THẬT đi qua
 * workstation làm phép dò, và kết quả của nó đóng mạch hoặc ngắt lại ngay.
 */
describe("circuit breaker (#2689)", () => {
  beforeEach(() => {
    resetUnreachable();
    setMode("auto");
  });

  it("một lỗi đơn lẻ KHÔNG ngắt mạch — vẫn đi workstation", () => {
    markWorkstationUnreachable();

    expect(breakerState()).toBe("closed");
    expect(resolveBaseUrl().via).toBe("workstation");
  });

  it("hai lỗi vẫn chưa đủ — ngưỡng là ba", () => {
    markWorkstationUnreachable();
    markWorkstationUnreachable();

    expect(breakerState()).toBe("closed");
    expect(resolveBaseUrl().via).toBe("workstation");
  });

  it("lỗi thứ BA mới ngắt mạch", () => {
    markWorkstationUnreachable();
    markWorkstationUnreachable();
    expect(resolveBaseUrl().via).toBe("workstation");

    markWorkstationUnreachable();

    expect(breakerState()).toBe("open");
    expect(resolveBaseUrl().via).toBe("cloud");
  });

  it("một lượt THÀNH CÔNG phá chuỗi — lỗi rải rác không cộng dồn", () => {
    markWorkstationUnreachable();
    markWorkstationUnreachable();

    markWorkstationReachable(); // workstation trả lời được một lượt

    markWorkstationUnreachable();
    markWorkstationUnreachable();

    // Tổng cộng 4 lỗi, nhưng không bao giờ có 3 lỗi LIÊN TIẾP.
    expect(breakerState()).toBe("closed");
    expect(resolveBaseUrl().via).toBe("workstation");
  });

  it("hết backoff → half-open, và lượt gọi THẬT đi qua workstation làm phép dò", () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date(2026, 0, 1, 12, 0, 0));

    tripBreaker();
    expect(breakerState()).toBe("open");
    expect(resolveBaseUrl().via).toBe("cloud");

    vi.advanceTimersByTime(31_000);

    expect(breakerState()).toBe("half-open");
    // Không có request thăm dò riêng: một endpoint thăm dò đo sức khoẻ của
    // CHÍNH nó, không đo đường mà đơn hàng thật sự đi qua.
    expect(resolveBaseUrl().via).toBe("workstation");

    vi.useRealTimers();
  });

  it("phép dò HỎNG → ngắt lại NGAY, không bắt đếm lại từ đầu", () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date(2026, 0, 1, 12, 0, 0));

    tripBreaker();
    vi.advanceTimersByTime(31_000);
    expect(breakerState()).toBe("half-open");

    markWorkstationUnreachable(); // MỘT lỗi, không phải ba

    expect(breakerState()).toBe("open");
    expect(resolveBaseUrl().via).toBe("cloud");

    vi.useRealTimers();
  });

  it("phép dò THÀNH CÔNG → đóng mạch và xoá bộ đếm", () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date(2026, 0, 1, 12, 0, 0));

    tripBreaker();
    vi.advanceTimersByTime(31_000);

    markWorkstationReachable();

    expect(breakerState()).toBe("closed");
    expect(resolveBaseUrl().via).toBe("workstation");

    // Bộ đếm phải sạch: hai lỗi kế tiếp KHÔNG được ngắt mạch ngay.
    markWorkstationUnreachable();
    markWorkstationUnreachable();
    expect(breakerState()).toBe("closed");

    vi.useRealTimers();
  });

  it("resetUnreachable xoá cả backoff LẪN bộ đếm", () => {
    markWorkstationUnreachable();
    markWorkstationUnreachable();

    resetUnreachable();

    // Nếu chỉ xoá backoff mà quên bộ đếm thì MỘT lỗi kế tiếp sẽ ngắt mạch.
    markWorkstationUnreachable();
    expect(breakerState()).toBe("closed");
  });
});
