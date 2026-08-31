import { MANIFEST_PATH, normalizeManifest, type Catalog } from "./catalog";

/**
 * Server-side half of the downloads page: where the backend lives, and the one
 * request that reads its release manifest.
 *
 * The manifest is read HERE, on the server, and never from the browser. Two
 * reasons, both load-bearing:
 *
 *   1. No CORS. The manifest is served by Laravel on a different origin than
 *      the Next app; a browser fetch would need a CORS header nobody has any
 *      reason to add to a static file tree.
 *   2. No JS dependency. This is the page a shop opens when its workstation is
 *      already down, sometimes on whatever tablet is to hand. The version list
 *      has to be in the first HTML response, not behind a hydration tick.
 */

/**
 * Same default as `next.config.ts` uses for its dev proxy, so a developer who
 * has configured one has configured both. `TEMPO_BACKEND_URL` is deliberately
 * NOT a `NEXT_PUBLIC_*` name: those are frozen into the bundle at build time
 * and so cannot follow a domain change without a rebuild — the same reasoning
 * `layout.tsx` records for `CUSTOMER_WEB_URL`.
 */
const DEFAULT_BACKEND_ORIGIN = "https://dxs-product.test";

/** Never leave a page hanging on a backend that is not answering. */
const MANIFEST_TIMEOUT_MS = 5_000;

export function backendOrigin(): string {
  const configured = process.env.TEMPO_BACKEND_URL?.trim();

  // Ở PRODUCTION, thiếu biến này KHÔNG được rơi về mặc định dev.
  //
  // Mặc định là `https://dxs-product.test` — một host chỉ tồn tại trên máy lập
  // trình viên. Rơi về nó ở production thì trang vẫn render, vẫn đẹp, vẫn liệt
  // kê đủ 5 nền tảng, và MỌI nút tải đều chết. Nhìn từ ngoài không phân biệt
  // được với trang chạy đúng — nhân viên quán chỉ thấy bấm không ra file.
  //
  // Env của Amplify sống ở AWS console, không ở repo: `admin-web-deploy.yml`
  // chỉ ĐỌC `app.environmentVariables`, không đặt. Nên không có gì trong cây
  // này bảo đảm biến được set, và cách duy nhất để chuyện đó không im lặng là
  // ném ở đây. Cùng lý lẽ với `WORKSTATION_DOWNLOADS_PAGE_URL` phía Laravel:
  // thà hỏng TO còn hơn trỏ sai một cách thầm lặng.
  if (!configured && process.env.NODE_ENV === "production") {
    throw new Error(
      "TEMPO_BACKEND_URL is not set. The downloads page cannot build file links "
        + "without the backend origin, and falling back to the dev default would "
        + "produce a page whose every download link is broken. Set it in the "
        + "Amplify environment variables.",
    );
  }

  return (configured || DEFAULT_BACKEND_ORIGIN).replace(/\/+$/, "");
}

/**
 * Read the release manifest. Returns null for every failure mode — unreachable
 * host, non-200, malformed JSON — because the page treats them identically:
 * say so plainly and hand over the direct links. A thrown error here would
 * render the Next error page, which is exactly the blank wall a shop must not
 * hit while its till is down.
 */
export async function loadCatalog(origin: string): Promise<Catalog | null> {
  try {
    // `apiFetch` is the rule for the Tempo API, and correctly so: it stamps
    // auth + Accept-Language and redirects on 401. None of that applies here.
    // This is a server-side read of a PUBLIC STATIC FILE on another origin, in
    // a request with no user and no token — and apiFetch builds same-origin
    // relative URLs off `document.cookie`, which does not exist on the server.
    // eslint-disable-next-line no-restricted-globals
    const response = await fetch(`${origin}${MANIFEST_PATH}`, {
      cache: "no-store",
      signal: AbortSignal.timeout(MANIFEST_TIMEOUT_MS),
      headers: { Accept: "application/json" },
    });

    if (!response.ok) return null;

    return normalizeManifest(await response.json());
  } catch {
    return null;
  }
}
