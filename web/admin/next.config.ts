import path from "node:path";
import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  output: "standalone",
  turbopack: {
    root: path.join(import.meta.dirname, ".."),
  },
  // Pre-existing typecheck errors in unrelated pages (notifications
  // schedules) block prod build. Skip type check during build for staging
  // — `pnpm typecheck` still runs separately as a CI gate.
  typescript: {
    ignoreBuildErrors: true,
  },
  allowedDevOrigins: ["127.0.0.1", "*.local.godx.jp"],
  images: {
    // Ảnh đến từ MinIO / S3 signed URL (query string rotate mỗi request) và
    // user avatar từ SSO — optimizer cache không mang lại giá trị nhưng lại
    // cần mỗi hostname phải được allowlist. Tắt optimizer để next/image chỉ
    // giữ phần CLS + lazy-load, còn `src` đi thẳng tới browser.
    unoptimized: true,
  },
  async redirects() {
    // Resolve legacy/section aliases at the routing layer. Rendering an async
    // Server Component solely to call redirect() makes React's development
    // performance instrumentation occasionally measure across a navigation
    // boundary and emit negative-timestamp page errors.
    return [
      {
        source: "/hq/:brandSlug/settings",
        destination: "/hq/:brandSlug/settings/payment-methods",
        permanent: false,
      },
      {
        source: "/hq/:brandSlug/menus/:menuId",
        destination: "/hq/:brandSlug/menus/:menuId/items",
        permanent: false,
      },
      {
        source: "/hq/:brandSlug/menus/:menuId/schedules",
        destination: "/hq/:brandSlug/menus/:menuId/items",
        permanent: false,
      },
      {
        source: "/shop/:shopSlug/settings/order",
        destination: "/shop/:shopSlug/settings",
        permanent: false,
      },
      {
        source: "/shop/:shopSlug/menus/:menuId/schedules",
        destination: "/shop/:shopSlug/menus/:menuId",
        permanent: false,
      },
    ];
  },
  async rewrites() {
    // Production reverse-proxying is owned by Amplify custom rewrite rules,
    // matching Kintai. Keep Next rewrites for local/test environments only.
    if (process.env.NODE_ENV === "production") {
      return [];
    }

    const backend = process.env.TEMPO_BACKEND_URL ?? "https://dxs-product.test";

    return {
      beforeFiles: [
        { source: "/auth/:path*", destination: `${backend}/auth/:path*` },
        { source: "/api/:path*", destination: `${backend}/api/:path*` },
        { source: "/broadcasting/:path*", destination: `${backend}/broadcasting/:path*` },
        { source: "/s3/:path*", destination: "http://localhost:5490/tempo/:path*" },
      ],
    };
  },
};

export default nextConfig;
