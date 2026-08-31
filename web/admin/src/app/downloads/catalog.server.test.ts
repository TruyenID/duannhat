// @vitest-environment node
import { afterEach, describe, expect, it, vi } from "vitest";

import { backendOrigin, loadCatalog } from "./catalog.server";

/**
 * The manifest read happens on the SERVER. These cover the two things that
 * decide whether a shop can download anything at all: which origin the links
 * point at, and what the page gets when the backend does not answer.
 */

const ENV_KEY = "TEMPO_BACKEND_URL";

afterEach(() => {
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
});

describe("backendOrigin", () => {
  it("takes the configured backend and drops a trailing slash", () => {
    vi.stubEnv(ENV_KEY, "https://tempo-prod.godx.jp/");

    expect(backendOrigin()).toBe("https://tempo-prod.godx.jp");
  });

  it("falls back to the same default next.config.ts proxies to", () => {
    vi.stubEnv(ENV_KEY, "");

    expect(backendOrigin()).toBe("https://dxs-product.test");
  });
});

describe("loadCatalog", () => {
  it("reads the manifest from the backend origin", async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({
        latest: "v1.0.0",
        updated_at: "2026-08-17T00:00:00Z",
        versions: [
          {
            version: "v1.0.0",
            released_at: "2026-08-17T00:00:00Z",
            commit: "abc123",
            archived: false,
            platforms: [{ id: "windows-amd64.exe", filename: "ws.exe", size: 1, sha256: "x" }],
          },
        ],
      }),
    });
    vi.stubGlobal("fetch", fetchMock);

    const catalog = await loadCatalog("https://backend.test");

    expect(fetchMock.mock.calls[0][0]).toBe(
      "https://backend.test/downloads/workstation/manifest.json"
    );
    expect(catalog?.latest).toBe("v1.0.0");
    expect(catalog?.versions).toHaveLength(1);
  });

  it("returns null — never throws — when the backend is unreachable", async () => {
    vi.stubGlobal("fetch", vi.fn().mockRejectedValue(new Error("ECONNREFUSED")));

    await expect(loadCatalog("https://backend.test")).resolves.toBeNull();
  });

  it("returns null on a non-200, so the page shows the fallback links", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue({ ok: false, status: 502 }));

    await expect(loadCatalog("https://backend.test")).resolves.toBeNull();
  });

  it("returns null when the body is not a manifest object", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue({ ok: true, json: async () => "nope" }));

    await expect(loadCatalog("https://backend.test")).resolves.toBeNull();
  });
});

describe("backendOrigin — production không được rơi về mặc định dev", () => {
  afterEach(() => {
    vi.unstubAllEnvs();
  });

  it("NÉM khi thiếu biến ở production — thà hỏng TO còn hơn trỏ sai thầm lặng", () => {
    // Mặc định là `dxs-product.test`, host chỉ có trên máy lập trình viên. Rơi
    // về nó ở production cho ra một trang render đẹp mà MỌI nút tải đều chết —
    // nhìn từ ngoài không phân biệt được với trang chạy đúng. Env của Amplify
    // sống ở AWS console, không ở repo, nên không có gì trong cây này bảo đảm
    // biến được set.
    vi.stubEnv("TEMPO_BACKEND_URL", "");
    vi.stubEnv("NODE_ENV", "production");
    expect(() => backendOrigin()).toThrow(/TEMPO_BACKEND_URL is not set/);
  });

  it("IM ở dev — mặc định vẫn dùng được để chạy máy", () => {
    vi.stubEnv("TEMPO_BACKEND_URL", "");
    vi.stubEnv("NODE_ENV", "development");
    expect(backendOrigin()).toBe("https://dxs-product.test");
  });
});
